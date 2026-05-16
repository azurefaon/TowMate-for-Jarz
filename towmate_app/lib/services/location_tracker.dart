import 'dart:async';
import 'package:geolocator/geolocator.dart';
import 'team_leader_service.dart';

class LocationTracker {
  Timer? _timer;
  bool _running = false;

  bool get isRunning => _running;

  Future<void> start() async {
    if (_running) return;

    final permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      await Geolocator.requestPermission();
    }

    _running = true;
    _sendLocation(); // send immediately on start
    _timer = Timer.periodic(const Duration(seconds: 15), (_) => _sendLocation());
  }

  void stop() {
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
      await TeamLeaderService.updateLocation(pos.latitude, pos.longitude);
    } catch (_) {}
  }
}
