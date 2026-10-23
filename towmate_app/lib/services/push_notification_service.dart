import 'dart:async';
import 'dart:convert';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../core/session_coordinator.dart';
import 'api_service.dart';

/// Single Android channel for every booking push (also named in the FCM
/// payload and the manifest default).
const pushChannelId = 'towmate_booking_updates';
const pushChannelName = 'Booking updates';
const pushChannelDescription = 'Updates about your active towing request.';

/// Minimal, privacy-safe view of an FCM `data` payload.
class BookingPush {
  const BookingPush({required this.bookingCode, required this.status});

  final String bookingCode;
  final String status;

  /// Returns null for anything that is not a well-formed booking_status push.
  static BookingPush? tryParse(Map<String, dynamic>? data) {
    if (data == null || data['type'] != 'booking_status') return null;
    final code = data['booking_code'];
    final status = data['status'];
    if (code is! String || code.trim().isEmpty || code.length > 64) return null;
    return BookingPush(bookingCode: code.trim(), status: status is String ? status : '');
  }
}

class PushMessage {
  const PushMessage({this.title, this.body, this.data = const {}});

  final String? title;
  final String? body;
  final Map<String, dynamic> data;
}

/// Thin wrapper over firebase_* so the service is testable without Firebase.
abstract class PushGateway {
  Future<void> initialize();
  Future<bool> requestPermission();
  Future<bool> isPermissionGranted();
  Future<String?> getToken();
  Stream<String> get onTokenRefresh;
  Stream<PushMessage> get onMessage;
  Stream<PushMessage> get onMessageOpenedApp;
  Future<PushMessage?> getInitialMessage();
}

/// Posts a real Android system notification (never an in-app banner).
abstract class SystemNotificationPresenter {
  Future<void> initialize(void Function(String? payload) onTap);
  Future<void> show({required String title, required String body, required String payload});
  Future<String?> launchPayload();
}

/// Required top-level FCM background handler. Messages carry a `notification`
/// block, so Android renders them itself; nothing to do here.
@pragma('vm:entry-point')
Future<void> towmateFirebaseMessagingBackgroundHandler(RemoteMessage message) async {}

class FirebasePushGateway implements PushGateway {
  FirebaseMessaging get _fm => FirebaseMessaging.instance;

  static PushMessage _map(RemoteMessage m) => PushMessage(
        title: m.notification?.title,
        body: m.notification?.body,
        data: Map<String, dynamic>.from(m.data),
      );

  @override
  Future<void> initialize() async {
    await Firebase.initializeApp();
    FirebaseMessaging.onBackgroundMessage(towmateFirebaseMessagingBackgroundHandler);
  }

  @override
  Future<bool> requestPermission() async {
    final settings = await _fm.requestPermission();
    return settings.authorizationStatus == AuthorizationStatus.authorized ||
        settings.authorizationStatus == AuthorizationStatus.provisional;
  }

  @override
  Future<bool> isPermissionGranted() async {
    final settings = await _fm.getNotificationSettings();
    return settings.authorizationStatus == AuthorizationStatus.authorized ||
        settings.authorizationStatus == AuthorizationStatus.provisional;
  }

  @override
  Future<String?> getToken() => _fm.getToken();

  @override
  Stream<String> get onTokenRefresh => _fm.onTokenRefresh;

  @override
  Stream<PushMessage> get onMessage => FirebaseMessaging.onMessage.map(_map);

  @override
  Stream<PushMessage> get onMessageOpenedApp => FirebaseMessaging.onMessageOpenedApp.map(_map);

  @override
  Future<PushMessage?> getInitialMessage() async {
    final m = await _fm.getInitialMessage();
    return m == null ? null : _map(m);
  }
}

class LocalNotificationPresenter implements SystemNotificationPresenter {
  final _plugin = FlutterLocalNotificationsPlugin();

  @override
  Future<void> initialize(void Function(String? payload) onTap) async {
    await _plugin.initialize(
      settings: const InitializationSettings(
        android: AndroidInitializationSettings('@mipmap/ic_launcher'),
      ),
      onDidReceiveNotificationResponse: (r) => onTap(r.payload),
    );
    await _plugin
        .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(
          const AndroidNotificationChannel(
            pushChannelId,
            pushChannelName,
            description: pushChannelDescription,
            importance: Importance.high,
          ),
        );
  }

