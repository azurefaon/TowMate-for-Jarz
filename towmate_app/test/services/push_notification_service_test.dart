import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/services/api_service.dart';
import 'package:towmate_app/services/push_notification_service.dart';

class FakeGateway implements PushGateway {
  FakeGateway({this.failInit = false, this.permissionGranted = true, this.token = 'fcm-token-1234567890abcd'});

  final bool failInit;
  final bool permissionGranted;
  String? token;
  int permissionRequests = 0;
  PushMessage? initialMessage;

  final message = StreamController<PushMessage>.broadcast();
  final opened = StreamController<PushMessage>.broadcast();
  final refresh = StreamController<String>.broadcast();

  @override
  Future<void> initialize() async {
    if (failInit) throw StateError('Firebase not configured');
  }

  @override
  Future<bool> requestPermission() async {
    permissionRequests++;
    return permissionGranted;
  }

  @override
  Future<String?> getToken() async => token;

  @override
  Stream<String> get onTokenRefresh => refresh.stream;

  @override
  Stream<PushMessage> get onMessage => message.stream;

  @override
  Stream<PushMessage> get onMessageOpenedApp => opened.stream;

  @override
  Future<PushMessage?> getInitialMessage() async => initialMessage;
}

class FakePresenter implements SystemNotificationPresenter {
  final shown = <({String title, String body, String payload})>[];
  void Function(String? payload)? onTap;
  String? launch;

  @override
  Future<void> initialize(void Function(String? payload) onTap) async => this.onTap = onTap;

  @override
  Future<void> show({required String title, required String body, required String payload}) async {
    shown.add((title: title, body: body, payload: payload));
  }

  @override
  Future<String?> launchPayload() async => launch;
}

const _validData = {
  'type': 'booking_status',
  'booking_id': '7',
  'booking_code': 'TM-0007',
  'status': 'on_the_way',
};

