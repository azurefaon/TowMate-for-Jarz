import 'dart:async';
import 'package:google_navigation_flutter/google_navigation_flutter.dart';

/// Native Google Navigation SDK calls used to tear a session down.
/// Swapped for a fake in tests.
abstract class TlNavigationDriver {
  Future<bool> isInitialized();
  Future<bool> areTermsAccepted();
  Future<void> initialize();
  Future<void> stop();
}

class GoogleTlNavigationDriver implements TlNavigationDriver {
  const GoogleTlNavigationDriver();

  @override
  Future<bool> isInitialized() => GoogleMapsNavigator.isInitialized();

  @override
  Future<bool> areTermsAccepted() => GoogleMapsNavigator.areTermsAccepted();

  @override
  Future<void> initialize() => TlNavigationSession.initializeNative();

  @override
  Future<void> stop() async {
    try {
      await GoogleMapsNavigator.stopGuidance();
    } catch (_) {}
    await GoogleMapsNavigator.cleanup();
  }
}

/// Turn-by-turn navigation (route + voice guidance) for the Team Leader.
///
/// This is NOT operational location reporting: LocationTracker keeps sending
/// GPS to dispatch independently of anything here.
///
/// The SDK session is process-wide, so exactly one Navigate screen owns it at a
/// time, and every native start/stop runs through one serial queue so a stop can
/// never be overtaken by an earlier start that resolves late.
class TlNavigationSession {
  TlNavigationSession._();

  static TlNavigationDriver driver = const GoogleTlNavigationDriver();

  static Object? _owner;
  static Future<void> _tail = Future.value();

  /// Statuses where the TL is driving to a destination. Everything else —
  /// arrived, loading, payment (Step 5), done — has no navigation.
  static bool isNavigationPhase(String status) =>
      status == 'accepted' || status == 'on_the_way' || status == 'on_job';

  /// 'pickup' / 'dropoff' while driving, null otherwise.
  static String? legFor(String status) {
    if (status == 'accepted' || status == 'on_the_way') return 'pickup';
    if (status == 'on_job') return 'dropoff';
    return null;
  }

  static bool get hasOwner => _owner != null;

  static bool isOwner(Object owner) => identical(_owner, owner);

  static void claim(Object owner) => _owner = owner;

  /// Swiping the app away ends navigation instead of leaving voice guidance
  /// running in a foreground service with no TowMate UI left to stop it.
  static Future<void> initializeNative() =>
      GoogleMapsNavigator.initializeNavigationSession(
        taskRemovedBehavior: TaskRemovedBehavior.quitService,
      );

  static Future<T> _serial<T>(Future<T> Function() op) {
    final result = _tail.then((_) => op());
    _tail = result.then<void>((_) {}, onError: (_) {});
    return result;
  }

  /// Runs [op] only while [owner] still owns the session. Returns false when
  /// skipped because the owner was released (screen closed) in the meantime.
  static Future<bool> runFor(Object owner, Future<void> Function() op) {
    return _serial(() async {
      if (!isOwner(owner)) return false;
      await op();
      return true;
    });
  }

  /// Stops guidance and tears the session down. Idempotent: a second call, or
  /// a call from a screen that no longer owns the session, does nothing.
  static Future<void> release(Object owner) {
    if (!isOwner(owner)) return Future.value();
    _owner = null;
    return _serial(_stopNow);
  }

  /// Used when no Navigate screen is open (Task tab, Payment, app resume):
  /// ends any guidance still running, including a session the Android
  /// foreground service kept alive across an activity restart, which
  /// isInitialized() cannot see until the session is re-attached.
  static Future<void> ensureStopped() {
    return _serial(() async {
      if (_owner != null) return;
      await _stopNow();
    });
  }

  static Future<void> _stopNow() async {
    try {
      if (!await driver.isInitialized()) {
        // No terms accepted means navigation has never run on this device.
        if (!await driver.areTermsAccepted()) return;
        await driver.initialize();
      }
      await driver.stop();
    } catch (_) {}
  }

  /// Test-only reset of the process-wide state.
  static void resetForTest() {
    _owner = null;
    _tail = Future.value();
    driver = const GoogleTlNavigationDriver();
  }
}
