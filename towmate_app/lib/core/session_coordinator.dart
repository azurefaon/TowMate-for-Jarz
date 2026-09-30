import 'package:flutter/material.dart';
import 'app_prefs.dart';
import '../services/api_service.dart';
import '../services/tl_presence_controller.dart';

class SessionCoordinator {
  SessionCoordinator._();

  static final GlobalKey<NavigatorState> navigatorKey =
      GlobalKey<NavigatorState>();

  static Future<void>? _invalidation;

  /// [token] is the token the failing request was sent with. A late 401 for a
  /// token that is no longer the active session token is ignored, so it cannot
  /// tear down a newer login.
  static Future<void> handleUnauthenticated({String? token}) async {
    if (token != null && token != await ApiService.getToken()) return;
    await (_invalidation ??= _invalidate());
  }

  static Future<void> _invalidate() async {
    TlPresenceController.stop();
    await ApiService.clearSession();
    AppPrefs.useGuestTheme();
    navigatorKey.currentState?.pushNamedAndRemoveUntil(
      '/login',
      (route) => false,
    );
  }

  static void reset() {
    _invalidation = null;
  }
}
