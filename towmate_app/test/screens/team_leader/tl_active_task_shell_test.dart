import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/models/task_model.dart';
import 'package:towmate_app/screens/team_leader/tl_active_task_shell.dart';
import 'package:towmate_app/services/tl_presence_controller.dart';
import 'package:towmate_app/widgets/skeleton_box.dart';
import 'package:towmate_app/widgets/tl_bottom_nav.dart';

TaskModel _task({
  required String bookingCode,
  required String status,
  int groupVehicleCount = 1,
  int groupPosition = 1,
}) {
  return TaskModel(
    id: bookingCode.hashCode,
    bookingCode: bookingCode,
    status: status,
    pickupAddress: 'Pasay',
    dropoffAddress: 'Makati',
    pickupLat: 14.5,
    pickupLng: 121.0,
    dropoffLat: 14.6,
    dropoffLng: 121.1,
    distanceKm: 9.5,
    customerName: 'Customer',
    customerPhone: '09170000000',
    customerEmail: 'customer@example.test',
    finalTotal: 4658.08,
    truckTypeName: 'Medium Duty',
    serviceType: 'book_now',
    groupVehicleCount: groupVehicleCount,
    groupPosition: groupPosition,
  );
}

void main() {
  group('resolveFetchedTask', () {
    test('excludes a completed solo booking fetched fresh with no prior tracking', () {
      final fetched = _task(bookingCode: 'TM-00100', status: 'completed');

      final result = resolveFetchedTask(previous: null, fetched: fetched);

      expect(result, isNull);
    });

    test('excludes both completed siblings of a normalized group fetched fresh', () {
      final fetchedA = _task(
        bookingCode: 'TM-00230',
        status: 'completed',
        groupVehicleCount: 2,
        groupPosition: 1,
      );
      final fetchedB = _task(
        bookingCode: 'TM-00231',
        status: 'completed',
        groupVehicleCount: 2,
        groupPosition: 2,
      );

      expect(resolveFetchedTask(previous: null, fetched: fetchedA), isNull);
      expect(resolveFetchedTask(previous: null, fetched: fetchedB), isNull);
    });

    test('keeps an active grouped booking unaffected', () {
      final fetched = _task(
        bookingCode: 'TM-00230',
        status: 'arrived_dropoff',
        groupVehicleCount: 2,
        groupPosition: 1,
      );

      final result = resolveFetchedTask(previous: null, fetched: fetched);

      expect(result, same(fetched));
    });

    test('shows the completion screen immediately when the same booking transitions while tracked', () {
      final previous = _task(bookingCode: 'TM-00100', status: 'waiting_verification');
      final fetched = _task(bookingCode: 'TM-00100', status: 'completed');

      final result = resolveFetchedTask(previous: previous, fetched: fetched);

      expect(result, same(fetched));
    });

    test('excludes the same completed booking on a fresh fetch after tracking was reset', () {
      final fetched = _task(bookingCode: 'TM-00100', status: 'completed');

      final result = resolveFetchedTask(previous: null, fetched: fetched);

      expect(result, isNull);
    });

    test('adopts a newly assigned booking normally after the previous one completed', () {
      final previous = _task(bookingCode: 'TM-00100', status: 'completed');
      final fetched = _task(bookingCode: 'TM-00200', status: 'assigned');

      final result = resolveFetchedTask(previous: previous, fetched: fetched);

      expect(result, same(fetched));
    });

    test('excludes a returned booking fetched fresh with no prior tracking', () {
      final fetched = _task(bookingCode: 'TM-00100', status: 'returned');

      final result = resolveFetchedTask(previous: null, fetched: fetched);

      expect(result, isNull);
    });

    test('keeps null when there is no active or completed task', () {
      final result = resolveFetchedTask(previous: null, fetched: null);

      expect(result, isNull);
    });
  });

  group('TlActiveTaskShell widget', () {
    final binding = TestWidgetsFlutterBinding.ensureInitialized();
    binding.defaultBinaryMessenger.setMockMethodCallHandler(
      const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
      (call) async => null,
    );

    http.Response json(Object body, {int status = 200}) {
      return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
    }

    http.Client buildClient({Object? task, bool slow = false}) {
      return MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/v1/team-leader/task')) {
          if (slow) await Future<void>.delayed(const Duration(seconds: 2));
          return json({'success': true, 'data': task});
        }
        if (path.endsWith('/v1/team-leader/presence/ping') ||
            path.endsWith('/v1/team-leader/presence/away')) {
          return json({'success': true});
        }
        return json({'success': false}, status: 404);
      });
    }

    Future<void> settle(WidgetTester tester) async {
      for (var i = 0; i < 10; i++) {
        await tester.pump(const Duration(milliseconds: 50));
      }
    }

    Future<void> pumpShell(
      WidgetTester tester, {
      Object? task,
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
                  return MaterialPageRoute(builder: (_) => const TlActiveTaskShell());
                }
                onNavigate?.call(settings.name!, settings.arguments);
                return MaterialPageRoute(builder: (_) => const Scaffold());
              },
            ),
          );
          await settle(tester);
        },
        () => buildClient(task: task),
      );
    }

    void tlTest(String description, Future<void> Function(WidgetTester) body) {
      testWidgets(description, (tester) async {
        try {
          await body(tester);
        } finally {
          TlPresenceController.stop();
        }
      });
    }

    tlTest('shows a loading skeleton, not a spinner, while resolving the current task', (tester) async {
      SharedPreferences.setMockInitialValues({'auth_token': 'tl-token'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: TlActiveTaskShell()));
          await tester.pump();
        },
        () => buildClient(slow: true),
      );

      expect(find.byType(SkeletonBox), findsWidgets);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(tester.takeException(), isNull);

      await tester.pump(const Duration(seconds: 3));
    });

    tlTest('shows the no-active-task state with the real bottom navigation', (tester) async {
      await pumpShell(tester);

      expect(find.text('No active task found.'), findsOneWidget);
      expect(find.byType(TlBottomNav), findsOneWidget);
    });

    tlTest('tapping Back to Home navigates to the exact existing /tl-home route', (tester) async {
      String? capturedRoute;
      await pumpShell(tester, onNavigate: (route, args) => capturedRoute = route);

      await tester.tap(find.text('Back to Home'));
      await settle(tester);

      expect(capturedRoute, '/tl-home');
    });

    tlTest('tapping Profile from the no-active-task state navigates to the exact existing route', (tester) async {
      String? capturedRoute;
      await pumpShell(tester, onNavigate: (route, args) => capturedRoute = route);

      await tester.tap(find.text('Profile'));
      await settle(tester);

      expect(capturedRoute, '/tl-profile');
    });

    tlTest('renders the no-active-task state correctly in light mode', (tester) async {
      await pumpShell(tester, theme: AppTheme.light, themeMode: ThemeMode.light);

      expect(tester.takeException(), isNull);
      expect(find.text('No active task found.'), findsOneWidget);
    });

    tlTest('renders the no-active-task state correctly in dark mode', (tester) async {
      await pumpShell(tester, theme: AppTheme.dark, themeMode: ThemeMode.dark);

      expect(tester.takeException(), isNull);
      expect(find.text('No active task found.'), findsOneWidget);
    });

    for (final width in [320.0, 340.0, 360.0, 375.0, 390.0, 412.0]) {
      tlTest('renders the no-active-task state without horizontal overflow at $width px width', (tester) async {
        final originalSize = tester.view.physicalSize;
        final originalRatio = tester.view.devicePixelRatio;
        tester.view.physicalSize = Size(width, 800);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(() {
          tester.view.physicalSize = originalSize;
          tester.view.devicePixelRatio = originalRatio;
        });

        await pumpShell(tester);

        expect(tester.takeException(), isNull);
      });
    }
  });
}
