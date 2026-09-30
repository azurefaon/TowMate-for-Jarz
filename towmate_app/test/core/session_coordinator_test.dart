import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/session_coordinator.dart';
import 'package:towmate_app/services/api_service.dart';
import 'package:towmate_app/services/tl_presence_controller.dart';

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  Future<void> seedSession() async {
    SharedPreferences.setMockInitialValues({});
    await ApiService.clearSession();
    SharedPreferences.setMockInitialValues({
      'auth_token': 'something',
      'user_role': 'Customer',
    });
    SessionCoordinator.reset();
  }

  Future<void> pumpHost(WidgetTester tester) async {
    await tester.pumpWidget(MaterialApp(
      navigatorKey: SessionCoordinator.navigatorKey,
      initialRoute: '/home',
      routes: {
        '/home': (_) => const Scaffold(body: Text('HOME_PLACEHOLDER')),
        '/login': (_) => const Scaffold(body: Text('LOGIN_PLACEHOLDER')),
      },
    ));
    await tester.pump();
  }

  testWidgets('a single invalidation clears the session and navigates to Login', (tester) async {
    await seedSession();
    await pumpHost(tester);
    expect(await ApiService.getToken(), 'something');

    await SessionCoordinator.handleUnauthenticated();
    await tester.pumpAndSettle();

    expect(await ApiService.getToken(), isNull);
    expect(find.text('LOGIN_PLACEHOLDER'), findsOneWidget);
  });

  testWidgets('two near-simultaneous invalidations produce exactly one clear/navigate, no stacked routes', (tester) async {
    await seedSession();
    await pumpHost(tester);

    final first = SessionCoordinator.handleUnauthenticated();
    final second = SessionCoordinator.handleUnauthenticated();
    await Future.wait([first, second]);
    await tester.pumpAndSettle();

    expect(find.text('LOGIN_PLACEHOLDER'), findsOneWidget);
    expect(SessionCoordinator.navigatorKey.currentState!.canPop(), isFalse);
    expect(await ApiService.getToken(), isNull);
  });

  testWidgets('reset() allows a fresh invalidation cycle after a new session begins', (tester) async {
    await seedSession();
    await pumpHost(tester);

    await SessionCoordinator.handleUnauthenticated();
    await tester.pumpAndSettle();

    SessionCoordinator.reset();
    SharedPreferences.setMockInitialValues({
      'auth_token': 'a-new-session-token',
      'user_role': 'Customer',
    });

    await SessionCoordinator.handleUnauthenticated();
    await tester.pumpAndSettle();

    expect(await ApiService.getToken(), isNull);
  });

  test('a password-login 401 does not invoke the global session coordinator', () async {
    await seedSession();

    final client = MockClient((r) async => http.Response(
          jsonEncode({'success': false, 'message': 'Invalid credentials.'}),
          401,
          headers: {'content-type': 'application/json'},
        ));

    await http.runWithClient(
      () => ApiService.login('someone@example.com', 'wrong', 'csrf'),
      () => client,
    );

    expect(await ApiService.getToken(), 'something');
  });

  testWidgets('a late 401 for an old token does not invalidate a newer login', (tester) async {
    await seedSession();
    await pumpHost(tester);
    expect(await ApiService.getToken(), 'something');

    final release = Completer<http.Response>();
    final client = MockClient((r) => release.future);

    final inFlight = http.runWithClient(
      () => ApiService.fetchAndCacheProfile(),
      () => client,
    );
    await tester.pump();

    await ApiService.saveSession(
      token: 'token-B',
      role: 'Customer',
      name: 'Jane',
      userId: 7,
    );

    release.complete(http.Response('{}', 401));
    await inFlight;
    await tester.pumpAndSettle();

    expect(await ApiService.getToken(), 'token-B');
    expect(find.text('HOME_PLACEHOLDER'), findsOneWidget);
    expect(find.text('LOGIN_PLACEHOLDER'), findsNothing);
  });

  testWidgets('a 401 for the active token clears the session and shows Login', (tester) async {
    await seedSession();
    await pumpHost(tester);

    final client = MockClient((r) async => http.Response('{}', 401));
    await http.runWithClient(
      () => ApiService.fetchAndCacheProfile(),
      () => client,
    );
    await tester.pumpAndSettle();

    expect(await ApiService.getToken(), isNull);
    expect(find.text('LOGIN_PLACEHOLDER'), findsOneWidget);
  });

  testWidgets('duplicate 401s for the active token invalidate only once', (tester) async {
    await seedSession();
    await pumpHost(tester);

    var requests = 0;
    final client = MockClient((r) async {
      requests++;
      return http.Response('{}', 401);
    });
    await http.runWithClient(
      () => Future.wait([
        ApiService.fetchAndCacheProfile(),
        ApiService.fetchAndCacheProfile(),
        ApiService.validateSession(),
      ]),
      () => client,
    );
    await tester.pumpAndSettle();

    expect(requests, 3);
    expect(find.text('LOGIN_PLACEHOLDER'), findsOneWidget);
    expect(SessionCoordinator.navigatorKey.currentState!.canPop(), isFalse);
    expect(await ApiService.getToken(), isNull);
  });

  group('TL presence on forced invalidation', () {
    tearDown(TlPresenceController.stop);

    testWidgets('an active-token 401 stops running TL presence', (tester) async {
      await seedSession();
      await pumpHost(tester);
      await http.runWithClient(() async => TlPresenceController.start(), () => MockClient((r) async => http.Response('{}', 200)));
      expect(TlPresenceController.isActive, isTrue);

      final client = MockClient((r) async => http.Response('{}', 401));
      await http.runWithClient(
        () => ApiService.fetchAndCacheProfile(),
        () => client,
      );
      await tester.pumpAndSettle();

      expect(TlPresenceController.isActive, isFalse);
      expect(find.text('LOGIN_PLACEHOLDER'), findsOneWidget);
    });

    testWidgets('a Customer login after forced logout does not inherit TL presence', (tester) async {
      await seedSession();
      await pumpHost(tester);
      await http.runWithClient(() async => TlPresenceController.start(), () => MockClient((r) async => http.Response('{}', 200)));

      await SessionCoordinator.handleUnauthenticated();
      await tester.pumpAndSettle();
      await ApiService.saveSession(
        token: 'customer-token',
        role: 'Customer',
        name: 'Jane',
        userId: 8,
      );

      expect(TlPresenceController.isActive, isFalse);
      // No timer is left running: advancing past the ping interval must not
      // issue any request.
      var pings = 0;
      await http.runWithClient(() async {
        await tester.pump(const Duration(seconds: 100));
      }, () => MockClient((r) async {
            pings++;
            return http.Response('{}', 200);
          }));
      expect(pings, 0);
    });

    testWidgets('invalidating while presence is already stopped is harmless', (tester) async {
      await seedSession();
      await pumpHost(tester);
      expect(TlPresenceController.isActive, isFalse);

      await SessionCoordinator.handleUnauthenticated();
      await tester.pumpAndSettle();

      expect(TlPresenceController.isActive, isFalse);
      expect(find.text('LOGIN_PLACEHOLDER'), findsOneWidget);
    });
  });

  for (final code in [403, 422]) {
    test('fetchAndCacheProfile ignores HTTP $code without invoking the session coordinator', () async {
      await seedSession();

      final client = MockClient((r) async => http.Response('{}', code));

      await http.runWithClient(
        () => ApiService.fetchAndCacheProfile(),
        () => client,
      );

      expect(await ApiService.getToken(), 'something');
    });
  }
}