  @override
  Future<void> show({required String title, required String body, required String payload}) {
    return _plugin.show(
      id: payload.hashCode & 0x7fffffff,
      title: title,
      body: body,
      notificationDetails: const NotificationDetails(
        android: AndroidNotificationDetails(
          pushChannelId,
          pushChannelName,
          channelDescription: pushChannelDescription,
          importance: Importance.high,
          priority: Priority.high,
        ),
      ),
      payload: payload,
    );
  }

  @override
  Future<String?> launchPayload() async {
    final details = await _plugin.getNotificationAppLaunchDetails();
    return details?.didNotificationLaunchApp == true ? details!.notificationResponse?.payload : null;
  }
}

/// Orchestrates FCM for the Customer app. Every entry point is failure
/// isolated: push problems never reach login, booking or session logic.
class PushNotificationService {
  PushNotificationService({
    PushGateway? gateway,
    SystemNotificationPresenter? presenter,
    Future<bool> Function()? isCustomerSession,
    Future<bool> Function(String token)? registerToken,
    Future<bool> Function()? removeToken,
    bool Function(String bookingCode)? openBooking,
    DateTime Function()? now,
  })  : _gateway = gateway ?? FirebasePushGateway(),
        _presenter = presenter ?? LocalNotificationPresenter(),
        _isCustomerSession = isCustomerSession ?? _defaultIsCustomerSession,
        _registerToken = registerToken ?? ApiService.registerDeviceToken,
        _removeToken = removeToken ?? ApiService.removeDeviceToken,
        _openBooking = openBooking ?? _defaultOpenBooking,
        _now = now ?? DateTime.now;

  static final PushNotificationService instance = PushNotificationService();

  static const _permissionAskedKey = 'push_permission_requested_v1';
  static const _enabledKey = 'push_notifications_enabled_v1';
  static const _pendingTtl = Duration(minutes: 2);

  final PushGateway _gateway;
  final SystemNotificationPresenter _presenter;
  final Future<bool> Function() _isCustomerSession;
  final Future<bool> Function(String token) _registerToken;
  final Future<bool> Function() _removeToken;
  final bool Function(String bookingCode) _openBooking;
  final DateTime Function() _now;

  bool _initialized = false;
  Future<void>? _initFuture;
  String? _lastRegisteredToken;
  String? _pendingBookingCode;
  DateTime? _pendingAt;
  final _subscriptions = <StreamSubscription<dynamic>>[];

  bool get isAvailable => _initialized;

  static Future<bool> _defaultIsCustomerSession() async {
    if (!await ApiService.isLoggedIn()) return false;
    return await ApiService.getUserRole() != 'Team Leader';
  }

  static bool _defaultOpenBooking(String bookingCode) {
    final navigator = SessionCoordinator.navigatorKey.currentState;
    if (navigator == null) return false;
    navigator.pushNamed('/booking-detail', arguments: bookingCode);
    return true;
  }

