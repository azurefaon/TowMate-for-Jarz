import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/widgets/tl_bottom_nav.dart';

Widget _host(String route, {ThemeData? theme, ThemeMode? themeMode, ValueChanged<String>? onNavigate}) {
  return MaterialApp(
    theme: theme,
    themeMode: themeMode,
    onGenerateRoute: (settings) {
      if (settings.name == '/' || settings.name == null) {
        return MaterialPageRoute(
          builder: (_) => Scaffold(bottomNavigationBar: TlBottomNav(currentRoute: route)),
        );
      }
      onNavigate?.call(settings.name!);
      return MaterialPageRoute(builder: (_) => const Scaffold());
    },
  );
}

void main() {
  group('TlBottomNav', () {
    testWidgets('shows exactly the four real Team Leader destinations', (tester) async {
      await tester.pumpWidget(_host('/tl-home'));

      expect(find.text('Home'), findsOneWidget);
      expect(find.text('My Task'), findsOneWidget);
      expect(find.text('History'), findsOneWidget);
      expect(find.text('Profile'), findsOneWidget);
    });

    testWidgets('does not include a Logout destination', (tester) async {
      await tester.pumpWidget(_host('/tl-home'));

      expect(find.text('Logout'), findsNothing);
      expect(find.text('Log out'), findsNothing);
    });

    for (final route in ['/tl-home', '/tl-active-task', '/tl-history', '/tl-profile']) {
      testWidgets('tapping the already-selected tab for $route does not navigate', (tester) async {
        String? captured;
        await tester.pumpWidget(_host(route, onNavigate: (r) => captured = r));

        final label = switch (route) {
          '/tl-home' => 'Home',
          '/tl-active-task' => 'My Task',
          '/tl-history' => 'History',
          _ => 'Profile',
        };
        await tester.tap(find.text(label));
        await tester.pumpAndSettle();

        expect(captured, isNull);
      });
    }

    testWidgets('tapping My Task from Home pushReplaces to the exact existing route', (tester) async {
      String? captured;
      await tester.pumpWidget(_host('/tl-home', onNavigate: (r) => captured = r));

      await tester.tap(find.text('My Task'));
      await tester.pumpAndSettle();

      expect(captured, '/tl-active-task');
    });

    testWidgets('tapping History from Home pushReplaces to the exact existing route', (tester) async {
      String? captured;
      await tester.pumpWidget(_host('/tl-home', onNavigate: (r) => captured = r));

      await tester.tap(find.text('History'));
      await tester.pumpAndSettle();

      expect(captured, '/tl-history');
    });

    testWidgets('tapping Profile from Home pushReplaces to the exact existing route', (tester) async {
      String? captured;
      await tester.pumpWidget(_host('/tl-home', onNavigate: (r) => captured = r));

      await tester.tap(find.text('Profile'));
      await tester.pumpAndSettle();

      expect(captured, '/tl-profile');
    });

    testWidgets('renders correctly in light mode', (tester) async {
      await tester.pumpWidget(_host('/tl-history', theme: AppTheme.light, themeMode: ThemeMode.light));

      expect(tester.takeException(), isNull);
      expect(find.byType(TlBottomNav), findsOneWidget);
    });

    testWidgets('renders correctly in dark mode', (tester) async {
      await tester.pumpWidget(_host('/tl-history', theme: AppTheme.dark, themeMode: ThemeMode.dark));

      expect(tester.takeException(), isNull);
      expect(find.byType(TlBottomNav), findsOneWidget);
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

        await tester.pumpWidget(_host('/tl-profile'));

        expect(tester.takeException(), isNull);
      });
    }
  });
}
