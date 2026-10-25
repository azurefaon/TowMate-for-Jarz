import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/team_leader/tl_active_task_shell.dart';
import 'package:towmate_app/screens/team_leader/tl_navigate_screen.dart';
import 'package:towmate_app/services/tl_navigation_session.dart';
import 'package:towmate_app/services/tl_presence_controller.dart';

import '../../services/tl_navigation_session_test.dart' show FakeNavigationDriver;

// Turn-by-turn navigation (and its voice guidance) only ever runs inside
// TlNavigateScreen, so "is TlNavigateScreen built" is the observable for
// "can guidance be speaking". Stops are observed through the fake driver.

Map<String, dynamic> _task(String status, {String code = 'TM-0800'}) => {
      'id': 800,
      'booking_code': code,
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
    };

http.Response _json(Object? body, {int status = 200}) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});

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

  late FakeNavigationDriver driver;
  late Map<String, dynamic> current;
  Map<String, dynamic>? offer;

  final client = MockClient((r) async {
    final p = r.url.path;
    if (p.endsWith('/v1/team-leader/task')) return _json({'success': true, 'data': current});
    if (p.endsWith('/next-offer')) return _json({'success': true, 'data': offer});
    if (p.contains('/presence/') || p.endsWith('/location')) return _json({'success': true});
    return _json({'success': false}, status: 404);
  });

  Future<void> settle(WidgetTester t) async {
    for (var i = 0; i < 12; i++) {
      await t.pump(const Duration(milliseconds: 50));
    }
  }

  Future<void> pumpShell(WidgetTester t) async {
    t.view.physicalSize = const Size(400, 1600);
    t.view.devicePixelRatio = 1.0;
    addTearDown(t.view.reset);
    SharedPreferences.setMockInitialValues({'auth_token': 'tl-token'});
    await t.pumpWidget(const MaterialApp(home: TlActiveTaskShell()));
    await settle(t);
  }

  // The shell polls the task every 20 seconds.
  Future<void> serverMovesTo(WidgetTester t, String status) async {
    current = _task(status);
    await t.pump(const Duration(seconds: 20));
    await settle(t);
  }

  Future<void> tapNavigateTab(WidgetTester t) async {
    await t.tap(find.text('Navigate'));
    await settle(t);
  }

  void tlTest(String name, Future<void> Function(WidgetTester) body) {
    testWidgets(name, (t) async {
      TlNavigationSession.resetForTest();
      offer = null;
      driver = FakeNavigationDriver(initialized: false, termsAccepted: true);
      TlNavigationSession.driver = driver;
      try {
        await http.runWithClient(() => body(t), () => client);
      } finally {
        TlPresenceController.stop();
        await t.pumpWidget(const SizedBox());
        TlNavigationSession.resetForTest();
      }
    });
  }

  group('A. task accepted', () {
    tlTest('accepting a task does not build navigation (no guidance, no voice)', (t) async {
      current = _task('accepted');
      await pumpShell(t);

      expect(find.text('Navigate'), findsOneWidget);
      expect(find.byType(TlNavigateScreen), findsNothing);
      expect(TlNavigationSession.hasOwner, isFalse);
    });

    tlTest('moving accepted -> on_the_way still does not start navigation', (t) async {
      current = _task('accepted');
      await pumpShell(t);
      await serverMovesTo(t, 'on_the_way');

      expect(find.byType(TlNavigateScreen), findsNothing);
    });

    tlTest('a fresh load ends guidance left running by a previous app run', (t) async {
      current = _task('accepted');
      await pumpShell(t);

      expect(driver.calls, containsAllInOrder(['isInitialized', 'areTermsAccepted', 'initialize', 'stop']));
    });
  });

  group('B/C. Navigate pressed and active navigation', () {
    tlTest('tapping Navigate builds exactly one navigation screen', (t) async {
      current = _task('on_the_way');
      await pumpShell(t);
      await tapNavigateTab(t);

      expect(find.byType(TlNavigateScreen), findsOneWidget);
      expect(TlNavigationSession.hasOwner, isTrue);
    });

    tlTest('the Navigate to Pickup button opens navigation', (t) async {
      current = _task('on_the_way');
      await pumpShell(t);
      final button = find.widgetWithText(ElevatedButton, 'Navigate to Pickup');
      expect(button, findsOneWidget);
      await t.ensureVisible(button);
      await t.tap(button);
      await settle(t);

      expect(find.byType(TlNavigateScreen), findsOneWidget);
    });

    tlTest('switching tabs and polling keep the same session (no duplicate start)', (t) async {
      current = _task('on_the_way');
      await pumpShell(t);
      driver.calls.clear(); // the fresh-load cleanup
      await tapNavigateTab(t);
      final state = t.state(find.byType(TlNavigateScreen));

      await t.tap(find.text('Task'));
      await settle(t);
      await serverMovesTo(t, 'on_the_way');
      await tapNavigateTab(t);

      expect(find.byType(TlNavigateScreen), findsOneWidget);
      expect(identical(t.state(find.byType(TlNavigateScreen)), state), isTrue);
      expect(driver.calls, isEmpty, reason: 'nothing stopped or re-created the session');
    });

    tlTest('background -> resume during an active trip keeps navigation, no restart', (t) async {
      current = _task('on_job');
      await pumpShell(t);
      await tapNavigateTab(t);
      final state = t.state(find.byType(TlNavigateScreen));
      final before = driver.calls.length;

      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await settle(t);

      expect(identical(t.state(find.byType(TlNavigateScreen)), state), isTrue);
      expect(driver.calls.length, before, reason: 'resume must not stop an open trip');
    });
  });

  group('D/E. Step 5 payment', () {
    tlTest('reaching Step 5 closes navigation and stops guidance', (t) async {
      current = _task('on_job');
      await pumpShell(t);
      await tapNavigateTab(t);
      expect(find.byType(TlNavigateScreen), findsOneWidget);
      driver.calls.clear();
      driver.initialized = true;

      await serverMovesTo(t, 'arrived_dropoff');
      await serverMovesTo(t, 'waiting_verification');

      expect(find.byType(TlNavigateScreen), findsNothing);
      expect(find.text('Navigate'), findsNothing);
      expect(TlNavigationSession.hasOwner, isFalse);
      expect(driver.calls, contains('stop'));
      expect(driver.initialized, isFalse);
    });

    tlTest('jumping straight from on_job to Step 5 also stops guidance', (t) async {
      current = _task('on_job');
      await pumpShell(t);
      await tapNavigateTab(t);
      driver.calls.clear();
      driver.initialized = true;

      await serverMovesTo(t, 'waiting_verification');

      expect(find.byType(TlNavigateScreen), findsNothing);
      expect(driver.calls, contains('stop'));
      expect(driver.initialized, isFalse);
    });

    tlTest('reopening the Payment step never starts navigation', (t) async {
      driver.initialized = true; // guidance left over from before the reopen
      current = _task('waiting_verification');
      await pumpShell(t);

      expect(find.byType(TlNavigateScreen), findsNothing);
      expect(find.text('Navigate'), findsNothing);
      expect(driver.initialized, isFalse);

      // Several polls (rebuilds) later it is still closed.
      await serverMovesTo(t, 'waiting_verification');
      await serverMovesTo(t, 'waiting_verification');
      expect(find.byType(TlNavigateScreen), findsNothing);
    });

    tlTest('background -> resume on the Payment step keeps navigation stopped', (t) async {
      current = _task('waiting_verification');
      await pumpShell(t);
      driver.calls.clear();
      driver.initialized = true; // e.g. a service-held session

      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await settle(t);

      expect(find.byType(TlNavigateScreen), findsNothing);
      expect(driver.calls, contains('stop'));
      expect(driver.initialized, isFalse);
    });
  });

  group('next-task offer (PR #19) and the next task', () {
    final nextOffer = <String, dynamic>{
      'id': 9,
      'status': 'offered',
      'seconds_left': 480,
      'current_booking_code': 'TM-0800',
      'booking': {
        'booking_code': 'TM-0801',
        'pickup_address': '12 Sample St',
        'tow_class': 'Light Duty',
        'is_scheduled': false,
      },
    };

    tlTest('Step 5 stops navigation and still shows the next-task offer with Accept', (t) async {
      offer = nextOffer;
      current = _task('on_job');
      await pumpShell(t);
      await tapNavigateTab(t);
      expect(find.byType(TlNavigateScreen), findsOneWidget);
      driver.calls.clear();
      driver.initialized = true;

      await serverMovesTo(t, 'waiting_verification');

      expect(find.byType(TlNavigateScreen), findsNothing);
      expect(driver.initialized, isFalse, reason: 'guidance stopped at Step 5');
      expect(find.byKey(const Key('nextTaskOfferCard')), findsOneWidget);
      expect(find.byKey(const Key('nextTaskAccept')), findsOneWidget);
    });

    tlTest('a different next task on the same leg does not inherit an open navigation', (t) async {
      current = _task('on_the_way');
      await pumpShell(t);
      await tapNavigateTab(t);
      expect(find.byType(TlNavigateScreen), findsOneWidget);
      driver.calls.clear();
      driver.initialized = true;

      // The poll jumps straight to the next booking, same pickup leg, no gap.
      current = _task('accepted', code: 'TM-0801');
      await t.pump(const Duration(seconds: 20));
      await settle(t);

      expect(find.byType(TlNavigateScreen), findsNothing);
      expect(driver.initialized, isFalse);
      expect(find.text('Navigate'), findsOneWidget);
    });

    tlTest('an accepted next task keeps navigation inactive until Navigate is pressed', (t) async {
      driver.initialized = true; // guidance left over from the previous task
      current = _task('accepted', code: 'TM-0801');
      await pumpShell(t);

      expect(find.byType(TlNavigateScreen), findsNothing);
      expect(driver.initialized, isFalse);

      await tapNavigateTab(t);
      expect(find.byType(TlNavigateScreen), findsOneWidget);
    });
  });

  group('legs', () {
    tlTest('arriving at pickup closes navigation and hides the Navigate tab', (t) async {
      current = _task('on_the_way');
      await pumpShell(t);
      await tapNavigateTab(t);

      await serverMovesTo(t, 'arrived_pickup');

      expect(find.byType(TlNavigateScreen), findsNothing);
      expect(find.text('Navigate'), findsNothing);
    });

    tlTest('the drop-off leg needs its own Navigate press', (t) async {
      current = _task('on_the_way');
      await pumpShell(t);
      await tapNavigateTab(t);

      await serverMovesTo(t, 'loading_vehicle');
      await serverMovesTo(t, 'on_job');

      expect(find.text('Navigate'), findsOneWidget);
      expect(find.byType(TlNavigateScreen), findsNothing);

      await tapNavigateTab(t);
      expect(find.byType(TlNavigateScreen), findsOneWidget);
    });
  });
}
