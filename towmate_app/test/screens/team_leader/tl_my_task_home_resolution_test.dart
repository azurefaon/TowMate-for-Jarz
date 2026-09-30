import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/team_leader/tl_active_task_shell.dart';
import 'package:towmate_app/screens/team_leader/tl_home_screen.dart';
import 'package:towmate_app/services/tl_presence_controller.dart';
import 'package:towmate_app/widgets/tl_assigned_team_card.dart';

Map<String, dynamic> _task(String status) => {
      'id': 311,
      'booking_code': '0000311',
      'status': status,
      'pickup_address': 'Pasay City',
      'dropoff_address': 'Makati City',
      'distance_km': 9.5,
      'customer_name': 'Juan Dela Cruz',
      'customer_phone': '09170000000',
      'final_total': 1850.0,
      'truck_type_name': 'Heavy Duty',
      'service_type': 'book_now',
      'assigned_team': {
        'team_leader_name': 'Demo Team Leader',
        'driver_name': 'Pedro Santos',
        'crew_names': ['Maria Reyes', 'Jose Ramirez'],
        'unit_name': 'Demo Unit 01',
        'plate_number': 'DEMO 0001',
        'truck_type_name': 'Heavy Duty',
        'truck_class': 'heavy',
      },
    };

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (c) async => null,
  );
  for (final ch in ['flutter.baseflow.com/geolocator', 'flutter.baseflow.com/geolocator_android']) {
    binding.defaultBinaryMessenger.setMockMethodCallHandler(
      MethodChannel(ch),
      (c) async => c.method == 'checkPermission' ? 2 : null,
    );
  }

  final requests = <String>[];
  Object? Function() backend = () => null;
  var taskStatusCode = 200;
  var latency = const Duration(milliseconds: 150);

  http.Response json(Object? body, [int code = 200]) =>
      http.Response(jsonEncode(body), code, headers: {'content-type': 'application/json'});

  final client = MockClient((r) async {
    requests.add('${r.method} ${r.url.path}');
    await Future<void>.delayed(latency);
    if (r.url.path.endsWith('/v1/team-leader/task') && r.method == 'GET') {
      return taskStatusCode == 200
          ? json({'success': true, 'data': backend()})
          : json({'success': false}, taskStatusCode);
    }
    return json({'success': true, 'data': backend()});
  });

  Iterable<String> taskGets() => requests.where((r) => r == 'GET /api/v1/team-leader/task');
  Iterable<String> mutations() => requests.where((r) =>
      r.contains('/accept') ||
      r.contains('/status') ||
      r.contains('/claim-next') ||
      r.contains('/return') ||
      r.contains('/complete'));

  Future<void> run(WidgetTester t, Duration total, {int steps = 50}) async {
    final step = Duration(microseconds: total.inMicroseconds ~/ steps);
    for (var i = 0; i < steps; i++) {
      await t.pump(step);
    }
  }

  void bigView(WidgetTester t) {
    t.view.physicalSize = const Size(400, 1600);
    t.view.devicePixelRatio = 1.0;
    addTearDown(t.view.reset);
  }

  /// Real-shaped app: fade PageRouteBuilder routes, real Home + shell.
  Future<List<String>> pumpApp(WidgetTester t, String initial) async {
    requests.clear();
    taskStatusCode = 200;
    latency = const Duration(milliseconds: 150);
    final pushed = <String>[];
    bigView(t);
    SharedPreferences.setMockInitialValues(
      {'auth_token': 'tl-token', 'user_name': 'Demo Team Leader', 'duty_class': 'heavy'},
    );
    await t.pumpWidget(MaterialApp(
      initialRoute: initial,
      onGenerateRoute: (s) {
        pushed.add(s.name ?? '');
        final Widget page = switch (s.name) {
          '/tl-home' => const TlHomeScreen(),
          '/tl-active-task' => const TlActiveTaskShell(),
          _ => const Scaffold(),
        };
        return PageRouteBuilder(
          settings: s,
          pageBuilder: (_, _, _) => page,
          transitionsBuilder: (_, a, _, c) => FadeTransition(opacity: a, child: c),
          transitionDuration: const Duration(milliseconds: 250),
        );
      },
    ));
    await run(t, const Duration(seconds: 2));
    return pushed;
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

  void expectAssignedHome() {
    expect(find.byType(TlHomeScreen), findsOneWidget, reason: 'exactly one Home, no stacking');
    expect(find.text('Accept Task'), findsOneWidget);
    expect(find.text('Assigned'), findsOneWidget);
    expect(find.byType(TlAssignedTeamCard), findsOneWidget);
    expect(find.text('Pedro Santos'), findsOneWidget);
    expect(find.byType(CircularProgressIndicator), findsNothing, reason: 'no stuck spinner');
    expect(find.byKey(const Key('tl_home_task_skeleton')), findsNothing);
    expect(find.textContaining('/ 6'), findsNothing);
  }

  group('manual sequence: assigned booking -> My Task', () {
    tlTest('fresh login with an assigned task shows assignment + Accept Task', (t) async {
      backend = () => _task('assigned');
      await pumpApp(t, '/tl-home');
      expectAssignedHome();
    });

    tlTest('tap My Task keeps the SAME assigned card, no 1/6, no spinner, read-only', (t) async {
      backend = () => _task('assigned');
      final pushed = await pumpApp(t, '/tl-home');
      final before = pushed.length;

      await t.tap(find.text('My Task'));
      await run(t, const Duration(seconds: 2));

      expectAssignedHome();
      expect(pushed.length, before, reason: 'Home must not be rebuilt/re-pushed');
      expect(pushed.contains('/tl-active-task'), isFalse);
      expect(mutations(), isEmpty, reason: 'opening My Task is read-only');
      expect(requests.where((r) => r.startsWith('POST') && !r.contains('presence')), isEmpty);
      expect(requests.where((r) => r.startsWith('PATCH') || r.startsWith('PUT')), isEmpty);
    });

    tlTest('assigned known on Home: My Task is instant and makes ZERO extra requests', (t) async {
      backend = () => _task('assigned');
      await pumpApp(t, '/tl-home');
      final before = List<String>.of(requests);
      latency = const Duration(seconds: 30); // any request would visibly stall

      await t.tap(find.text('My Task'));
      await t.pump(); // a single frame: nothing async is awaited

      expect(requests, before, reason: 'no lookup, no request of any kind');
      expect(find.text('Accept Task'), findsOneWidget, reason: 'card still mounted on the very next frame');
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(find.byKey(const Key('tl_home_task_skeleton')), findsNothing);
    });

    tlTest('repeated My Task taps stay instant: no requests, no stacking, card stays', (t) async {
      backend = () => _task('assigned');
      final pushed = await pumpApp(t, '/tl-home');
      final before = List<String>.of(requests);
      final pushes = pushed.length;

      for (var i = 0; i < 6; i++) {
        await t.tap(find.text('My Task'));
        await t.pump(const Duration(milliseconds: 20));
      }
      await run(t, const Duration(seconds: 2));

      expectAssignedHome();
      expect(taskGets().length, before.where((r) => r == 'GET /api/v1/team-leader/task').length,
          reason: 'no duplicate task GETs');
      expect(pushed.length, pushes);
      expect(mutations(), isEmpty);
    });

    tlTest('browser refresh on Home: assigned card returns', (t) async {
      backend = () => _task('assigned');
      await pumpApp(t, '/tl-home');
      await t.pumpWidget(const SizedBox());
      await pumpApp(t, '/tl-home');
      expectAssignedHome();
    });

    tlTest('direct /tl-active-task while assigned resolves to Home with the card', (t) async {
      backend = () => _task('assigned');
      await pumpApp(t, '/tl-active-task');
      await run(t, const Duration(seconds: 2));
      expect(find.byType(TlActiveTaskShell), findsNothing);
      expectAssignedHome();
      expect(mutations(), isEmpty);
    });

    tlTest('Home shows a placeholder, not a blank gap, during the first fetch', (t) async {
      backend = () => _task('assigned');
      latency = const Duration(seconds: 1);
      SharedPreferences.setMockInitialValues({'auth_token': 'x', 'user_name': 'Demo'});
      bigView(t);
      await t.pumpWidget(const MaterialApp(home: TlHomeScreen()));
      await t.pump(const Duration(milliseconds: 300));
      expect(find.byKey(const Key('tl_home_task_skeleton')), findsOneWidget);
      await run(t, const Duration(seconds: 3));
      expect(find.byKey(const Key('tl_home_task_skeleton')), findsNothing);
      expect(find.text('Accept Task'), findsOneWidget);
    });
  });

  group('other My Task outcomes', () {
    tlTest('accept still works and opens the active shell at 1 / 6', (t) async {
      var status = 'assigned';
      backend = () => _task(status);
      await pumpApp(t, '/tl-home');
      status = 'on_the_way';
      await t.tap(find.text('Accept Task'));
      await run(t, const Duration(seconds: 3));
      expect(find.byType(TlActiveTaskShell), findsOneWidget);
      expect(find.text('1 / 6'), findsOneWidget);
    });

    tlTest('accept -> operational: the active flow opens at the backend step', (t) async {
      var status = 'assigned';
      backend = () => _task(status);
      await pumpApp(t, '/tl-home');

      status = 'on_job'; // what the backend reports after the TL worked the task
      await t.tap(find.text('Accept Task'));
      await run(t, const Duration(seconds: 3));

      expect(find.byType(TlActiveTaskShell), findsOneWidget);
      expect(find.text('3 / 6'), findsOneWidget);
      expect(find.byType(TlHomeScreen), findsNothing);
    });

    tlTest('Home load that finds an operational task opens the shell at the backend step', (t) async {
      // Home's first load is still failing/unknown; the tap performs exactly
      // one lookup and follows the backend.
      backend = () => _task('on_job');
      taskStatusCode = 200;
      requests.clear();
      SharedPreferences.setMockInitialValues({'auth_token': 'x', 'user_name': 'Demo'});
      bigView(t);
      latency = const Duration(milliseconds: 150);
      await t.pumpWidget(MaterialApp(
        initialRoute: '/tl-home',
        onGenerateRoute: (s) => MaterialPageRoute(
          settings: s,
          builder: (_) => s.name == '/tl-active-task'
              ? const TlActiveTaskShell()
              : s.name == '/tl-home'
                  ? const TlHomeScreen()
                  : const Scaffold(),
        ),
      ));
      await run(t, const Duration(seconds: 3));
      // Home already redirected to the operational shell from its own load.
      expect(find.byType(TlActiveTaskShell), findsOneWidget);
      expect(find.text('3 / 6'), findsOneWidget);
    });

    tlTest('no task: My Task shows the empty state and loading terminates', (t) async {
      backend = () => null;
      await pumpApp(t, '/tl-home');
      expect(find.text('Accept Task'), findsNothing);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      await t.tap(find.text('My Task'));
      await run(t, const Duration(seconds: 2));
      expect(find.text('No active task found.'), findsOneWidget);
      expect(find.textContaining('/ 6'), findsNothing);
    });

    tlTest('request failure is NOT "no task": Home unknown + failing lookup stays on Home', (t) async {
      backend = () => _task('assigned');
      taskStatusCode = 500;
      requests.clear();
      SharedPreferences.setMockInitialValues({'auth_token': 'x', 'user_name': 'Demo'});
      bigView(t);
      final pushed = <String>[];
      await t.pumpWidget(MaterialApp(
        initialRoute: '/tl-home',
        onGenerateRoute: (s) {
          pushed.add(s.name ?? '');
          return MaterialPageRoute(
            settings: s,
            builder: (_) => s.name == '/tl-active-task'
                ? const TlActiveTaskShell()
                : s.name == '/tl-home'
                    ? const TlHomeScreen()
                    : const Scaffold(),
          );
        },
      ));
      // Initial load exhausts its 3 attempts and fails: state stays UNKNOWN.
      await run(t, const Duration(seconds: 12), steps: 120);
      expect(find.byType(CircularProgressIndicator), findsNothing);

      await t.tap(find.text('My Task'));
      await run(t, const Duration(seconds: 6), steps: 120); // 3 attempts, 2s apart

      expect(pushed, isNot(contains('/tl-active-task')), reason: 'a failure must not be treated as no task');
      expect(find.byType(TlActiveTaskShell), findsNothing);
      expect(find.text('No active task found.'), findsNothing);
      expect(find.text("Couldn't check your task. Please try again."), findsOneWidget);
      expect(mutations(), isEmpty);
    });

    tlTest('shell: a failed load shows an error + Retry, never "No active task found."', (t) async {
      backend = () => _task('on_the_way');
      await pumpApp(t, '/tl-home');
      taskStatusCode = 500;
      await t.pumpWidget(const SizedBox());
      requests.clear();
      SharedPreferences.setMockInitialValues({'auth_token': 'x', 'user_name': 'Demo'});
      bigView(t);
      await t.pumpWidget(const MaterialApp(home: TlActiveTaskShell()));
      await run(t, const Duration(seconds: 12), steps: 120);

      expect(find.byKey(const Key('tl_shell_load_error')), findsOneWidget);
      expect(find.text('No active task found.'), findsNothing);

      taskStatusCode = 200;
      await t.tap(find.text('Retry'));
      await run(t, const Duration(seconds: 2));
      expect(find.byKey(const Key('tl_shell_load_error')), findsNothing);
      expect(find.text('1 / 6'), findsOneWidget);
    });

    tlTest('API failure on first load ends loading with no stuck skeleton', (t) async {
      backend = () => _task('assigned');
      taskStatusCode = 500;
      requests.clear();
      SharedPreferences.setMockInitialValues({'auth_token': 'x', 'user_name': 'Demo'});
      bigView(t);
      await t.pumpWidget(const MaterialApp(home: TlHomeScreen()));
      await run(t, const Duration(seconds: 12), steps: 120);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(find.byKey(const Key('tl_home_task_skeleton')), findsNothing);
    });
  });
}
