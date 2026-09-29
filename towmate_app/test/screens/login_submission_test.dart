import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/security_utils.dart';
import 'package:towmate_app/screens/customer/login_screen.dart';
import 'package:towmate_app/services/api_service.dart';
import 'package:towmate_app/services/tl_presence_controller.dart';

const _tlEmail = 'tl.assigned.demo@example.com';

http.Response _json(Object body, [int code = 200]) =>
    http.Response(jsonEncode(body), code, headers: {'content-type': 'application/json'});

http.Response _tlSuccess() => _json({
      'success': true,
      'requires_terms_acceptance': false,
      'data': {
        'token': '1|tok',
        'user': {
          'id': 128,
          'name': 'Demo Team Leader',
          'email': _tlEmail,
          'phone': null,
          'role': 'Team Leader',
          'duty_class': null,
          'must_change_password': false,
        },
      },
    });

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (c) async => null,
  );

  group('ApiService.login failure classification', () {
    Future<Map<String, dynamic>> run(http.Client c) => http.runWithClient(
          () => ApiService.login(_tlEmail, 'TlDemo@2026', 'csrf'),
          () => c,
        );

    setUp(() => SharedPreferences.setMockInitialValues({}));

    test('a real 401 is an invalid-credentials failure, not a transport error', () async {
      final r = await run(MockClient((_) async => _json({'success': false, 'message': 'Invalid credentials.'}, 401)));
      expect(r['success'], isFalse);
      expect(r['message'], 'Invalid credentials.');
      expect(r.containsKey('transport_error'), isFalse);
    });

    test('a timeout is a transport error and says timed out', () async {
      final r = await run(MockClient((_) async => throw TimeoutException('slow')));
      expect(r['transport_error'], isTrue);
      expect(r['message'], contains('timed out'));
    });

    test('unreachable server is a transport error', () async {
      final r = await run(MockClient((_) async => throw http.ClientException('Failed to fetch')));
      expect(r['transport_error'], isTrue);
      expect(r['message'], contains('reach the server'));
    });

    for (final code in [500, 502, 429]) {
      test('HTTP $code is a transport error, not a wrong password', () async {
        final r = await run(MockClient((_) async => _json({'success': false, 'message': 'busy'}, code)));
        expect(r['transport_error'], isTrue);
      });
    }
  });

  group('LoginScreen submission', () {
    late List<String> posts;

    setUp(() async {
      RateLimiter.reset();
      posts = [];
      SharedPreferences.setMockInitialValues({});
      // A previous test's successful login must not leave a session behind
      // (LoginScreen redirects away when it finds one).
      await ApiService.clearSession();
    });

    Future<void> pumpLogin(WidgetTester t, http.Client client, {List<String>? routes}) async {
      t.view.physicalSize = const Size(400, 1400);
      t.view.devicePixelRatio = 1.0;
      addTearDown(t.view.reset);
      await http.runWithClient(() async {
        await t.pumpWidget(MaterialApp(
          home: const LoginScreen(),
          onGenerateRoute: (s) {
            routes?.add(s.name ?? '');
            return MaterialPageRoute(settings: s, builder: (_) => const Scaffold());
          },
        ));
        await t.pump();
        await t.pump();
      }, () => client);
    }

    Future<void> fill(WidgetTester t, {String email = _tlEmail, String password = 'TlDemo@2026'}) async {
      await t.enterText(find.byType(TextField).at(0), email);
      await t.enterText(find.byType(TextField).at(1), password);
    }

    /// Runs [body] inside the mock-client zone so requests fired after a tap
    /// are served by the same mock.
    Future<void> inClient(http.Client c, Future<void> Function() body) =>
        http.runWithClient(body, () => c);

    testWidgets('one Sign in tap = exactly one POST /login', (t) async {
      final client = MockClient((r) async {
        if (r.url.path.endsWith('/login')) posts.add(r.url.path);
        if (r.url.path.endsWith('/login')) await Future<void>.delayed(const Duration(milliseconds: 300));
        return _tlSuccess();
      });
      final routes = <String>[];
      await pumpLogin(t, client, routes: routes);
      await fill(t);

      await inClient(client, () async {
        await t.ensureVisible(find.text('Sign in'));
        await t.tap(find.text('Sign in'));
        await t.pump(const Duration(milliseconds: 500));
      });

      expect(posts, ['/api/login']);
      expect(routes, contains('/tl-home'));
      TlPresenceController.stop();
    });

    testWidgets('Enter pressed repeatedly while a login is in flight adds NO extra requests', (t) async {
      final gate = Completer<void>();
      final client = MockClient((r) async {
        if (r.url.path.endsWith('/login')) posts.add(r.url.path);
        await gate.future;
        return _tlSuccess();
      });
      await pumpLogin(t, client);
      await fill(t);

      await inClient(client, () async {
        await t.ensureVisible(find.byType(TextField).at(1));
        for (var i = 0; i < 4; i++) {
          // Enter with TextInputAction.done unfocuses the field, so focus it
          // again before every press to model a user hammering Enter.
          await t.tap(find.byType(TextField).at(1));
          await t.testTextInput.receiveAction(TextInputAction.done);
          await t.pump(const Duration(milliseconds: 50));
        }
        expect(posts, hasLength(1), reason: 'only one logical attempt while loading');
        gate.complete();
        await t.pump(const Duration(milliseconds: 500));
      });
      TlPresenceController.stop();
    });

    testWidgets('a real 401 shows the backend invalid-credentials message, not a timeout', (t) async {
      final client = MockClient((r) async {
        if (r.url.path.endsWith('/login')) posts.add(r.url.path);
        return _json({'success': false, 'message': 'Invalid credentials.'}, 401);
      });
      await pumpLogin(t, client);
      await fill(t, password: 'wrong');

      await inClient(client, () async {
        await t.ensureVisible(find.text('Sign in'));
        await t.tap(find.text('Sign in'));
        await t.pump(const Duration(milliseconds: 300));
      });

      expect(find.text('Invalid credentials.'), findsOneWidget);
      expect(find.textContaining('timed out'), findsNothing);
    });

    testWidgets('a timeout ends loading cleanly, shows timed out, and retry works', (t) async {
      var fail = true;
      final client = MockClient((r) async {
        if (r.url.path.endsWith('/login')) posts.add(r.url.path);
        if (fail) throw TimeoutException('slow');
        return _tlSuccess();
      });
      final routes = <String>[];
      await pumpLogin(t, client, routes: routes);
      await fill(t);

      await inClient(client, () async {
        await t.ensureVisible(find.text('Sign in'));
        await t.tap(find.text('Sign in'));
        await t.pump(const Duration(milliseconds: 300));
        expect(find.text('Request timed out. Please try again.'), findsOneWidget);
        expect(find.byType(CircularProgressIndicator), findsNothing);

        fail = false;
        await t.tap(find.text('Sign in'));
        await t.pump(const Duration(milliseconds: 500));
      });

      expect(posts, hasLength(2));
      expect(routes, contains('/tl-home'));
      TlPresenceController.stop();
    });

    testWidgets('three timeouts do NOT lock out a correct password', (t) async {
      final client = MockClient((r) async {
        if (r.url.path.endsWith('/login')) posts.add(r.url.path);
        throw TimeoutException('slow');
      });
      await pumpLogin(t, client);
      await fill(t);

      await inClient(client, () async {
        await t.ensureVisible(find.text('Sign in'));
        for (var i = 0; i < 4; i++) {
          await t.tap(find.text('Sign in'));
          await t.pump(const Duration(milliseconds: 200));
        }
      });

      expect(posts, hasLength(4), reason: 'every attempt was allowed through');
      expect(RateLimiter.isLocked, isFalse);
    });

    testWidgets('three genuine 401s still trigger the credential lockout', (t) async {
      final client = MockClient((r) async {
        if (r.url.path.endsWith('/login')) posts.add(r.url.path);
        return _json({'success': false, 'message': 'Invalid credentials.'}, 401);
      });
      await pumpLogin(t, client);
      await fill(t, password: 'wrong');

      await inClient(client, () async {
        await t.ensureVisible(find.text('Sign in'));
        for (var i = 0; i < 3; i++) {
          await t.tap(find.text('Sign in'));
          await t.pump(const Duration(milliseconds: 200));
        }
      });

      expect(RateLimiter.isLocked, isTrue);
      RateLimiter.reset();
    });
  });
}
