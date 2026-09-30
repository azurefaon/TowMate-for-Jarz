import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/status_style.dart';
import 'package:towmate_app/core/tl_task_flow.dart';
import 'package:towmate_app/screens/team_leader/tl_active_task_shell.dart';
import 'package:towmate_app/screens/team_leader/tl_home_screen.dart';
import 'package:towmate_app/services/tl_presence_controller.dart';
import 'package:towmate_app/widgets/tl_assigned_team_card.dart';
import 'package:towmate_app/widgets/tl_status_timeline.dart';
import 'package:towmate_app/models/task_model.dart';

Map<String, dynamic> _task(String status, {Map<String, dynamic>? team}) => {
      'id': 900,
      'booking_code': 'TM-0900',
      'status': status,
      'pickup_address': 'Pasay City',
      'dropoff_address': 'Makati City',
      'pickup_lat': 14.5,
      'pickup_lng': 121.0,
      'dropoff_lat': 14.6,
      'dropoff_lng': 121.1,
      'distance_km': 9.5,
      'customer_name': 'Juan Dela Cruz',
      'customer_phone': '09170000000',
      'customer_email': 'juan@example.test',
      'final_total': 1850.0,
      'truck_type_name': 'Light Duty',
      'service_type': 'book_now',
      'payment_method': status == 'waiting_verification' ? 'cash' : null,
      'assigned_team': team ??
          {
            'team_leader_name': 'Leader One',
            'driver_name': 'Pedro Santos',
            'crew_names': ['Maria Reyes', 'Jose Ramirez'],
            'unit_name': 'Demo Unit 01',
            'plate_number': 'DEMO 0001',
            'truck_type_name': 'Heavy Duty',
            'truck_class': 'heavy',
          },
    };

