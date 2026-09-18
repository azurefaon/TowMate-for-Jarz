import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/team_leader/tl_profile_screen.dart';
import 'package:towmate_app/services/tl_presence_controller.dart';
import 'package:towmate_app/widgets/skeleton_box.dart';
import 'package:towmate_app/widgets/tl_bottom_nav.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

final _profileFixture = {
  'data': {
    'name': 'Ariel Santos',
    'first_name': 'Ariel',
    'last_name': 'Santos',
    'email': 'ariel@example.test',
    'phone': '+639171234567',
  },
};

http.Client _buildClient({Map<String, dynamic>? profileOverride, bool slowProfile = false}) {
  return MockClient((request) async {
    final path = request.url.path;
    if (path.endsWith('/v1/profile')) {
      if (slowProfile) await Future<void>.delayed(const Duration(seconds: 2));
      return _json(profileOverride ?? _profileFixture);
    }
    if (path.endsWith('/v1/team-leader/presence/offline')) {
      return _json({'success': true});
    }
    return _json({'success': false}, status: 404);
  });
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pumpTlProfile(
  WidgetTester tester, {
  Map<String, dynamic>? profileOverride,
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
              return MaterialPageRoute(builder: (_) => const TlProfileScreen());
            }
            onNavigate?.call(settings.name!, settings.arguments);
            return MaterialPageRoute(builder: (_) => const Scaffold());
          },
        ),
      );
      await _settle(tester);
    },
    () => _buildClient(profileOverride: profileOverride),
  );
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  group('TlProfileScreen', () {
    testWidgets('shows no hamburger menu or drawer', (tester) async {
      await _pumpTlProfile(tester);

      expect(find.byIcon(Icons.menu_rounded), findsNothing);
      expect(find.byType(Drawer), findsNothing);
      expect(find.byType(AppBar), findsNothing);
    });

    testWidgets('shows a loading skeleton, not a spinner, before content arrives', (tester) async {
      SharedPreferences.setMockInitialValues({'auth_token': 'tl-token'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: TlProfileScreen()));
          await tester.pump();
        },
        () => _buildClient(slowProfile: true),
      );

      expect(find.byType(SkeletonBox), findsWidgets);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(tester.takeException(), isNull);

      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('shows the real Team Leader name and role, and hides the skeleton', (tester) async {
      await _pumpTlProfile(tester);

      expect(find.text('Ariel Santos'), findsWidgets);
      expect(find.text('Team Leader'), findsOneWidget);
      expect(find.byType(SkeletonBox), findsNothing);
    });

    testWidgets('shows Name, Email, Phone, Password account rows', (tester) async {
      await _pumpTlProfile(tester);

      expect(find.text('Name'), findsOneWidget);
      expect(find.text('Email'), findsOneWidget);
      expect(find.text('Phone'), findsOneWidget);
      expect(find.text('Password'), findsOneWidget);
      expect(find.text('••••••••'), findsOneWidget);
    });

    testWidgets('tapping Email does not open an edit dialog', (tester) async {
      await _pumpTlProfile(tester);

      await tester.tap(find.text('Email'));
      await _settle(tester);

      expect(find.text('Edit Phone'), findsNothing);
      expect(find.text('Edit Name'), findsNothing);
    });

    testWidgets('tapping Name opens the Edit Name dialog', (tester) async {
      await _pumpTlProfile(tester);

      await tester.tap(find.text('Name'));
      await _settle(tester);

      expect(find.text('Edit Name'), findsOneWidget);
    });

    testWidgets('tapping Phone opens the Edit Phone dialog', (tester) async {
      await _pumpTlProfile(tester);

      await tester.tap(find.text('Phone'));
      await _settle(tester);

      expect(find.text('Edit Phone'), findsOneWidget);
    });

    testWidgets('tapping Password opens the Change Password dialog', (tester) async {
      await _pumpTlProfile(tester);

      await tester.tap(find.text('Password'));
      await _settle(tester);

      expect(find.text('Change Password'), findsOneWidget);
    });

    testWidgets('shows the Appearance section with a Dark Mode switch', (tester) async {
      await _pumpTlProfile(tester);

      expect(find.text('Appearance'), findsOneWidget);
      expect(find.text('Dark Mode'), findsOneWidget);
      expect(find.byType(Switch), findsOneWidget);
    });

    testWidgets('toggling Dark Mode does not throw', (tester) async {
      await _pumpTlProfile(tester);

      final switchFinder = find.byType(Switch);
      await tester.ensureVisible(switchFinder);
      await _settle(tester);
      await tester.tap(switchFinder);
      await _settle(tester);

      expect(tester.takeException(), isNull);
    });

    testWidgets('shows a Log out action and its confirmation dialog', (tester) async {
      await _pumpTlProfile(tester);

      await tester.ensureVisible(find.text('Log out'));
      await _settle(tester);
      await tester.tap(find.text('Log out'));
      await _settle(tester);

      expect(find.text('Log out?'), findsOneWidget);
    });

    testWidgets('confirming Log out navigates to the exact existing /login route', (tester) async {
      String? capturedRoute;
      await _pumpTlProfile(tester, onNavigate: (route, args) => capturedRoute = route);

      await tester.ensureVisible(find.text('Log out'));
      await _settle(tester);
      await tester.tap(find.text('Log out'));
      await _settle(tester);
      await tester.tap(find.widgetWithText(TextButton, 'Log out'));
      await _settle(tester);

      expect(capturedRoute, '/login');
    });

    testWidgets('confirming Log out stops the TL presence heartbeat', (tester) async {
      try {
        final pings = <String>[];
        SharedPreferences.setMockInitialValues({'auth_token': 'tl-token'});
        await http.runWithClient(
          () async {
            await tester.pumpWidget(
              MaterialApp(
                onGenerateRoute: (settings) {
                  if (settings.name == '/' || settings.name == null) {
                    return MaterialPageRoute(builder: (_) => const TlProfileScreen());
                  }
                  return MaterialPageRoute(builder: (_) => const Scaffold());
                },
              ),
            );
            await _settle(tester);

            TlPresenceController.start();
            await tester.pump();
            pings.clear();

            await tester.ensureVisible(find.text('Log out'));
            await _settle(tester);
            await tester.tap(find.text('Log out'));
            await _settle(tester);
            await tester.tap(find.widgetWithText(TextButton, 'Log out'));
            await _settle(tester);

            expect(TlPresenceController.isActive, isFalse);

            final afterLogout = pings.length;
            await tester.pump(const Duration(seconds: 45));
            expect(pings.length, afterLogout);
          },
          () => MockClient((request) async {
            final path = request.url.path;
            if (path.endsWith('/presence/ping')) {
              pings.add(path);
              return _json({'success': true});
            }
            if (path.endsWith('/v1/profile')) return _json(_profileFixture);
            if (path.endsWith('/presence/offline')) return _json({'success': true});
            return _json({'success': false}, status: 404);
          }),
        );
      } finally {
        TlPresenceController.stop();
      }
    });

    testWidgets('bottom nav shows the four real Team Leader destinations', (tester) async {
      await _pumpTlProfile(tester);

      final nav = find.byType(TlBottomNav);
      expect(nav, findsOneWidget);
      for (final label in ['Home', 'My Task', 'History', 'Profile']) {
        expect(find.descendant(of: nav, matching: find.text(label)), findsOneWidget);
      }
    });

    testWidgets('tapping History navigates to the exact existing /tl-history route', (tester) async {
      String? capturedRoute;
      await _pumpTlProfile(tester, onNavigate: (route, args) => capturedRoute = route);

      await tester.tap(find.text('History'));
      await _settle(tester);

      expect(capturedRoute, '/tl-history');
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

        await _pumpTlProfile(tester);

        expect(tester.takeException(), isNull);
        expect(find.byType(TlBottomNav), findsOneWidget);
      });
    }

    testWidgets('renders correctly in light mode', (tester) async {
      await _pumpTlProfile(tester, theme: AppTheme.light, themeMode: ThemeMode.light);

      expect(tester.takeException(), isNull);
      expect(find.text('Ariel Santos'), findsWidgets);
    });

    testWidgets('renders correctly in dark mode', (tester) async {
      await _pumpTlProfile(tester, theme: AppTheme.dark, themeMode: ThemeMode.dark);

      expect(tester.takeException(), isNull);
      expect(find.text('Ariel Santos'), findsWidgets);
    });
  });
}
