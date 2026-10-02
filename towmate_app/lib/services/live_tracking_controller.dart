import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/widgets.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart' show LatLng;

import '../models/tracking_model.dart';
import 'api_service.dart';

typedef TrackingFetcher = Future<TrackingSnapshot?> Function(String bookingCode);
typedef RouteCalculator = Future<Map<String, dynamic>> Function(
  double fromLat,
  double fromLng,
  double toLat,
  double toLng,
);

/// One polling loop for ONE booking_code. Shared (via [LiveTrackingRegistry])
/// by every widget showing that booking, so Home, Booking Details and the
/// full map never run parallel loops for the same booking.
class LiveTrackingController extends ChangeNotifier {
  LiveTrackingController({
    required this.bookingCode,
    TrackingFetcher? fetcher,
    RouteCalculator? routeCalculator,
    DateTime Function()? clock,
    this.pollInterval = const Duration(seconds: 10),
    this.routeRefreshInterval = const Duration(seconds: 60),
    this.routeMinGap = const Duration(seconds: 30),
    this.routeMoveThresholdMeters = 200,
  })  : _fetcher = fetcher ?? ApiService.fetchBookingTracking,
        _routeCalculator = routeCalculator ?? ApiService.calculateRoute,
        _clock = clock ?? DateTime.now;

  final String bookingCode;
  final Duration pollInterval;
  final Duration routeRefreshInterval;
  final Duration routeMinGap;
  final double routeMoveThresholdMeters;

  final TrackingFetcher _fetcher;
  final RouteCalculator _routeCalculator;
  final DateTime Function() _clock;

  Timer? _timer;
  bool _fetching = false;
  bool _disposed = false;
  bool _ended = false;
  int _failures = 0;

  TrackingSnapshot? _snapshot;
  DateTime? _lastSuccessAt;

  List<LatLng> _routePoints = const [];
  double? _etaMinutes;
  double? _distanceKm;
  bool _distanceIsApproximate = false;
  LatLng? _routeOrigin;
  LatLng? _routeDestination;
  DateTime? _lastRouteAttemptAt;
  bool _routeInFlight = false;
  int _routeRequestCount = 0;
  int _fetchCount = 0;

  /// The backend's latest answer was tracking=false (or 404). Polling stops
  /// on that answer; a later re-check keeps this true until the backend
  /// says tracking again, so the UI never flickers back to "connecting".
  bool get ended => _ended || (_snapshot != null && !_snapshot!.tracking);

  bool get isPolling => _timer != null;

  /// No answer received yet.
  bool get awaitingFirstSnapshot => _snapshot == null;

  @visibleForTesting
  int get routeRequestCount => _routeRequestCount;

  @visibleForTesting
  int get fetchCount => _fetchCount;

  /// The latest backend snapshot. After failed polls it is aged locally by
  /// the time since the last successful poll (never upgraded).
  TrackingSnapshot? get snapshot {
    final s = _snapshot;
    if (s == null || _failures == 0 || _lastSuccessAt == null) return s;
    return s.agedBy(_clock().difference(_lastSuccessAt!).inSeconds);
  }

  bool get _showsLocation => !ended && (snapshot?.hasCurrentLocation ?? false);

  /// Route/ETA/distance exist only while a current location may be shown.
  List<LatLng> get routePoints => _showsLocation ? _routePoints : const [];
  double? get etaMinutes => _showsLocation ? _etaMinutes : null;
  double? get distanceKm => _showsLocation ? _distanceKm : null;
  bool get distanceIsApproximate => _distanceIsApproximate;

  /// Starts (or resumes) polling: one immediate fetch, then every
  /// [pollInterval]. Calling it on an ended controller re-checks once, which
  /// lets a booking move on_the_way → … → on_job without a new controller.
  void start() {
    if (_disposed) return;
    _ended = false;
    if (_timer != null) return;
    _timer = Timer.periodic(pollInterval, (_) => _poll());
    unawaited(_poll());
  }

  /// Stops polling; keeps the last state for display.
  void stop() {
    _timer?.cancel();
    _timer = null;
  }

