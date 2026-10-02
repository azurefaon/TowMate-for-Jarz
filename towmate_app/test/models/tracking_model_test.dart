import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/models/tracking_model.dart';

void main() {
  group('TrackingSnapshot.fromJson', () {
    test('parses a live pickup snapshot', () {
      final s = TrackingSnapshot.fromJson({
        'tracking': true,
        'booking_code': 'TM-1',
        'status': 'on_the_way',
        'phase': 'pickup',
        'freshness': 'live',
        'location': {'lat': 14.6, 'lng': 121.0, 'accuracy': 8.5, 'age_seconds': 12},
        'last_seen': null,
        'destination': {'lat': 14.65, 'lng': 121.05},
      });
      expect(s.tracking, isTrue);
      expect(s.bookingCode, 'TM-1');
      expect(s.isPickupPhase, isTrue);
      expect(s.freshness, TrackingFreshness.live);
      expect(s.hasCurrentLocation, isTrue);
      expect(s.location!.lat, 14.6);
      expect(s.location!.accuracy, 8.5);
      expect(s.location!.ageSeconds, 12);
      expect(s.destination, const TrackingPoint(14.65, 121.05));
    });

    test('parses updating drop-off and integer coordinates', () {
      final s = TrackingSnapshot.fromJson({
        'tracking': true,
        'booking_code': 'TM-2',
        'status': 'on_job',
        'phase': 'dropoff',
        'freshness': 'updating',
        'location': {'lat': 14, 'lng': 121, 'accuracy': null, 'age_seconds': 60},
        'destination': {'lat': 14.5, 'lng': 121.1},
      });
      expect(s.isDropoffPhase, isTrue);
      expect(s.freshness, TrackingFreshness.updating);
      expect(s.location!.lat, 14.0);
      expect(s.location!.accuracy, isNull);
    });

    test('unavailable never exposes a location, even if one is (wrongly) sent', () {
      final s = TrackingSnapshot.fromJson({
        'tracking': true,
        'booking_code': 'TM-3',
        'status': 'on_the_way',
        'phase': 'pickup',
        'freshness': 'unavailable',
        'location': {'lat': 1, 'lng': 2, 'age_seconds': 300},
        'last_seen': {'age_seconds': 300},
        'destination': {'lat': 14.65, 'lng': 121.05},
      });
      expect(s.location, isNull);
      expect(s.hasCurrentLocation, isFalse);
      expect(s.lastSeenAgeSeconds, 300);
      expect(s.destination, isNotNull);
    });

    test('tracking=false yields no phase, freshness, location or destination', () {
      final s = TrackingSnapshot.fromJson({
        'tracking': false,
        'booking_code': 'TM-4',
        'status': 'completed',
        'phase': null,
        'freshness': null,
        'location': null,
        'last_seen': null,
        'destination': null,
      });
      expect(s.tracking, isFalse);
      expect(s.phase, isNull);
      expect(s.freshness, isNull);
      expect(s.location, isNull);
      expect(s.destination, isNull);
    });

    test('live with a missing location is treated as unavailable; unknown freshness fails safe', () {
      final noLoc = TrackingSnapshot.fromJson({'tracking': true, 'booking_code': 'X', 'status': 'on_job', 'phase': 'dropoff', 'freshness': 'live'});
      expect(noLoc.freshness, TrackingFreshness.unavailable);
      expect(noLoc.hasCurrentLocation, isFalse);

      final weird = TrackingSnapshot.fromJson({
        'tracking': true,
        'booking_code': 'X',
        'status': 'on_job',
        'phase': 'dropoff',
        'freshness': 'super-live',
        'location': {'lat': 1, 'lng': 2, 'age_seconds': 1},
      });
      expect(weird.freshness, TrackingFreshness.unavailable);
      expect(weird.location, isNull);
    });

    test('a 404 is modelled as an ended snapshot', () {
      const s = TrackingSnapshot.ended('TM-9');
      expect(s.tracking, isFalse);
      expect(s.hasCurrentLocation, isFalse);
    });
  });

  group('TrackingSnapshot.agedBy (failed polls only)', () {
    TrackingSnapshot live(int age) => TrackingSnapshot.fromJson({
          'tracking': true,
          'booking_code': 'TM-1',
          'status': 'on_the_way',
          'phase': 'pickup',
          'freshness': age <= 30 ? 'live' : 'updating',
          'location': {'lat': 14.6, 'lng': 121.0, 'age_seconds': age},
          'destination': {'lat': 14.65, 'lng': 121.05},
        });

    test('ages live → updating → unavailable and drops coordinates past 90 s', () {
      expect(live(10).agedBy(15).freshness, TrackingFreshness.live);
      expect(live(10).agedBy(25).freshness, TrackingFreshness.updating);
      final gone = live(10).agedBy(81);
      expect(gone.freshness, TrackingFreshness.unavailable);
      expect(gone.location, isNull);
      expect(gone.lastSeenAgeSeconds, 91);
      expect(gone.destination, isNotNull);
    });

    test('never upgrades freshness', () {
      expect(live(45).agedBy(1).freshness, TrackingFreshness.updating);
      expect(live(45).agedBy(0).freshness, TrackingFreshness.updating);
    });
  });

  test('only on_the_way and on_job are trackable', () {
    expect(isTrackableStatus('on_the_way'), isTrue);
    expect(isTrackableStatus('on_job'), isTrue);
    for (final s in ['requested', 'assigned', 'arrived_pickup', 'in_progress', 'waiting_verification', 'completed', null]) {
      expect(isTrackableStatus(s), isFalse, reason: '$s');
    }
  });
}