Future<void> _flush() async {
  for (var i = 0; i < 5; i++) {
    await Future<void>.delayed(Duration.zero);
  }
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  late FakeGateway gateway;
  late FakePresenter presenter;
  late List<String> opened;
  late List<String> registered;
  var customerSession = true;
  var navigatorReady = true;
  var registerResult = true;

  PushNotificationService build({FakeGateway? g, DateTime Function()? now}) {
    gateway = g ?? gateway;
    return PushNotificationService(
      gateway: gateway,
      presenter: presenter,
      isCustomerSession: () async => customerSession,
      registerToken: (t) async {
        registered.add(t);
        return registerResult;
      },
      openBooking: (code) {
        if (!navigatorReady) return false;
        opened.add(code);
        return true;
      },
      now: now,
    );
  }

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    gateway = FakeGateway();
    presenter = FakePresenter();
    opened = [];
    registered = [];
    customerSession = true;
    navigatorReady = true;
    registerResult = true;
  });

  group('BookingPush.tryParse', () {
    test('accepts a well-formed booking_status payload', () {
      final p = BookingPush.tryParse(_validData)!;
      expect(p.bookingCode, 'TM-0007');
      expect(p.status, 'on_the_way');
    });

    test('rejects malformed payloads safely', () {
      expect(BookingPush.tryParse(null), isNull);
      expect(BookingPush.tryParse({}), isNull);
      expect(BookingPush.tryParse({'type': 'other', 'booking_code': 'TM-1'}), isNull);
      expect(BookingPush.tryParse({'type': 'booking_status'}), isNull);
      expect(BookingPush.tryParse({'type': 'booking_status', 'booking_code': 42}), isNull);
      expect(BookingPush.tryParse({'type': 'booking_status', 'booking_code': '  '}), isNull);
    });
  });

  group('foreground FCM', () {
    test('23: shows a system notification through the presenter', () async {
      final service = build();
      await service.initialize();

      gateway.message.add(const PushMessage(title: 'Tow truck on the way', body: 'Your tow truck is on the way.', data: _validData));
      await _flush();

      expect(presenter.shown, hasLength(1));
      expect(presenter.shown.single.title, 'Tow truck on the way');
      expect(jsonDecode(presenter.shown.single.payload)['booking_code'], 'TM-0007');
      await service.dispose();
    });

    testWidgets('24: renders no custom in-app banner, snackbar or overlay', (tester) async {
      final service = build();
      await tester.runAsync(() async {
        await service.initialize();
      });
      await tester.pumpWidget(const MaterialApp(home: Scaffold(body: Text('Home'))));

      await tester.runAsync(() async {
        gateway.message.add(const PushMessage(title: 'Tow truck arrived', body: 'Arrived.', data: _validData));
        await _flush();
      });
      await tester.pump(const Duration(milliseconds: 300));

      expect(presenter.shown, hasLength(1));
      expect(find.byType(MaterialBanner), findsNothing);
      expect(find.byType(SnackBar), findsNothing);
      expect(find.text('Tow truck arrived'), findsNothing);
      await tester.runAsync(service.dispose);
    });

    test('26: malformed/unknown foreground message is ignored without throwing', () async {
      final service = build();
      await service.initialize();

      gateway.message.add(const PushMessage(title: 'x', body: 'y', data: {'type': 'mystery'}));
      gateway.message.add(const PushMessage(data: _validData)); // no title/body
      await _flush();

      expect(presenter.shown, isEmpty);
      await service.dispose();
    });
  });

  group('notification tap', () {
    test('25: onMessageOpenedApp opens the existing booking flow', () async {
      final service = build();
      await service.initialize();

      gateway.opened.add(const PushMessage(data: _validData));
      await _flush();

      expect(opened, ['TM-0007']);
      await service.dispose();
    });

    test('25: tapping the foreground local notification opens the booking', () async {
      final service = build();
      await service.initialize();

      presenter.onTap!(jsonEncode(_validData));
      await _flush();

      expect(opened, ['TM-0007']);
      await service.dispose();
    });

    test('26: malformed tap payloads fail safely', () async {
      final service = build();
      await service.initialize();

      presenter.onTap!('not json');
      presenter.onTap!(null);
      presenter.onTap!(jsonEncode({'type': 'booking_status'}));
      gateway.opened.add(const PushMessage(data: {'type': 'booking_status', 'booking_code': ''}));
      await _flush();

      expect(opened, isEmpty);
      await service.dispose();
    });

    test('27: logged-out tap never opens a booking (auth is not bypassed)', () async {
      customerSession = false;
      final service = build();
      await service.initialize();

      gateway.opened.add(const PushMessage(data: _validData));
      await _flush();
      customerSession = true;
      await service.consumePendingNavigation();

      expect(opened, isEmpty);
      await service.dispose();
    });

    test('cold-start tap is deferred until Home consumes it', () async {
      gateway.initialMessage = const PushMessage(data: _validData);
      final service = build();
      await service.initialize();
      expect(opened, isEmpty);

      await service.startForCustomer();

      expect(opened, ['TM-0007']);
      await service.dispose();
    });

    test('cold-start tap for a logged-out user is dropped, not replayed after login', () async {
      customerSession = false;
      gateway.initialMessage = const PushMessage(data: _validData);
      final service = build();
      await service.initialize();

      await service.consumePendingNavigation();
      customerSession = true;
      await service.startForCustomer();

      expect(opened, isEmpty);
      await service.dispose();
    });

    test('stale pending tap expires', () async {
      var clock = DateTime(2026, 1, 1, 12);
      gateway.initialMessage = const PushMessage(data: _validData);
      final service = build(now: () => clock);
      await service.initialize();

      clock = clock.add(const Duration(minutes: 5));
      await service.startForCustomer();

      expect(opened, isEmpty);
      await service.dispose();
    });

    test('pending tap survives until the navigator is ready', () async {
      navigatorReady = false;
      final service = build();
      await service.initialize();

      gateway.opened.add(const PushMessage(data: _validData));
      await _flush();
      expect(opened, isEmpty);

      navigatorReady = true;
      await service.consumePendingNavigation();
      expect(opened, ['TM-0007']);
      await service.dispose();
    });
  });

  group('permission and token lifecycle', () {
    test('28: denied permission leaves the app functional and is asked only once', () async {
      gateway = FakeGateway(permissionGranted: false);
      final service = build(g: gateway);
      await service.initialize();

      await service.startForCustomer();
      await service.startForCustomer();

      expect(gateway.permissionRequests, 1);
      expect(registered, ['fcm-token-1234567890abcd']);
      await service.dispose();
    });

    test('29: Firebase initialization failure leaves the app functional', () async {
      final service = build(g: FakeGateway(failInit: true));

      await service.initialize();
      await service.startForCustomer();
      await service.consumePendingNavigation();

      expect(service.isAvailable, isFalse);
      expect(registered, isEmpty);
    });

    test('registers the token once and re-registers on refresh', () async {
      final service = build();
      await service.initialize();

      await service.startForCustomer();
      await service.startForCustomer();
      gateway.refresh.add('fcm-token-refreshed-0000000');
      await _flush();

      expect(registered, ['fcm-token-1234567890abcd', 'fcm-token-refreshed-0000000']);
      await service.dispose();
    });

    test('token refresh is ignored when no customer session exists', () async {
      customerSession = false;
      final service = build();
      await service.initialize();

      gateway.refresh.add('fcm-token-refreshed-0000000');
      await _flush();

      expect(registered, isEmpty);
      await service.dispose();
    });

    test('30: a failed registration is retried later and never throws', () async {
      registerResult = false;
      final service = build();
      await service.initialize();

      await service.startForCustomer();
      registerResult = true;
      await service.startForCustomer();

      expect(registered, ['fcm-token-1234567890abcd', 'fcm-token-1234567890abcd']);
      await service.dispose();
    });
  });

  group('30: token registration never invalidates the session', () {
    Future<void> seedSession() async {
      SharedPreferences.setMockInitialValues({'auth_token': 'session-token', 'user_role': 'Customer', 'user_name': 'Faon'});
    }

    test('server error keeps the user logged in', () async {
      await seedSession();
      final ok = await http.runWithClient(
        () => ApiService.registerDeviceToken('fcm-token-1234567890abcd'),
        () => MockClient((_) async => http.Response('{"message":"boom"}', 500)),
      );

      expect(ok, isFalse);
      expect(await ApiService.isLoggedIn(), isTrue);
    });

    test('network exception keeps the user logged in', () async {
      await seedSession();
      final ok = await http.runWithClient(
        () => ApiService.registerDeviceToken('fcm-token-1234567890abcd'),
        () => MockClient((_) async => throw http.ClientException('offline')),
      );

      expect(ok, isFalse);
      expect(await ApiService.isLoggedIn(), isTrue);
    });

    test('successful registration is remembered and unregistered on logout', () async {
      await seedSession();
      final calls = <String>[];
      await http.runWithClient(() async {
        expect(await ApiService.registerDeviceToken('fcm-token-1234567890abcd'), isTrue);
        await ApiService.logout('csrf');
      }, () => MockClient((request) async {
            calls.add('${request.method} ${request.url.path}');
            if (request.method == 'DELETE') {
              expect(jsonDecode(request.body)['token'], 'fcm-token-1234567890abcd');
            }
            return http.Response('{"success":true}', 200);
          }));

      expect(calls, contains('POST /api/v1/device-tokens'));
      expect(calls, contains('DELETE /api/v1/device-tokens'));
      expect(await ApiService.isLoggedIn(), isFalse);
    });
  });
}
