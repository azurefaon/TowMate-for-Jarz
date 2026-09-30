import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/models/task_model.dart';
import 'package:towmate_app/screens/team_leader/tl_active_task_shell.dart';
import 'package:towmate_app/services/team_leader_service.dart';
import 'package:towmate_app/services/tl_presence_controller.dart';
import 'package:towmate_app/widgets/tl_demo_simulator_button.dart';

Map<String, dynamic> _task(String status, {required bool demo}) => {
      'id': 311,
      'booking_code': '0000311',
      'status': status,
      'pickup_address': 'Quezon City',
      'dropoff_address': 'Makati City',
      'pickup_lat': 14.676,
      'pickup_lng': 121.0437,
      'dropoff_lat': 14.5547,
      'dropoff_lng': 121.0244,
      'distance_km': 8.4,
      'customer_name': 'Juan Dela Cruz',
      'customer_phone': '09171234567',
      'final_total': 6518.4,
      'truck_type_name': 'Heavy Duty',
      'service_type': 'book_now',
      'demo_arrival_available': demo,
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

  // Mock "backend": status only changes when the demo endpoint (or the normal
  // accepted -> on_the_way call) is hit, never because the UI says so.
  var status = 'on_the_way';
  var demo = true;
  var demoEndpointCode = 200;
  var refetchFails = false;
  final log = <String>[];
  final bodies = <String, String>{};

  http.Response json(Object b, [int code = 200]) =>
      http.Response(jsonEncode(b), code, headers: {'content-type': 'application/json'});

  final client = MockClient((r) async {
    final key = '${r.method} ${r.url.path}';
    log.add(key);
    bodies[key] = r.body;
    final p = r.url.path;
    if (p.endsWith('/simulate-arrival')) {
      if (demoEndpointCode == 200) {
        status = status == 'on_job' ? 'arrived_dropoff' : 'arrived_pickup';
        return json({'success': true, 'status': status});
      }
      return json({'success': false, 'message': demoEndpointCode == 404 ? 'Not found.' : 'Arrival cannot be simulated.'}, demoEndpointCode);
    }
    if (p.contains('/status') && r.method == 'PATCH') {
      final wanted = (jsonDecode(r.body) as Map)['status'] as String;
      if (status == 'accepted' && wanted == 'on_the_way') status = 'on_the_way';
      return json({'success': true, 'data': _task(status, demo: demo)});
    }
    if (p.endsWith('/v1/team-leader/task') && r.method == 'GET') {
      if (refetchFails && log.any((e) => e.endsWith('/simulate-arrival'))) return json({'success': false}, 500);
      return json({'success': true, 'data': _task(status, demo: demo)});
    }
    return json({'success': true});
  });

  Future<void> pump(WidgetTester t, String initialStatus, {required bool showDemo}) async {
    status = initialStatus;
    demo = showDemo;
    demoEndpointCode = 200;
    refetchFails = false;
    log.clear();
    bodies.clear();
    t.view.physicalSize = const Size(400, 1800);
    t.view.devicePixelRatio = 1.0;
    addTearDown(t.view.reset);
    SharedPreferences.setMockInitialValues({'auth_token': 'x', 'user_name': 'Demo'});
    await t.pumpWidget(const MaterialApp(home: TlActiveTaskShell()));
    for (var i = 0; i < 12; i++) {
      await t.pump(const Duration(milliseconds: 50));
    }
  }

  Future<void> settle(WidgetTester t, [int n = 20]) async {
    for (var i = 0; i < n; i++) {
      await t.pump(const Duration(milliseconds: 100));
    }
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

  group('model', () {
    test('demo_arrival_available defaults to false and parses true', () {
      expect(TaskModel.fromJson({'id': 1, 'booking_code': 'X', 'status': 'assigned'}).demoArrivalAvailable, isFalse);
      expect(TaskModel.fromJson(_task('on_the_way', demo: true)).demoArrivalAvailable, isTrue);
      expect(TaskModel.fromJson(_task('on_the_way', demo: false)).demoArrivalAvailable, isFalse);
      expect(TaskModel.fromJson(_task('on_the_way', demo: true)).copyWith(status: 'accepted').demoArrivalAvailable, isTrue);
    });
  });

  group('normal status endpoint', () {
    tlTest('a status update sends only status/lat/lng - never a demo or bypass flag', (t) async {
      log.clear();
      bodies.clear();
      SharedPreferences.setMockInitialValues({'auth_token': 'x'});
      status = 'on_the_way';

      await TeamLeaderService.updateStatus('0000311', 'arrived_pickup', lat: 14.676, lng: 121.0437);

      final key = log.firstWhere((e) => e.startsWith('PATCH'));
      final sent = jsonDecode(bodies[key]!) as Map<String, dynamic>;
      expect(sent.keys.toSet(), {'status', 'lat', 'lng'});
      for (final bad in ['is_demo', 'demo', 'skip_location', 'force']) {
        expect(sent.containsKey(bad), isFalse, reason: bad);
      }
    });
  });

  group('widget', () {
    testWidgets('is unmistakably labelled as a demo simulator', (t) async {
      await t.pumpWidget(MaterialApp(home: Scaffold(body: TlDemoSimulatorButton(label: 'Simulate Arrival at Pickup', onPressed: () {}))));
      expect(find.text('DEMO ONLY'), findsOneWidget);
      expect(find.text('Simulate Arrival at Pickup'), findsOneWidget);
      expect(find.textContaining('skips the GPS check'), findsOneWidget);
    });
  });

  group('pickup (En Route)', () {
    tlTest('an ordinary TL never sees the simulator', (t) async {
      await pump(t, 'on_the_way', showDemo: false);
      expect(find.text('Arrived at Pickup'), findsOneWidget);
      expect(find.byType(TlDemoSimulatorButton), findsNothing);
      expect(find.text('DEMO ONLY'), findsNothing);
    });

    tlTest('the demo fixture sees it beside (not instead of) the real button', (t) async {
      await pump(t, 'on_the_way', showDemo: true);
      expect(find.text('Arrived at Pickup'), findsOneWidget, reason: 'real yellow button kept');
      expect(find.text('DEMO ONLY'), findsOneWidget);
      expect(find.text('Simulate Arrival at Pickup'), findsOneWidget);
    });

    tlTest('tap calls the dedicated endpoint (no flags), refetches, and advances from BACKEND state', (t) async {
      await pump(t, 'on_the_way', showDemo: true);
      expect(find.text('1 / 6'), findsOneWidget);

      await t.ensureVisible(find.text('Simulate Arrival at Pickup'));
      await t.tap(find.text('Simulate Arrival at Pickup'));
      await settle(t);

      expect(log.where((e) => e.endsWith('/demo/task/0000311/simulate-arrival')), hasLength(1));
      expect(bodies['POST /api/v1/team-leader/demo/task/0000311/simulate-arrival'], anyOf(isNull, isEmpty),
          reason: 'no status / flag / coordinates in the request');
      expect(log.where((e) => e.startsWith('PATCH')), isEmpty, reason: 'normal status endpoint untouched');
      // The refetch came AFTER the demo call.
      expect(log.lastIndexWhere((e) => e == 'GET /api/v1/team-leader/task'),
          greaterThan(log.indexWhere((e) => e.endsWith('/simulate-arrival'))));
      expect(find.text('2 / 6'), findsOneWidget);
    });

    tlTest('from accepted it first makes the normal on_the_way transition, then simulates', (t) async {
      await pump(t, 'accepted', showDemo: true);

      await t.ensureVisible(find.text('Simulate Arrival at Pickup'));
      await t.tap(find.text('Simulate Arrival at Pickup'));
      await settle(t);

      final patch = log.indexWhere((e) => e.startsWith('PATCH'));
      final sim = log.indexWhere((e) => e.endsWith('/simulate-arrival'));
      expect(patch, greaterThan(-1));
      expect(sim, greaterThan(patch));
      expect((jsonDecode(bodies[log[patch]]!) as Map)['status'], 'on_the_way');
      expect(find.text('2 / 6'), findsOneWidget);
    });

    tlTest('a rejected simulation does NOT fake a transition', (t) async {
      await pump(t, 'on_the_way', showDemo: true);
      demoEndpointCode = 404; // e.g. production / non-demo
      final before = log.where((e) => e == 'GET /api/v1/team-leader/task').length;

      await t.ensureVisible(find.text('Simulate Arrival at Pickup'));
      await t.tap(find.text('Simulate Arrival at Pickup'));
      await settle(t);

      expect(find.text('1 / 6'), findsOneWidget, reason: 'still En Route');
      expect(find.text('2 / 6'), findsNothing);
      expect(find.text('Not found.'), findsOneWidget);
      expect(log.where((e) => e == 'GET /api/v1/team-leader/task').length, before,
          reason: 'no success -> no refetch/advance');
    });

    tlTest('success but failed refetch does not fake the next screen', (t) async {
      await pump(t, 'on_the_way', showDemo: true);
      refetchFails = true;

      await t.ensureVisible(find.text('Simulate Arrival at Pickup'));
      await t.tap(find.text('Simulate Arrival at Pickup'));
      await settle(t, 200); // getCurrentTask retries with 2s gaps

      expect(find.text('2 / 6'), findsNothing);
      expect(find.text('1 / 6'), findsOneWidget);

      // Drain any in-flight getCurrentTask retry (2s gaps) before teardown.
      await t.pumpWidget(const SizedBox());
      await t.pump(const Duration(seconds: 3));
      await t.pump(const Duration(seconds: 3));
    });
  });

  group('drop-off (Transporting)', () {
    tlTest('ordinary TL never sees it', (t) async {
      await pump(t, 'on_job', showDemo: false);
      expect(find.text('Arrived at Drop-off'), findsOneWidget);
      expect(find.byType(TlDemoSimulatorButton), findsNothing);
    });

    tlTest('demo fixture: button calls the dedicated action and moves to Drop-off from backend state', (t) async {
      await pump(t, 'on_job', showDemo: true);
      expect(find.text('3 / 6'), findsOneWidget);
      expect(find.text('Simulate Arrival at Drop-off'), findsOneWidget);

      await t.ensureVisible(find.text('Simulate Arrival at Drop-off'));
      await t.tap(find.text('Simulate Arrival at Drop-off'));
      await settle(t);

      expect(log.where((e) => e.endsWith('/simulate-arrival')), hasLength(1));
      expect(log.where((e) => e.startsWith('PATCH')), isEmpty);
      expect(find.text('4 / 6'), findsOneWidget);
    });

    tlTest('a rejected drop-off simulation leaves Transporting untouched', (t) async {
      await pump(t, 'on_job', showDemo: true);
      demoEndpointCode = 422;

      await t.ensureVisible(find.text('Simulate Arrival at Drop-off'));
      await t.tap(find.text('Simulate Arrival at Drop-off'));
      await settle(t);

      expect(find.text('3 / 6'), findsOneWidget);
      expect(find.text('4 / 6'), findsNothing);
      expect(find.text('Arrival cannot be simulated.'), findsOneWidget);
    });
  });
}
