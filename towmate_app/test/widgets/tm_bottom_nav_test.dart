import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/widgets/tm_bottom_nav.dart';

class _Recorder extends NavigatorObserver {
  final pushed = <String>[];
  final replaced = <String>[];

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    final name = route.settings.name;
    if (name != null && name != '/') pushed.add(name);
  }

  @override
  void didReplace({Route<dynamic>? newRoute, Route<dynamic>? oldRoute}) {
    final name = newRoute?.settings.name;
    if (name != null) replaced.add(name);
  }
}

double _contrast(Color a, Color b) {
  final l1 = a.computeLuminance();
  final l2 = b.computeLuminance();
  return (math.max(l1, l2) + 0.05) / (math.min(l1, l2) + 0.05);
}

void main() {
  late _Recorder recorder;

  Future<void> pumpNav(
    WidgetTester tester, {
    String current = '/home',
    bool dark = false,
    double bottomInset = 0,
    double textScale = 1.0,
    Size size = const Size(360, 800),
  }) async {
    recorder = _Recorder();
    await tester.binding.setSurfaceSize(size);
    addTearDown(() => tester.binding.setSurfaceSize(null));
    Widget page(String name) =>
        Scaffold(body: Center(child: Text('PAGE $name')));
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.light,
        darkTheme: AppTheme.dark,
        themeMode: dark ? ThemeMode.dark : ThemeMode.light,
        navigatorObservers: [recorder],
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(context).copyWith(
            padding: EdgeInsets.only(bottom: bottomInset),
            textScaler: TextScaler.linear(textScale),
          ),
          child: child!,
        ),
        initialRoute: '/',
        onGenerateRoute: (settings) {
          if (settings.name == '/') {
            return MaterialPageRoute(
              settings: settings,
              builder: (_) => Scaffold(
                backgroundColor: Colors.grey,
                body: const SizedBox.expand(),
                bottomNavigationBar:
                    TmBottomNav(currentRoute: current, unreadCount: 3),
              ),
            );
          }
          return MaterialPageRoute(
            settings: settings,
            builder: (_) => page(settings.name!),
          );
        },
      ),
    );
    await tester.pumpAndSettle();
  }

  Rect fabRect(WidgetTester tester) => tester.getRect(
        find
            .ancestor(
              of: find.byIcon(Icons.add),
              matching: find.byType(Container),
            )
            .first,
      );

  Finder barFinder(Color card) => find.descendant(
        of: find.byType(TmBottomNav),
        matching: find.byWidgetPredicate(
          (w) =>
              w is DecoratedBox &&
              w.decoration is BoxDecoration &&
              (w.decoration as BoxDecoration).color == card &&
              (w.decoration as BoxDecoration).border != null,
        ),
      );

  group('destinations', () {
    testWidgets('Home replaces the route', (tester) async {
      await pumpNav(tester, current: '/my-bookings');
      await tester.tap(find.text('Home'));
      await tester.pumpAndSettle();
      expect(find.text('PAGE /home'), findsOneWidget);
      expect(recorder.replaced, ['/home']);
    });

    testWidgets('Bookings replaces the route', (tester) async {
      await pumpNav(tester);
      await tester.tap(find.text('Bookings'));
      await tester.pumpAndSettle();
      expect(find.text('PAGE /my-bookings'), findsOneWidget);
      expect(recorder.replaced, ['/my-bookings']);
    });

    testWidgets('Alerts pushes the route', (tester) async {
      await pumpNav(tester);
      await tester.tap(find.text('Alerts'));
      await tester.pumpAndSettle();
      expect(find.text('PAGE /notifications'), findsOneWidget);
      expect(recorder.pushed, ['/notifications']);
    });

    testWidgets('Profile pushes the route', (tester) async {
      await pumpNav(tester);
      await tester.tap(find.text('Profile'));
      await tester.pumpAndSettle();
      expect(find.text('PAGE /profile'), findsOneWidget);
      expect(recorder.pushed, ['/profile']);
    });

    testWidgets('Book Now pushes the route', (tester) async {
      await pumpNav(tester);
      await tester.tap(find.text('Book Now'));
      await tester.pumpAndSettle();
      expect(find.text('PAGE /book-now'), findsOneWidget);
      expect(recorder.pushed, ['/book-now']);
    });

testWidgets('re-tapping the current tab does nothing', (tester) async {      await pumpNav(tester, current: '/home');      await tester.tap(find.text('Home'));      await tester.pumpAndSettle();      expect(recorder.replaced, isEmpty);      expect(recorder.pushed, isEmpty);    });    testWidgets('re-tapping the current pushed tab does nothing', (tester) async {      await pumpNav(tester, current: '/profile');      await tester.tap(find.text('Profile'));      await tester.pumpAndSettle();      expect(recorder.pushed, isEmpty);      expect(recorder.replaced, isEmpty);    });
  });

  group('Book Now hit testing', () {
    final points = <String, Offset Function(Rect)>{
      'center': (r) => r.center,
      'upper part (above the bar)': (r) => Offset(r.center.dx, r.top + 6),
      'lower part': (r) => Offset(r.center.dx, r.bottom - 6),
    };

    for (final entry in points.entries) {
      testWidgets('tapping the ${entry.key} triggers exactly one Book Now',
          (tester) async {
        await pumpNav(tester);
        final fab = fabRect(tester);
        if (entry.key.startsWith('upper')) {
          // The visible circle really does rise above the bar's painted top edge.
          final barTop = tester.getTopLeft(barFinder(TmColors.white)).dy;
          expect(fab.top, lessThan(barTop));
        }
        await tester.tapAt(entry.value(fab));
        await tester.pumpAndSettle();
        expect(recorder.pushed, ['/book-now']);
      });
    }

    testWidgets('tapping just above the circle does nothing', (tester) async {
      await pumpNav(tester);
      final fab = fabRect(tester);
      await tester.tapAt(Offset(fab.center.dx, fab.top - 4));
      await tester.pumpAndSettle();
      expect(recorder.pushed, isEmpty);
      expect(recorder.replaced, isEmpty);
    });

    testWidgets('tapping just beside the circle does nothing', (tester) async {
      await pumpNav(tester);
      final fab = fabRect(tester);
      await tester.tapAt(Offset(fab.left - 5, fab.center.dy + 12));
      await tester.tapAt(Offset(fab.right + 5, fab.center.dy + 12));
      await tester.pumpAndSettle();
      expect(recorder.pushed, isEmpty);
      expect(recorder.replaced, isEmpty);
    });
  });

  group('theme', () {
    for (final dark in [false, true]) {
      final mode = dark ? 'dark' : 'light';
      testWidgets('labels are readable in $mode mode', (tester) async {
        await pumpNav(tester, dark: dark);
        final card = dark ? TmColors.dark800 : TmColors.white;
        Color colorOf(String label) =>
            tester.widget<Text>(find.text(label)).style!.color!;

        expect(_contrast(colorOf('Book Now'), card), greaterThanOrEqualTo(4.5));
        for (final label in ['Bookings', 'Alerts', 'Profile']) {
          expect(_contrast(colorOf(label), card), greaterThanOrEqualTo(3.0),
              reason: label);
        }
      });
    }

    testWidgets('a selected Book Now uses the accent colour', (tester) async {
      await pumpNav(tester, current: '/book-now');
      expect(tester.widget<Text>(find.text('Book Now')).style!.color,
          TmColors.yellow);
    });
  });

  group('safe area', () {
    for (final dark in [false, true]) {
      final mode = dark ? 'dark' : 'light';
      testWidgets('the bar background continues through the bottom inset ($mode)',
          (tester) async {
        await pumpNav(tester, bottomInset: 34, dark: dark);
        final screen = tester.getRect(find.byType(Scaffold).first);
        final nav = tester.getRect(find.byType(TmBottomNav));
        expect(nav.bottom, screen.bottom);

        final card = dark ? TmColors.dark800 : TmColors.white;
        final barRect = tester.getRect(barFinder(card));
        expect(barRect.bottom, screen.bottom,
            reason: 'no scaffold-coloured strip below the bar');
        expect(barRect.height, 62 + 34);

        // Tab contents stay above the gesture area.
        final home = tester.getRect(find.text('Home'));
        expect(home.bottom, lessThanOrEqualTo(screen.bottom - 34));
      });
    }

    testWidgets('with no inset the bar keeps its normal height', (tester) async {
      await pumpNav(tester);
      expect(tester.getSize(find.byType(TmBottomNav)).height, 24 + 62);
    });
  });

  group('geometry', () {
    testWidgets('no overflow on a narrow phone with large text', (tester) async {
      await pumpNav(tester,
          size: const Size(320, 640), textScale: 1.5, bottomInset: 20);
      expect(tester.takeException(), isNull);
      final nav = tester.getRect(find.byType(TmBottomNav));
      expect(fabRect(tester).top, greaterThanOrEqualTo(nav.top));
      expect(tester.getRect(find.text('Book Now')).bottom,
          lessThanOrEqualTo(nav.bottom - 20));

      await tester.tap(find.text('Book Now'));
      await tester.pumpAndSettle();
      expect(recorder.pushed, ['/book-now']);
    });

    testWidgets('the unread badge renders on Alerts', (tester) async {
      await pumpNav(tester);
      expect(find.text('3'), findsOneWidget);
    });
  });
}
