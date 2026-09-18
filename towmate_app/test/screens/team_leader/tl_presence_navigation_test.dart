import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/team_leader/tl_active_task_shell.dart';
import 'package:towmate_app/screens/team_leader/tl_history_screen.dart';
import 'package:towmate_app/screens/team_leader/tl_home_screen.dart';
import 'package:towmate_app/screens/team_leader/tl_profile_screen.dart';
import 'package:towmate_app/services/tl_presence_controller.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<http.Client> _pumpTlApp(WidgetTester tester, List<String> pings) async {
  SharedPreferences.setMockInitialValues(
    {'auth_token': 'tl-token', 'user_name': 'Ariel Santos', 'duty_class': 'heavy'},
  );
  final client = MockClient((request) async {
    final path = request.url.path;
    if (path.endsWith('/presence/ping')) {
      pings.add(path);
      return _json({'success': true});
    }
    if (path.endsWith('/presence/away') || path.endsWith('/presence/offline')) {
      return _json({'success': true});
    }
    if (path.endsWith('/v1/team-leader/task')) {
      return _json({'success': true, 'data': null});
    }
    if (path.endsWith('/v1/team-leader/history')) {
      return _json({'success': true, 'data': []});
    }
    if (path.endsWith('/v1/profile')) {
      return _json({'success': false});
    }
    return _json({'success': false}, status: 404);
  });

  await http.runWithClient(
    () async {
      await tester.pumpWidget(
        MaterialApp(
          home: const TlHomeScreen(),
          onGenerateRoute: (settings) {
            final page = switch (settings.name) {
              '/tl-home' => const TlHomeScreen(),
              '/tl-active-task' => const TlActiveTaskShell(),
              '/tl-history' => const TlHistoryScreen(),
              '/tl-profile' => const TlProfileScreen(),
              _ => const TlHomeScreen(),
            };
            return MaterialPageRoute(settings: settings, builder: (_) => page);
          },
        ),
      );
      await _settle(tester);
    },
    () => client,
  );

  return client;
}

void _tlTest(String description, Future<void> Function(WidgetTester) body) {
  testWidgets(description, (tester) async {
    try {
      await body(tester);
    } finally {
      TlPresenceController.stop();
    }
  });
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('flutter.baseflow.com/geolocator'),
    (call) async => call.method == 'checkPermission' ? 2 : null,
  );
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('flutter.baseflow.com/geolocator_android'),
    (call) async => call.method == 'checkPermission' ? 2 : null,
  );

  group('TL presence heartbeat across navigation', () {
    _tlTest('starts exactly one heartbeat when the TL session begins on Home', (tester) async {
      final pings = <String>[];
      await _pumpTlApp(tester, pings);

      expect(pings.length, 1);
      expect(TlPresenceController.isActive, isTrue);
    });

    _tlTest('switching Home to History does not stop the heartbeat', (tester) async {
      final pings = <String>[];
      final client = await _pumpTlApp(tester, pings);

      await http.runWithClient(() async {
        await tester.tap(find.text('History'));
        await _settle(tester);
        expect(find.byType(TlHistoryScreen), findsOneWidget);

        final afterNav = pings.length;
        await tester.pump(const Duration(seconds: 45));
        expect(pings.length - afterNav, 1);
      }, () => client);
    });

    _tlTest('switching Home to Profile does not stop the heartbeat', (tester) async {
      final pings = <String>[];
      final client = await _pumpTlApp(tester, pings);

      await http.runWithClient(() async {
        await tester.tap(find.text('Profile'));
        await _settle(tester);
        expect(find.byType(TlProfileScreen), findsOneWidget);

        final afterNav = pings.length;
        await tester.pump(const Duration(seconds: 45));
        expect(pings.length - afterNav, 1);
      }, () => client);
    });

    _tlTest('navigating through My Task does not create a duplicate heartbeat', (tester) async {
      final pings = <String>[];
      final client = await _pumpTlApp(tester, pings);

      await http.runWithClient(() async {
        await tester.tap(find.text('My Task'));
        await tester.pumpAndSettle();
        expect(find.byType(TlActiveTaskShell), findsOneWidget);
        expect(find.byType(TlHomeScreen), findsNothing);

        await tester.tap(find.text('History'));
        await tester.pumpAndSettle();
        expect(find.byType(TlHistoryScreen), findsOneWidget);
        expect(find.byType(TlActiveTaskShell), findsNothing);

        await tester.tap(find.text('Profile'));
        await tester.pumpAndSettle();
        expect(find.byType(TlProfileScreen), findsOneWidget);
        expect(find.byType(TlHistoryScreen), findsNothing);

        await tester.tap(find.text('Home'));
        await tester.pumpAndSettle();
        expect(find.byType(TlHomeScreen), findsOneWidget);
        expect(find.byType(TlProfileScreen), findsNothing);

        final afterNav = pings.length;
        await tester.pump(const Duration(seconds: 45));
        expect(pings.length - afterNav, 1);
      }, () => client);
    });
  });
}
