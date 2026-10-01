import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/api_error.dart';
import 'package:towmate_app/core/session_coordinator.dart';
import 'package:towmate_app/services/api_service.dart';
import 'package:towmate_app/services/team_leader_service.dart';

class _LoginPushCounter extends NavigatorObserver {
  int pushes = 0;

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    if (route.settings.name == '/login') pushes++;
  }
}

http.Response _json(Map<String, dynamic> body, int status) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  late _LoginPushCounter observer;

  Future<void> seedSession() async {
    SharedPreferences.setMockInitialValues({});
    await ApiService.clearSession();
    SharedPreferences.setMockInitialValues({
      'auth_token': 'live-token',
      'user_role': 'Customer',
    });
    SessionCoordinator.reset();
  }

  Future<void> pumpHost(WidgetTester tester) async {
    observer = _LoginPushCounter();
    await tester.pumpWidget(MaterialApp(
      navigatorKey: SessionCoordinator.navigatorKey,
      navigatorObservers: [observer],
      initialRoute: '/home',
      routes: {
        '/home': (_) => const Scaffold(body: Text('HOME_PLACEHOLDER')),
        '/login': (_) => const Scaffold(body: Text('LOGIN_PLACEHOLDER')),
      },
    ));
    await tester.pump();
  }

  Future<T> withClient<T>(http.Client client, Future<T> Function() body) =>
      http.runWithClient(body, () => client);

  group('authenticated 401', () {
    testWidgets('an ApiService call outside session bootstrap clears the session and routes to Login once', (tester) async {
      await seedSession();
      await pumpHost(tester);
      final client = MockClient((_) async => _json({'message': 'Unauthenticated.'}, 401));

      await tester.runAsync(() => withClient(client, ApiService.fetchNotifications));
      await tester.pumpAndSettle();

      expect(await ApiService.getToken(), isNull);
      expect(find.text('LOGIN_PLACEHOLDER'), findsOneWidget);
      expect(observer.pushes, 1);
    });

    testWidgets('a TeamLeaderService call receives the same centralized handling', (tester) async {
      await seedSession();
      await pumpHost(tester);
      final client = MockClient((_) async => _json({'message': 'Unauthenticated.'}, 401));

      await tester.runAsync(() => withClient(client, () => TeamLeaderService.acceptTask('TM-1')));
      await tester.pumpAndSettle();

      expect(await ApiService.getToken(), isNull);
      expect(find.text('LOGIN_PLACEHOLDER'), findsOneWidget);
      expect(observer.pushes, 1);
    });

    testWidgets('concurrent authenticated 401 responses produce one invalidation and one navigation', (tester) async {
      await seedSession();
      await pumpHost(tester);
      final client = MockClient((_) async => _json({'message': 'Unauthenticated.'}, 401));

      await tester.runAsync(() => withClient(client, () => Future.wait([
            ApiService.fetchNotifications(),
            ApiService.fetchNotifications(),
            TeamLeaderService.acceptTask('TM-1'),
          ])));
      await tester.pumpAndSettle();

      expect(await ApiService.getToken(), isNull);
      expect(observer.pushes, 1);
    });
  });

  group('failures that must not end the session', () {
    final cases = <String, http.Response>{
      '403': _json({'message': 'Forbidden'}, 403),
      '422': _json({'message': 'Invalid', 'errors': {}}, 422),
      '429': _json({'message': 'Too many'}, 429),
      '500': _json({'message': 'Server error'}, 500),
      '503 html': http.Response('<html>down</html>', 503),
    };

    for (final entry in cases.entries) {
      testWidgets('authenticated ${entry.key} keeps the session', (tester) async {
        await seedSession();
        await pumpHost(tester);
        final client = MockClient((_) async => entry.value);

        await tester.runAsync(() => withClient(client, ApiService.fetchNotifications));
        await tester.runAsync(() => withClient(client, () => TeamLeaderService.acceptTask('TM-1')));
        await tester.pumpAndSettle();

        expect(await ApiService.getToken(), 'live-token');
        expect(find.text('LOGIN_PLACEHOLDER'), findsNothing);
        expect(observer.pushes, 0);
      });
    }

    testWidgets('a timeout keeps the session', (tester) async {
      await seedSession();
      await pumpHost(tester);
      final client = MockClient((_) async => throw TimeoutException('slow'));

      await tester.runAsync(() => withClient(client, ApiService.fetchNotifications));
      await tester.pumpAndSettle();

      expect(await ApiService.getToken(), 'live-token');
      expect(observer.pushes, 0);
    });

    testWidgets('an unreachable server keeps the session', (tester) async {
      await seedSession();
      await pumpHost(tester);
      final client = MockClient((_) async => throw const SocketException('Connection refused'));

      await tester.runAsync(() => withClient(client, ApiService.fetchNotifications));
      await tester.pumpAndSettle();

      expect(await ApiService.getToken(), 'live-token');
      expect(observer.pushes, 0);
    });
  });

  group('public and pre-auth 401', () {
    final unauthorized = MockClient((_) async => _json({'message': 'Unauthenticated.'}, 401));

    testWidgets('password login 401 does not invalidate the session', (tester) async {
      await seedSession();
      await pumpHost(tester);

      final result = await tester.runAsync(
        () => withClient(unauthorized, () => ApiService.login('a@example.com', 'wrong', 'csrf')),
      );
      await tester.pumpAndSettle();

      expect(result!['success'], isFalse);
      expect(await ApiService.getToken(), 'live-token');
      expect(observer.pushes, 0);
    });

    testWidgets('OTP requests with a 401 do not invalidate the session', (tester) async {
      await seedSession();
      await pumpHost(tester);

      await tester.runAsync(() async {
        await withClient(unauthorized, () => ApiService.sendResetOtp('a@example.com'));
        await withClient(unauthorized, () => ApiService.sendRegistrationOtp('a@example.com'));
        await withClient(unauthorized, () => ApiService.verifyResetOtp('a@example.com', '123456'));
      });
      await tester.pumpAndSettle();

      expect(await ApiService.getToken(), 'live-token');
      expect(observer.pushes, 0);
    });

    testWidgets('Google pre-authentication 401 does not invalidate the session', (tester) async {
      await seedSession();
      await pumpHost(tester);

      await tester.runAsync(
        () => withClient(unauthorized, () => ApiService.loginWithGoogle('id-token', 'csrf')),
      );
      await tester.pumpAndSettle();

      expect(await ApiService.getToken(), 'live-token');
      expect(observer.pushes, 0);
    });
  });

  group('session restore', () {
    testWidgets('a revoked token routes to Login and clears the session', (tester) async {
      await seedSession();
      await pumpHost(tester);
      final client = MockClient((_) async => _json({'message': 'Unauthenticated.'}, 401));

      final result = await tester.runAsync(() => withClient(client, ApiService.validateSession));
      await tester.pumpAndSettle();

      expect(result!['invalid_session'], isTrue);
      expect(await ApiService.getToken(), isNull);
      expect(find.text('LOGIN_PLACEHOLDER'), findsOneWidget);
      expect(observer.pushes, 1);
    });

    for (final failure in <String, http.Client>{
      'a timeout': MockClient((_) async => throw TimeoutException('slow')),
      'an unreachable server': MockClient((_) async => throw const SocketException('down')),
      'a 500': MockClient((_) async => _json({'message': 'boom'}, 500)),
      'a 503': MockClient((_) async => http.Response('<html>maintenance</html>', 503)),
    }.entries) {
      testWidgets('${failure.key} during restore keeps the cached session', (tester) async {
        await seedSession();
        await pumpHost(tester);

        final result = await tester.runAsync(() => withClient(failure.value, ApiService.validateSession));
        await tester.pumpAndSettle();

        expect(result!['success'], isFalse);
        expect(result['transport_error'], isTrue);
        expect(result['invalid_session'], isNull);
        expect(await ApiService.getToken(), 'live-token');
        expect(observer.pushes, 0);
      });
    }
  });

  group('classification', () {
    test('separates timeout, unreachable, rate limit, server error and malformed response', () {
      expect(ApiError.classifyException(TimeoutException('slow')), ApiErrorKind.timeout);
      expect(ApiError.classifyException(const SocketException('down')), ApiErrorKind.unreachable);
      expect(ApiError.classifyException(http.ClientException('Failed to fetch')), ApiErrorKind.unreachable);
      expect(ApiError.classifyException(const FormatException('<html>')), ApiErrorKind.invalidResponse);
      expect(ApiError.classifyStatus(429), ApiErrorKind.rateLimited429);
      expect(ApiError.classifyStatus(500), ApiErrorKind.serverError5xx);
      expect(ApiError.classifyStatus(503), ApiErrorKind.serverError5xx);
      expect(ApiError.classifyStatus(403), ApiErrorKind.forbidden403);
      expect(ApiError.classifyStatus(404), ApiErrorKind.notFound404);
      expect(ApiError.classifyStatus(409), ApiErrorKind.conflict409);
      expect(ApiError.classifyStatus(422), ApiErrorKind.validation422);
    });

    test('distinguishes authenticated and login 401', () {
      expect(ApiError.classifyStatus(401), ApiErrorKind.authenticated401);
      expect(ApiError.classifyStatus(401, authenticated: false), ApiErrorKind.login401);
      expect(ApiError.endsSession(ApiErrorKind.authenticated401), isTrue);
      expect(ApiError.endsSession(ApiErrorKind.login401), isFalse);
      expect(ApiError.endsSession(ApiErrorKind.forbidden403), isFalse);
      expect(ApiError.endsSession(ApiErrorKind.rateLimited429), isFalse);
      expect(ApiError.endsSession(ApiErrorKind.serverError5xx), isFalse);
      expect(ApiError.endsSession(ApiErrorKind.timeout), isFalse);
      expect(ApiError.endsSession(ApiErrorKind.unreachable), isFalse);
    });

    test('never classifies a malformed or server failure as a connectivity problem', () {
      expect(ApiError.message(ApiError.classifyException(const FormatException('<html>500</html>'))),
          isNot(contains('connect')));
      expect(ApiError.message(ApiErrorKind.rateLimited429), isNot(contains('too long')));
      expect(ApiError.message(ApiErrorKind.serverError5xx), isNot(contains('too long')));
    });

    test('user-facing defaults match the approved wording and never expose technical details', () {
      expect(ApiError.message(ApiErrorKind.authenticated401), 'Your session has ended. Please sign in again.');
      expect(ApiError.message(ApiErrorKind.login401), 'Incorrect email or password.');
      expect(ApiError.message(ApiErrorKind.forbidden403), "You don't have permission to do this.");
      expect(ApiError.message(ApiErrorKind.validation422), 'Please check the information you entered.');
      expect(ApiError.message(ApiErrorKind.rateLimited429), 'Too many attempts. Please wait a moment and try again.');
      expect(ApiError.message(ApiErrorKind.timeout), 'The request took too long. Please try again.');
      expect(ApiError.message(ApiErrorKind.unreachable), 'Unable to connect. Check your internet connection and try again.');
      expect(ApiError.message(ApiErrorKind.serverError5xx), "We couldn't complete your request right now. Please try again.");
      expect(ApiError.message(ApiErrorKind.invalidResponse), "We couldn't complete your request right now. Please try again.");

      for (final kind in ApiErrorKind.values) {
        final text = ApiError.message(kind);
        expect(RegExp(r'\b(401|403|404|409|422|429|500|Unauthenticated|Exception)\b').hasMatch(text), isFalse);
      }
    });
  });
}
