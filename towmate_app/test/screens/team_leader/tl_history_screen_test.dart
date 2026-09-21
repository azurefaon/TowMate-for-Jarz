import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/team_leader/tl_history_screen.dart';
import 'package:towmate_app/widgets/skeleton_box.dart';
import 'package:towmate_app/widgets/tl_bottom_nav.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

final _jobsFixture = [
  {
    'booking_code': 'TM-0777',
    'status': 'completed',
    'customer_name': 'Maria Santos',
    'pickup_address': 'Quezon City',
    'dropoff_address': 'Mandaluyong',
    'final_total': 1250.0,
    'completed_at': '2026-08-01T10:00:00Z',
  },
  {
    'booking_code': 'TM-0778',
    'status': 'returned',
    'customer_name': 'Jose Cruz',
    'pickup_address': 'Pasig',
    'dropoff_address': 'Taguig',
    'final_total': 0.0,
    'completed_at': '2026-08-02T10:00:00Z',
  },
];

http.Client _buildClient({
  List<Map<String, dynamic>>? jobs,
  bool slow = false,
  int lastPage = 1,
}) {
  return MockClient((request) async {
    final path = request.url.path;
    if (path.endsWith('/v1/team-leader/history')) {
      if (slow) await Future<void>.delayed(const Duration(seconds: 2));
      return _json({
        'success': true,
        'data': jobs ?? _jobsFixture,
        'current_page': 1,
        'last_page': lastPage,
      });
    }
    return _json({'success': false}, status: 404);
  });
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pumpTlHistory(
  WidgetTester tester, {
  List<Map<String, dynamic>>? jobs,
  bool slow = false,
  ThemeData? theme,
  ThemeMode? themeMode,
  void Function(String route, Object? args)? onNavigate,
}) async {
  SharedPreferences.setMockInitialValues({'auth_token': 'tl-token'});
  await http.runWithClient(
    () async {
      await tester.pumpWidget(
        MaterialApp(
          theme: theme,
          themeMode: themeMode,
          onGenerateRoute: (settings) {
            if (settings.name == '/' || settings.name == null) {
              return MaterialPageRoute(builder: (_) => const TlHistoryScreen());
            }
            onNavigate?.call(settings.name!, settings.arguments);
            return MaterialPageRoute(builder: (_) => const Scaffold());
          },
        ),
      );
      await _settle(tester);
    },
    () => _buildClient(jobs: jobs, slow: slow),
  );
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  group('TlHistoryScreen', () {
    testWidgets('shows no hamburger menu or drawer', (tester) async {
      await _pumpTlHistory(tester);

      expect(find.byIcon(Icons.menu_rounded), findsNothing);
      expect(find.byType(Drawer), findsNothing);
      expect(find.byType(AppBar), findsNothing);
    });

    testWidgets('shows a loading skeleton, not a spinner, before content arrives', (tester) async {
      SharedPreferences.setMockInitialValues({'auth_token': 'tl-token'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: TlHistoryScreen()));
          await tester.pump();
        },
        () => _buildClient(slow: true),
      );

      expect(find.byType(SkeletonBox), findsWidgets);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(tester.takeException(), isNull);

      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('renders real completed and returned job data and hides the skeleton', (tester) async {
      await _pumpTlHistory(tester);

      expect(find.text('TM-0777'), findsOneWidget);
      expect(find.text('Maria Santos'), findsOneWidget);
      expect(find.text('Completed'), findsOneWidget);
      expect(find.text('TM-0778'), findsOneWidget);
      expect(find.text('Jose Cruz'), findsOneWidget);
      expect(find.text('Returned'), findsOneWidget);
      expect(find.byType(SkeletonBox), findsNothing);
    });

    testWidgets('shows the empty state when there are no completed jobs', (tester) async {
      await _pumpTlHistory(tester, jobs: []);

      expect(find.text('No completed jobs yet'), findsOneWidget);
    });

    testWidgets('bottom nav shows the four real Team Leader destinations', (tester) async {
      await _pumpTlHistory(tester);

      final nav = find.byType(TlBottomNav);
      expect(nav, findsOneWidget);
      for (final label in ['Home', 'My Task', 'History', 'Profile']) {
        expect(find.descendant(of: nav, matching: find.text(label)), findsOneWidget);
      }
    });

    testWidgets('tapping Home navigates to the exact existing /tl-home route', (tester) async {
      String? capturedRoute;
      await _pumpTlHistory(tester, onNavigate: (route, args) => capturedRoute = route);

      await tester.tap(find.text('Home'));
      await _settle(tester);

      expect(capturedRoute, '/tl-home');
    });

    testWidgets('tapping the already-selected History tab does not navigate away', (tester) async {
      final routes = <String>[];
      await _pumpTlHistory(tester, onNavigate: (route, args) => routes.add(route));

      await tester.tap(find.text('History'));
      await _settle(tester);

      expect(routes, isEmpty);
    });

    testWidgets('pull to refresh reuses the exact existing load callback without throwing', (tester) async {
      await http.runWithClient(
        () async {
          await _pumpTlHistory(tester);
          await tester.fling(find.byType(ListView), const Offset(0, 300), 1000);
          await _settle(tester);
        },
        () => _buildClient(),
      );

      expect(tester.takeException(), isNull);
    });

    for (final width in [320.0, 340.0, 360.0, 375.0, 390.0, 412.0]) {
      testWidgets('renders without horizontal overflow at $width px width', (tester) async {
        final originalSize = tester.view.physicalSize;
        final originalRatio = tester.view.devicePixelRatio;
        tester.view.physicalSize = Size(width, 800);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(() {
          tester.view.physicalSize = originalSize;
          tester.view.devicePixelRatio = originalRatio;
        });

        await _pumpTlHistory(tester);

        expect(tester.takeException(), isNull);
        expect(find.byType(TlBottomNav), findsOneWidget);
      });
    }

    testWidgets('renders correctly in light mode', (tester) async {
      await _pumpTlHistory(tester, theme: AppTheme.light, themeMode: ThemeMode.light);

      expect(tester.takeException(), isNull);
      expect(find.text('TM-0777'), findsOneWidget);
    });

    testWidgets('renders correctly in dark mode', (tester) async {
      await _pumpTlHistory(tester, theme: AppTheme.dark, themeMode: ThemeMode.dark);

      expect(tester.takeException(), isNull);
      expect(find.text('TM-0777'), findsOneWidget);
    });
  });
}