  Future<void> _poll() async {
    if (_fetching || _disposed) return;
    _fetching = true;
    _fetchCount++;
    TrackingSnapshot? result;
    try {
      result = await _fetcher(bookingCode);
    } catch (_) {
      result = null;
    }
    _fetching = false;
    if (_disposed) return;

    if (result == null) {
      _failures++;
      notifyListeners();
      return;
    }

    _failures = 0;
    _snapshot = result;
    _lastSuccessAt = _clock();

    if (!result.tracking) {
      _ended = true;
      stop();
      _clearRoute();
      notifyListeners();
      return;
    }

    if (!result.hasCurrentLocation || result.destination == null) {
      _clearRoute();
      notifyListeners();
      return;
    }

    notifyListeners();
    _maybeRefreshRoute(result);
  }

  void _clearRoute() {
    _routePoints = const [];
    _etaMinutes = null;
    _distanceKm = null;
    _distanceIsApproximate = false;
    _routeOrigin = null;
    // Forget the destination so a returning live fix re-routes promptly.
    _routeDestination = null;
  }

  void _maybeRefreshRoute(TrackingSnapshot s) {
    if (_routeInFlight) return;
    final loc = s.location!;
    final dest = s.destination!;
    final origin = LatLng(loc.lat, loc.lng);
    final destination = LatLng(dest.lat, dest.lng);
    final now = _clock();
    final sinceLast = _lastRouteAttemptAt == null ? null : now.difference(_lastRouteAttemptAt!);

    final destinationChanged = _routeDestination == null ||
        _routeDestination!.latitude != destination.latitude ||
        _routeDestination!.longitude != destination.longitude;

    bool due;
    if (destinationChanged) {
      // New/changed destination (first route, or pickup → drop-off). Still
      // keep a small floor so flapping freshness can't spam the router.
      due = sinceLast == null || sinceLast >= const Duration(seconds: 15);
    } else if (sinceLast != null && sinceLast < routeMinGap) {
      due = false;
    } else if (sinceLast == null || sinceLast >= routeRefreshInterval) {
      due = true;
    } else {
      final from = _routeOrigin;
      due = from != null && metersBetween(from, origin) >= routeMoveThresholdMeters;
    }

    if (due) {
      unawaited(_requestRoute(origin, destination));
    } else if (destinationChanged && _routePoints.isNotEmpty) {
      // Never keep drawing the previous leg's route/ETA against a new
      // destination while waiting for the re-route.
      _routePoints = const [];
      _etaMinutes = null;
      _distanceKm = null;
      _distanceIsApproximate = false;
      notifyListeners();
    }
  }

  Future<void> _requestRoute(LatLng origin, LatLng destination) async {
    _routeInFlight = true;
    _lastRouteAttemptAt = _clock();
    _routeDestination = destination;
    _routeRequestCount++;

    Map<String, dynamic> result;
    try {
      result = await _routeCalculator(origin.latitude, origin.longitude, destination.latitude, destination.longitude);
    } catch (_) {
      result = const {'success': false};
    }
    _routeInFlight = false;
    if (_disposed || ended || !(snapshot?.hasCurrentLocation ?? false)) return;

    final points = _parsePoints(result['coordinates']);
    final distance = result['distance_km'];
    if (result['success'] == true && points.length >= 2 && distance is num) {
      final duration = result['duration_min'];
      _routePoints = points;
      _distanceKm = distance.toDouble();
      _etaMinutes = duration is num ? duration.toDouble() : null;
      _distanceIsApproximate = false;
    } else {
      // Router unavailable: no drawn route and no ETA; straight-line distance
      // only, clearly flagged as approximate.
      _routePoints = const [];
      _etaMinutes = null;
      _distanceKm = metersBetween(origin, destination) / 1000.0;
      _distanceIsApproximate = true;
    }
    _routeOrigin = origin;
    notifyListeners();
  }

  static List<LatLng> _parsePoints(Object? raw) {
    if (raw is! List) return const [];
    final out = <LatLng>[];
    for (final c in raw) {
      if (c is List && c.length >= 2 && c[0] is num && c[1] is num) {
        out.add(LatLng((c[0] as num).toDouble(), (c[1] as num).toDouble()));
      }
    }
    return out;
  }

