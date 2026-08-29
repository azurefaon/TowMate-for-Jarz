import 'dart:async';
import 'package:geolocator/geolocator.dart';
import 'team_leader_service.dart';

// Singleton — shared between the idle home screen (tracks while online-but-
// waiting-for-a-job) and the active-task shell (tracks during driving phases),
// so their independent start()/stop() calls coordinate on one timer instead
// of each screen running its own and double-polling the location endpoint.
class LocationTracker {
  static final LocationTracker _instance = LocationTracker._internal();
  factory LocationTracker() => _instance;
  LocationTracker._internal();

  Timer? _timer;
  bool _running = false;
  int _refCount = 0;

  bool get isRunning => _running;

  // Reference-counted: each caller's start()/stop() pair only turns the timer
  // off once every caller that asked for it has also called stop(), so one
  // screen's dispose() can't kill tracking another screen still needs.
  Future<void> start() async {
    _refCount++;
    if (_running) return;

    final permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      await Geolocator.requestPermission();
    }

    _running = true;
    _sendLocation(); // send immediately on start
    _timer = Timer.periodic(const Duration(seconds: 10), (_) => _sendLocation());
  }

  void stop() {
    if (_refCount > 0) _refCount--;
    if (_refCount > 0) return;

    _timer?.cancel();
    _timer = null;
    _running = false;
  }

  Future<void> _sendLocation() async {
    try {
      final pos = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 10),
        ),
      );
      await TeamLeaderService.updateLocation(
        pos.latitude,
        pos.longitude,
        accuracy: pos.accuracy,
      );
    } catch (_) {}
  }
}
