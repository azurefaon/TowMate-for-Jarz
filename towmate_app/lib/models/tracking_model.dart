/// Customer Live Tracking v1 — client model for
/// `GET /api/v1/bookings/{code}/tracking`.
///
/// The backend is the source of truth for freshness. The client never
/// upgrades freshness and never shows coordinates the backend withheld.
library;

/// Only these booking statuses are ever trackable.
const Set<String> kTrackableStatuses = {'on_the_way', 'on_job'};

bool isTrackableStatus(String? status) => status != null && kTrackableStatuses.contains(status);

/// Freshness thresholds (seconds). Mirrors the backend contract; only used
/// to age a snapshot locally when a poll FAILS, never to upgrade one.
const int kTrackingLiveMaxAge = 30;
const int kTrackingUnavailableAfter = 90;

enum TrackingFreshness { live, updating, unavailable }

TrackingFreshness? _parseFreshness(Object? raw) => switch (raw) {
      'live' => TrackingFreshness.live,
      'updating' => TrackingFreshness.updating,
      'unavailable' => TrackingFreshness.unavailable,
      null => null,
      // Unknown value from a newer backend: fail safe.
      _ => TrackingFreshness.unavailable,
    };

double? _num(Object? v) => v is num ? v.toDouble() : (v == null ? null : double.tryParse(v.toString()));
int? _int(Object? v) => v is num ? v.round() : (v == null ? null : int.tryParse(v.toString()));

class TrackingPoint {
  const TrackingPoint(this.lat, this.lng);
  final double lat;
  final double lng;

  static TrackingPoint? fromJson(Object? raw) {
    if (raw is! Map) return null;
    final lat = _num(raw['lat']);
    final lng = _num(raw['lng']);
    if (lat == null || lng == null) return null;
    return TrackingPoint(lat, lng);
  }

  @override
  bool operator ==(Object other) => other is TrackingPoint && other.lat == lat && other.lng == lng;

  @override
  int get hashCode => Object.hash(lat, lng);
}

class TrackingLocation {
  const TrackingLocation({
    required this.lat,
    required this.lng,
    required this.ageSeconds,
    this.accuracy,
  });

  final double lat;
  final double lng;
  final double? accuracy;
  final int ageSeconds;

  static TrackingLocation? fromJson(Object? raw) {
    if (raw is! Map) return null;
    final lat = _num(raw['lat']);
    final lng = _num(raw['lng']);
    final age = _int(raw['age_seconds']);
    if (lat == null || lng == null || age == null) return null;
    return TrackingLocation(lat: lat, lng: lng, accuracy: _num(raw['accuracy']), ageSeconds: age < 0 ? 0 : age);
  }
}

class TrackingSnapshot {
  const TrackingSnapshot({
    required this.tracking,
    required this.bookingCode,
    required this.status,
    this.phase,
    this.freshness,
    this.location,
    this.lastSeenAgeSeconds,
    this.destination,
  });

  /// Synthetic "not trackable" snapshot (e.g. the backend answered 404).
  const TrackingSnapshot.ended(this.bookingCode)
      : tracking = false,
        status = '',
        phase = null,
        freshness = null,
        location = null,
        lastSeenAgeSeconds = null,
        destination = null;

  final bool tracking;
  final String bookingCode;
  final String status;

  /// 'pickup' (on_the_way) or 'dropoff' (on_job); null when not tracking.
  final String? phase;
  final TrackingFreshness? freshness;

  /// Present only for live/updating. Never present when unavailable.
  final TrackingLocation? location;

  /// Age of the withheld last-known fix (unavailable only), if the API sent it.
  final int? lastSeenAgeSeconds;
  final TrackingPoint? destination;

  bool get isPickupPhase => phase == 'pickup';
  bool get isDropoffPhase => phase == 'dropoff';

  /// True only when the backend returned current coordinates we may show.
  bool get hasCurrentLocation =>
      tracking && location != null && freshness != null && freshness != TrackingFreshness.unavailable;

  factory TrackingSnapshot.fromJson(Map<String, dynamic> j) {
    final tracking = j['tracking'] == true;
    final freshness = tracking ? (_parseFreshness(j['freshness']) ?? TrackingFreshness.unavailable) : null;
    final rawPhase = j['phase'];
    final phase = tracking && (rawPhase == 'pickup' || rawPhase == 'dropoff') ? rawPhase as String : null;
    // Defensive: never trust coordinates alongside unavailable / not tracking.
    final location = tracking && freshness != TrackingFreshness.unavailable ? TrackingLocation.fromJson(j['location']) : null;
    final lastSeen = j['last_seen'];
    return TrackingSnapshot(
      tracking: tracking,
      bookingCode: (j['booking_code'] as String?) ?? '',
      status: (j['status'] as String?) ?? '',
      phase: phase,
      // A "live/updating" answer with no usable location is treated as unavailable.
      freshness: tracking && location == null ? TrackingFreshness.unavailable : freshness,
      location: location,
      lastSeenAgeSeconds: tracking && lastSeen is Map ? _int(lastSeen['age_seconds']) : null,
      destination: tracking ? TrackingPoint.fromJson(j['destination']) : null,
    );
  }

  /// Ages this snapshot by [elapsedSeconds] (used only after failed polls so
  /// a dead connection never keeps showing a "live" truck). Never upgrades.
  TrackingSnapshot agedBy(int elapsedSeconds) {
    if (elapsedSeconds <= 0 || !tracking) return this;
    final loc = location;
    if (loc == null) {
      final last = lastSeenAgeSeconds;
      return last == null ? this : _copy(lastSeenAgeSeconds: last + elapsedSeconds);
    }
    final age = loc.ageSeconds + elapsedSeconds;
    if (age > kTrackingUnavailableAfter) {
      return TrackingSnapshot(
        tracking: true,
        bookingCode: bookingCode,
        status: status,
        phase: phase,
        freshness: TrackingFreshness.unavailable,
        location: null,
        lastSeenAgeSeconds: age,
        destination: destination,
      );
    }
    return _copy(
      freshness: age > kTrackingLiveMaxAge ? TrackingFreshness.updating : freshness,
      location: TrackingLocation(lat: loc.lat, lng: loc.lng, accuracy: loc.accuracy, ageSeconds: age),
    );
  }

  TrackingSnapshot _copy({TrackingFreshness? freshness, TrackingLocation? location, int? lastSeenAgeSeconds}) {
    return TrackingSnapshot(
      tracking: tracking,
      bookingCode: bookingCode,
      status: status,
      phase: phase,
      freshness: freshness ?? this.freshness,
      location: location ?? this.location,
      lastSeenAgeSeconds: lastSeenAgeSeconds ?? this.lastSeenAgeSeconds,
      destination: destination,
    );
  }
}