  static double metersBetween(LatLng a, LatLng b) {
    const r = 6371000.0;
    final dLat = _rad(b.latitude - a.latitude);
    final dLng = _rad(b.longitude - a.longitude);
    final h = math.sin(dLat / 2) * math.sin(dLat / 2) +
        math.cos(_rad(a.latitude)) * math.cos(_rad(b.latitude)) * math.sin(dLng / 2) * math.sin(dLng / 2);
    return 2 * r * math.asin(math.min(1.0, math.sqrt(h)));
  }

  static double _rad(double d) => d * math.pi / 180.0;

  @override
  void dispose() {
    _disposed = true;
    stop();
    super.dispose();
  }
}

/// A widget's claim on a booking's controller. While at least one ACTIVE
/// handle exists (and the app is in the foreground) the controller polls.
/// An inactive handle (screen covered by another route) keeps the state but
/// does not keep the loop running.
class LiveTrackingHandle {
  LiveTrackingHandle._(this._registry, this.controller);

  final LiveTrackingRegistry _registry;
  final LiveTrackingController controller;
  bool _active = true;
  bool _released = false;

  bool get active => _active && !_released;

  void setActive(bool value) {
    if (_released || _active == value) return;
    _active = value;
    _registry._sync(controller.bookingCode);
  }

  void release() {
    if (_released) return;
    _released = true;
    _registry._release(this);
  }
}

class LiveTrackingRegistry with WidgetsBindingObserver {
  LiveTrackingRegistry._();

  static final LiveTrackingRegistry instance = LiveTrackingRegistry._();

  /// Test seam: how controllers are built.
  @visibleForTesting
  static LiveTrackingController Function(String bookingCode) controllerFactory =
      (code) => LiveTrackingController(bookingCode: code);

  final Map<String, LiveTrackingController> _controllers = {};
  final Map<String, List<LiveTrackingHandle>> _handles = {};
  bool _observing = false;
  bool _foreground = true;

  @visibleForTesting
  LiveTrackingController? controllerFor(String bookingCode) => _controllers[bookingCode];

  @visibleForTesting
  int get controllerCount => _controllers.length;

  LiveTrackingHandle acquire(String bookingCode) {
    final controller = _controllers.putIfAbsent(bookingCode, () => controllerFactory(bookingCode));
    final handle = LiveTrackingHandle._(this, controller);
    (_handles[bookingCode] ??= []).add(handle);
    if (!_observing) {
      WidgetsBinding.instance.addObserver(this);
      _observing = true;
    }
    _sync(bookingCode);
    return handle;
  }

  void _release(LiveTrackingHandle handle) {
    final code = handle.controller.bookingCode;
    final list = _handles[code];
    list?.remove(handle);
    if (list == null || list.isEmpty) {
      _handles.remove(code);
      _controllers.remove(code)?.dispose();
    } else {
      _sync(code);
    }
    if (_handles.isEmpty && _observing) {
      WidgetsBinding.instance.removeObserver(this);
      _observing = false;
    }
  }

  void _sync(String code) {
    final controller = _controllers[code];
    if (controller == null) return;
    final shouldRun = _foreground && (_handles[code]?.any((h) => h.active) ?? false);
    // An ended controller is not polling; a sync (new viewer, screen
    // uncovered, app resumed) re-checks it once, which is how a booking that
    // later becomes trackable again (e.g. on_job) resumes.
    if (shouldRun && !controller.isPolling) {
      controller.start();
    } else if (!shouldRun && controller.isPolling) {
      controller.stop();
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final foreground = switch (state) {
      AppLifecycleState.resumed => true,
      AppLifecycleState.paused || AppLifecycleState.hidden || AppLifecycleState.detached => false,
      // Transient (dialogs, notification shade): keep the current mode.
      AppLifecycleState.inactive => _foreground,
    };
    if (foreground == _foreground) return;
    _foreground = foreground;
    for (final code in _controllers.keys.toList()) {
      _sync(code);
    }
  }

  /// Test seam: back to a clean, foreground state.
  @visibleForTesting
  void resetForTest() {
    for (final c in _controllers.values) {
      c.dispose();
    }
    _controllers.clear();
    _handles.clear();
    if (_observing) {
      WidgetsBinding.instance.removeObserver(this);
      _observing = false;
    }
    _foreground = true;
    controllerFactory = (code) => LiveTrackingController(bookingCode: code);
  }
}