  /// The user's Push Notifications choice on this device (on by default).
  Future<bool> isPreferenceEnabled() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_enabledKey) ?? true;
  }

  /// What the Profile switch should show: on only when the preference is on
  /// AND the system currently allows notifications.
  Future<bool> isEffectivelyOn() async {
    try {
      if (!await isPreferenceEnabled()) return false;
      await _initFuture;
      if (!_initialized) return false;
      return await _gateway.isPermissionGranted();
    } catch (_) {
      return false;
    }
  }

  /// Turns booking pushes on or off and returns the state that is now in
  /// effect. Off removes this device's token from the server, so the server
  /// stops sending to it; in-app notifications are unaffected. On asks for
  /// the system permission and stays off if it is refused.
  Future<PushToggleResult> setEnabled(bool enable) async {
    final prefs = await SharedPreferences.getInstance();
    if (!enable) {
      if (!await _removeToken()) return PushToggleResult.serverUnreachable;
      _lastRegisteredToken = null;
      await prefs.setBool(_enabledKey, false);
      return PushToggleResult.off;
    }
    try {
      await _initFuture;
      if (!_initialized) return PushToggleResult.unavailable;
      if (!await _gateway.requestPermission()) {
        await prefs.setBool(_enabledKey, false);
        return PushToggleResult.permissionDenied;
      }
      final token = await _gateway.getToken();
      if (token == null || token.isEmpty || !await _registerForced(token)) {
        return PushToggleResult.serverUnreachable;
      }
      await prefs.setBool(_enabledKey, true);
      return PushToggleResult.on;
    } catch (e) {
      _log('enable failed: ${e.runtimeType}');
      return PushToggleResult.unavailable;
    }
  }

  Future<bool> _registerForced(String token) async {
    if (token == _lastRegisteredToken) return true;
    final ok = await _registerToken(token);
    if (ok) _lastRegisteredToken = token;
    return ok;
  }

  /// Safe to call from main(); never throws.
  Future<void> initialize() => _initFuture ??= _initialize();

  Future<void> _initialize() async {
    try {
      await _gateway.initialize();
      await _presenter.initialize(_onLocalTap);

      _subscriptions
        ..add(_gateway.onMessage.listen(_onForegroundMessage, onError: _ignore))
        ..add(_gateway.onMessageOpenedApp.listen((m) => _onTap(m.data), onError: _ignore))
        ..add(_gateway.onTokenRefresh.listen(_onTokenRefresh, onError: _ignore));
      _initialized = true;

      final initial = await _gateway.getInitialMessage();
      if (initial != null) await _onTap(initial.data, deferNavigation: true);
      final launch = await _presenter.launchPayload();
      if (launch != null) _onLocalTap(launch, deferNavigation: true);
    } catch (e) {
      _log('init skipped: ${e.runtimeType}');
    }
  }

  /// Called when a Customer session is active (Home). Opens any pending
  /// notification target, asks for notification permission at most once, then
  /// registers the device token. Idempotent.
  Future<void> startForCustomer() async {
    try {
      await _initFuture;
      if (!_initialized) return;
      await consumePendingNavigation();
      if (!await isPreferenceEnabled()) return;
      await _requestPermissionOnce();
      final token = await _gateway.getToken();
      if (token != null && token.isNotEmpty) await _register(token);
    } catch (e) {
      _log('token setup skipped: ${e.runtimeType}');
    }
  }

  /// Opens a pending notification target once the Navigator is ready and a
  /// Customer session is confirmed. Drops it if the user is logged out.
  Future<void> consumePendingNavigation() async {
    final code = _pendingBookingCode;
    final at = _pendingAt;
    if (code == null) return;
    _pendingBookingCode = null;
    _pendingAt = null;
    if (at == null || _now().difference(at) > _pendingTtl) return;
    if (!await _isCustomerSession()) return;
    if (!_openBooking(code)) {
      _pendingBookingCode = code;
      _pendingAt = at;
    }
  }

  Future<void> dispose() async {
    for (final s in _subscriptions) {
      await s.cancel();
    }
    _subscriptions.clear();
    _initialized = false;
    _initFuture = null;
  }

  Future<void> _requestPermissionOnce() async {
    final prefs = await SharedPreferences.getInstance();
    if (prefs.getBool(_permissionAskedKey) == true) return;
    await prefs.setBool(_permissionAskedKey, true);
    await _gateway.requestPermission();
  }

  Future<void> _register(String token) async {
    if (token == _lastRegisteredToken) return;
    if (await _registerToken(token)) _lastRegisteredToken = token;
  }

  Future<void> _onTokenRefresh(String token) async {
    try {
      if (await isPreferenceEnabled() && await _isCustomerSession()) await _register(token);
    } catch (_) {}
  }

  Future<void> _onForegroundMessage(PushMessage message) async {
    try {
      if (!await isPreferenceEnabled()) return;
      final push = BookingPush.tryParse(message.data);
      final title = message.title;
      final body = message.body;
      if (push == null || title == null || body == null) return;
      await _presenter.show(title: title, body: body, payload: jsonEncode(message.data));
    } catch (e) {
      _log('foreground display skipped: ${e.runtimeType}');
    }
  }

  void _onLocalTap(String? payload, {bool deferNavigation = false}) {
    if (payload == null) return;
    try {
      final decoded = jsonDecode(payload);
      if (decoded is Map<String, dynamic>) _onTap(decoded, deferNavigation: deferNavigation);
    } catch (_) {}
  }

  /// [deferNavigation]: cold-start taps wait for the auth gate to finish routing
  /// (see consumePendingNavigation) instead of pushing over it.
  Future<void> _onTap(Map<String, dynamic> data, {bool deferNavigation = false}) async {
    final push = BookingPush.tryParse(data);
    if (push == null) return;
    _pendingBookingCode = push.bookingCode;
    _pendingAt = _now();
    if (!deferNavigation) await consumePendingNavigation();
  }

  static void _ignore(Object _) {}

  static void _log(String message) {
    if (kDebugMode) debugPrint('[push] $message');
  }
}

enum PushToggleResult { on, off, permissionDenied, serverUnreachable, unavailable }