http.Response _json(Object? body, {int status = 200}) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  for (final ch in [
    'plugins.it_nomads.com/flutter_secure_storage',
  ]) {
    binding.defaultBinaryMessenger.setMockMethodCallHandler(MethodChannel(ch), (c) async => null);
  }
  for (final ch in ['flutter.baseflow.com/geolocator', 'flutter.baseflow.com/geolocator_android']) {
    binding.defaultBinaryMessenger.setMockMethodCallHandler(
      MethodChannel(ch),
      (c) async => c.method == 'checkPermission' ? 2 : null,
    );
  }

  Future<void> settle(WidgetTester t) async {
    for (var i = 0; i < 12; i++) {
      await t.pump(const Duration(milliseconds: 50));
    }
  }

  Object? Function()? taskFn;
  var acceptOk = true;

  // One client for the whole test body so fetches triggered after a tap
  // (new route -> new fetch) are served by the same mock.
  final client = MockClient((r) async {
    final p = r.url.path;
    if (p.endsWith('/accept')) {
      return acceptOk
          ? _json({'success': true, 'data': taskFn?.call()})
          : _json({'success': false, 'message': 'no'}, status: 409);
    }
    if (p.endsWith('/status') || p.endsWith('/v1/team-leader/task')) {
      return _json({'success': true, 'data': taskFn?.call()});
    }
    if (p.contains('/presence/') || p.endsWith('/location')) return _json({'success': true});
    return _json({'success': false}, status: 404);
  });

  Future<List<String>> pumpApp(
    WidgetTester t, {
    required String initial,
    Object? Function()? task,
  }) async {
    final visited = <String>[];
    taskFn = task;
    acceptOk = true;
    t.view.physicalSize = const Size(400, 1600);
    t.view.devicePixelRatio = 1.0;
    addTearDown(t.view.reset);
    SharedPreferences.setMockInitialValues({'auth_token': 'tl-token', 'user_name': 'Ariel', 'duty_class': 'heavy'});
    await t.pumpWidget(MaterialApp(
      initialRoute: initial,
      onGenerateRoute: (s) {
        visited.add(s.name ?? '');
        switch (s.name) {
          case '/tl-home':
            return MaterialPageRoute(settings: s, builder: (_) => const TlHomeScreen());
          case '/tl-active-task':
            return MaterialPageRoute(settings: s, builder: (_) => const TlActiveTaskShell());
          default:
            return MaterialPageRoute(settings: s, builder: (_) => const Scaffold());
        }
      },
    ));
    await settle(t);
    return visited;
  }

  void tlTest(String name, Future<void> Function(WidgetTester) body) {
    testWidgets(name, (t) async {
      try {
        await http.runWithClient(() => body(t), () => client);
      } finally {
        TlPresenceController.stop();
      }
    });
  }

  group('status -> step mapping (single source of truth)', () {
    const expected = {
      'accepted': 1, 'on_the_way': 1,
      'arrived_pickup': 2, 'in_progress': 2, 'loading_vehicle': 2,
      'on_job': 3, 'arrived_dropoff': 4, 'waiting_verification': 5, 'completed': 6,
    };
    for (final e in expected.entries) {
      test('${e.key} -> step ${e.value}', () => expect(TlTaskFlow.stepFor(e.key), e.value));
    }
    test('assigned / returned / unknown have no step', () {
      expect(TlTaskFlow.stepFor('assigned'), isNull);
      expect(TlTaskFlow.stepFor('returned'), isNull);
      expect(TlTaskFlow.stepFor('bogus'), isNull);
      expect(TlTaskFlow.isOperational('assigned'), isFalse);
      expect(TlTaskFlow.isOperational('on_job'), isTrue);
    });
    testWidgets('timeline renders nothing for assigned, N / 6 otherwise', (t) async {
      await t.pumpWidget(const MaterialApp(home: Scaffold(body: TlStatusTimeline(currentStatus: 'assigned'))));
      expect(find.textContaining('/ 6'), findsNothing);
      await t.pumpWidget(const MaterialApp(home: Scaffold(body: TlStatusTimeline(currentStatus: 'on_job'))));
      expect(find.text('3 / 6'), findsOneWidget);
    });
  });

  group('status chips', () {
    double lum(Color c) {
      double f(double v) => v <= 0.03928 ? v / 12.92 : math.pow((v + 0.055) / 1.055, 2.4).toDouble();
      return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
    }

    double contrast(Color a, Color b) {
      final l1 = lum(a), l2 = lum(b);
      return (l1 > l2 ? l1 + 0.05 : l2 + 0.05) / (l1 > l2 ? l2 + 0.05 : l1 + 0.05);
    }

    for (final s in ['assigned', 'accepted', 'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle',
      'on_job', 'arrived_dropoff', 'waiting_verification', 'completed', 'returned']) {
      test('$s chip is solid and readable', () {
        final st = StatusStyle.of(s);
        expect(st.background.a, 1.0);
        expect(contrast(st.background, st.foreground), greaterThanOrEqualTo(4.5));
      });
    }
    test('assigned is the amber chip with dark text; returned is red', () {
      expect(StatusStyle.of('assigned').foreground.toARGB32(), 0xFF171717);
      expect(StatusStyle.of('returned').background.toARGB32(), 0xFFB91C1C);
    });
  });

  group('My Task truthfulness', () {
    tlTest('assigned: Home offers Accept Task; My Task never shows 1 / 6', (t) async {
      final visited = await pumpApp(t, initial: '/tl-home', task: () => _task('assigned'));
      expect(find.text('Assigned'), findsOneWidget);
      expect(find.textContaining('Accept'), findsWidgets);

      await t.tap(find.text('My Task'));
      await settle(t);

      expect(visited, isNot(contains('/tl-active-task')), reason: 'Home resolves My Task before navigating');
      expect(find.text('1 / 6'), findsNothing);
      expect(find.text('Step 1 of 6'), findsNothing);
      expect(find.byType(TlHomeScreen), findsOneWidget);
      expect(find.textContaining('Accept'), findsWidgets);
    });

    tlTest('deep link / refresh into active-task while assigned resolves to Home', (t) async {
      await pumpApp(t, initial: '/tl-active-task', task: () => _task('assigned'));
      expect(find.text('1 / 6'), findsNothing);
      expect(find.byType(TlActiveTaskShell), findsNothing);
      expect(find.byType(TlHomeScreen), findsOneWidget);
    });

    tlTest('successful Accept Task enters step 1 of the operational flow', (t) async {
      var status = 'assigned';
      await pumpApp(t, initial: '/tl-home', task: () => _task(status));
      status = 'on_the_way';
      await t.tap(find.textContaining('Accept').first);
      await settle(t);

      expect(find.byType(TlActiveTaskShell), findsOneWidget);
      expect(find.text('1 / 6'), findsOneWidget);
    });

    tlTest('no active task: My Task shows the empty state, no stale screen', (t) async {
      await pumpApp(t, initial: '/tl-active-task', task: () => null);
      expect(find.text('No active task found.'), findsOneWidget);
      expect(find.textContaining('/ 6'), findsNothing);
    });

    tlTest('a completed task fetched fresh is not shown afterwards', (t) async {
      await pumpApp(t, initial: '/tl-active-task', task: () => _task('completed'));
      expect(find.text('No active task found.'), findsOneWidget);
    });

    const screens = {
      'accepted': '1 / 6',
      'on_the_way': '1 / 6',
      'arrived_pickup': '2 / 6',
      'in_progress': '2 / 6',
      'loading_vehicle': '2 / 6',
      'on_job': '3 / 6',
      'arrived_dropoff': '4 / 6',
      'waiting_verification': '5 / 6',
    };
    for (final e in screens.entries) {
      tlTest('backend status ${e.key} opens the shell at ${e.value}', (t) async {
        await pumpApp(t, initial: '/tl-active-task', task: () => _task(e.key));
        expect(find.byType(TlActiveTaskShell), findsOneWidget);
        expect(find.text(e.value), findsOneWidget);
      });
    }
  });

  group('regressions preserved', () {
    tlTest('Assigned Team card keeps driver/crew/truck and has no Classification row', (t) async {
      await pumpApp(t, initial: '/tl-active-task', task: () => _task('on_the_way'));
      expect(find.byType(TlAssignedTeamCard), findsOneWidget);
      for (final s in ['Pedro Santos', 'Maria Reyes, Jose Ramirez', 'Demo Unit 01', 'DEMO 0001', 'Heavy Duty']) {
        expect(find.text(s), findsWidgets, reason: s);
      }
      expect(find.text('Classification'), findsNothing);
    });

    tlTest('shell has no Drawer/hamburger and keeps Task/Navigate/Emergency', (t) async {
      await pumpApp(t, initial: '/tl-active-task', task: () => _task('on_the_way'));
      expect(find.byType(Drawer), findsNothing);
      expect(find.byIcon(Icons.menu_rounded), findsNothing);
      for (final s in ['Task', 'Navigate', 'Emergency']) {
        expect(find.text(s), findsOneWidget);
      }
    });

    test('TaskModel parses assigned status without an operational step', () {
      final m = TaskModel.fromJson(_task('assigned'));
      expect(TlTaskFlow.stepFor(m.status), isNull);
    });
  });
}
