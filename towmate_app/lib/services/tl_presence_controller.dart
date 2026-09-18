import 'dart:async';
import 'package:flutter/widgets.dart';
import 'team_leader_service.dart';

class TlPresenceController extends WidgetsBindingObserver {
  TlPresenceController._();

  static final TlPresenceController _instance = TlPresenceController._();

  static Timer? _timer;
  static bool _active = false;

  static bool get isActive => _active;

  static void start() {
    if (_active) return;
    _active = true;
    WidgetsBinding.instance.addObserver(_instance);
    TeamLeaderService.pingPresence();
    _startTimer();
  }

  static void stop() {
    if (!_active) return;
    _active = false;
    WidgetsBinding.instance.removeObserver(_instance);
    _timer?.cancel();
    _timer = null;
  }

  static void _startTimer() {
    _timer?.cancel();
    _timer = Timer.periodic(
      const Duration(seconds: 45),
      (_) => TeamLeaderService.pingPresence(),
    );
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!_active) return;
    if (state == AppLifecycleState.resumed) {
      TeamLeaderService.pingPresence();
      if (_timer == null || !_timer!.isActive) {
        _startTimer();
      }
    } else if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.detached) {
      TeamLeaderService.markAway();
    }
  }
}
