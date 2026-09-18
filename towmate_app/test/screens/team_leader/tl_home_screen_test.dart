import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/team_leader/tl_home_screen.dart';
import 'package:towmate_app/services/tl_presence_controller.dart';
import 'package:towmate_app/widgets/tl_bottom_nav.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

final _assignedTaskFixture = {
  'id': 501,
  'booking_code': 'TM-0501',
  'status': 'assigned',
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
  'truck_type_name': 'Heavy Duty',
  'service_type': 'book_now',
};

http.Client _buildClient({
  Object? task,
  bool slowTask = false,
  bool acceptSucceeds = true,
}) {
  return MockClient((request) async {
    final path = request.url.path;
    if (path.endsWith('/accept')) {
      if (!acceptSucceeds) {
        return _json({'success': false, 'message': 'Task is no longer available.'}, status: 409);
      }
      return _json({'success': true, 'data': task});
    }
    if (path.endsWith('/status')) {
      return _json({'success': true, 'data': task});
    }
    if (path.endsWith('/v1/team-leader/task')) {
      if (slowTask) await Future<void>.delayed(const Duration(seconds: 2));
      return _json({'success': true, 'data': task});
    }
    if (path.endsWith('/v1/team-leader/presence/ping') ||
        path.endsWith('/v1/team-leader/presence/away') ||
        path.endsWith('/v1/team-leader/location')) {
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

Future<void> _pumpTlHome(
  WidgetTester tester, {
  Object? task,
  Map<String, Object>? prefsOverride,
  ThemeData? theme,
  ThemeMode? themeMode,
  bool acceptSucceeds = true,
  void Function(String route, Object? args)? onNavigate,
}) async {
  SharedPreferences.setMockInitialValues(
    prefsOverride ??
        {'auth_token': 'tl-token', 'user_name': 'Ariel Santos', 'duty_class': 'heavy'},
  );
  await http.runWithClient(
    () async {
      await tester.pumpWidget(
        MaterialApp(
          theme: theme,
          themeMode: themeMode,
          onGenerateRoute: (settings) {
            if (settings.name == '/' || settings.name == null) {
              return MaterialPageRoute(builder: (_) => const TlHomeScreen());
            }
            onNavigate?.call(settings.name!, settings.arguments);
            return MaterialPageRoute(builder: (_) => const Scaffold());
          },
        ),
      );
      await _settle(tester);
    },
    () => _buildClient(task: task, acceptSucceeds: acceptSucceeds),
  );
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

  group('TlHomeScreen', () {
    _tlTest('shows no hamburger menu and a centered TowMate wordmark', (tester) async {
      await _pumpTlHome(tester);

      expect(find.byIcon(Icons.menu_rounded), findsNothing);
      expect(find.byType(Drawer), findsNothing);
      expect(find.byType(RichText), findsWidgets);
    });

    _tlTest('shows the real Team Leader name and a time-based greeting', (tester) async {
      await _pumpTlHome(tester);

      expect(find.text('Ariel Santos'), findsOneWidget);
      expect(
        find.textContaining(RegExp(r'Good (morning|afternoon|evening),')),
        findsOneWidget,
      );
    });

    _tlTest('shows the real availability status', (tester) async {
      await _pumpTlHome(tester);

      expect(find.text('Available'), findsOneWidget);
    });

    _tlTest('shows the real duty class inline with availability', (tester) async {
      await _pumpTlHome(tester);

      expect(find.text('· Heavy Duty'), findsOneWidget);
    });

    _tlTest('omits the duty class detail when none is available', (tester) async {
      await _pumpTlHome(
        tester,
        prefsOverride: {'auth_token': 'tl-token', 'user_name': 'Ariel Santos'},
      );

      expect(find.textContaining('Duty'), findsNothing);
    });

    _tlTest('shows the clean empty state when there is no task', (tester) async {
      await _pumpTlHome(tester);

      expect(find.text('CURRENT TASK'), findsOneWidget);
      expect(find.text('No task assigned'), findsOneWidget);
      expect(find.text('New towing requests will appear here when assigned.'), findsOneWidget);
      expect(find.text('Accept Task'), findsNothing);
    });

    _tlTest('shows the real task card details when a task is assigned', (tester) async {
      await _pumpTlHome(tester, task: _assignedTaskFixture);

      expect(find.text('TM-0501'), findsOneWidget);
      expect(find.text('Assigned'), findsOneWidget);
      expect(find.text('Juan Dela Cruz'), findsOneWidget);
      expect(find.text('09170000000'), findsOneWidget);
      expect(find.text('PICKUP'), findsOneWidget);
      expect(find.text('Pasay City'), findsOneWidget);
      expect(find.text('DROP-OFF'), findsOneWidget);
      expect(find.text('Makati City'), findsOneWidget);
      expect(find.text('9.5 km'), findsOneWidget);
      expect(find.text('Heavy Duty'), findsOneWidget);
      expect(find.text('Immediate'), findsOneWidget);
      expect(find.text('Total Fare'), findsOneWidget);
      expect(find.text('₱1,850.00'), findsOneWidget);
      expect(find.text('Accept Task'), findsOneWidget);
      expect(find.text('No task assigned'), findsNothing);
    });

    _tlTest('shows the customer note only when the task has one', (tester) async {
      await _pumpTlHome(
        tester,
        task: {..._assignedTaskFixture, 'notes': "Vehicle won't start, needs flatbed towing."},
      );

      expect(find.text('CUSTOMER NOTE'), findsOneWidget);
      expect(find.text("Vehicle won't start, needs flatbed towing."), findsOneWidget);
    });

    _tlTest('omits the customer note section when the task has none', (tester) async {
      await _pumpTlHome(tester, task: _assignedTaskFixture);

      expect(find.text('CUSTOMER NOTE'), findsNothing);
    });

    _tlTest('renders long customer name, addresses, and note without throwing', (tester) async {
      await _pumpTlHome(
        tester,
        task: {
          ..._assignedTaskFixture,
          'customer_name': 'Maria Fernanda Concepcion-Villanueva De La Cruz Santos',
          'pickup_address':
              'Unit 4502, Tower 2, The Grand Residences, 123 Extremely Long Commonwealth Avenue Corner Visayas Avenue, Barangay Holy Spirit, Quezon City, Metro Manila, Philippines 1127',
          'dropoff_address':
              'Building C, Ground Floor, Ayala Triangle Gardens, Makati Central Business District, Makati City, Metro Manila, Philippines',
          'notes':
              'Vehicle completely won\'t start after the engine overheated on the highway, needs flatbed towing to the nearest authorized service center as soon as possible, driver is waiting with hazard lights on.',
          'final_total': 128450.75,
        },
      );

      expect(tester.takeException(), isNull);
      expect(find.text('Accept Task'), findsOneWidget);
    });

    _tlTest('tapping Accept Task calls the exact existing accept flow and navigates to My Task', (tester) async {
      String? capturedRoute;

      SharedPreferences.setMockInitialValues(
        {'auth_token': 'tl-token', 'user_name': 'Ariel Santos', 'duty_class': 'heavy'},
      );
      await http.runWithClient(
        () async {
          await tester.pumpWidget(
            MaterialApp(
              onGenerateRoute: (settings) {
                if (settings.name == '/' || settings.name == null) {
                  return MaterialPageRoute(builder: (_) => const TlHomeScreen());
                }
                capturedRoute = settings.name;
                return MaterialPageRoute(builder: (_) => const Scaffold());
              },
            ),
          );
          await _settle(tester);

          await tester.ensureVisible(find.text('Accept Task'));
          await _settle(tester);
          await tester.tap(find.text('Accept Task'));
          await _settle(tester);
        },
        () => _buildClient(task: _assignedTaskFixture),
      );

      expect(capturedRoute, '/tl-active-task');
    });

    _tlTest('a failed accept keeps the task visible and shows the real error message', (tester) async {
      SharedPreferences.setMockInitialValues(
        {'auth_token': 'tl-token', 'user_name': 'Ariel Santos', 'duty_class': 'heavy'},
      );
      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: TlHomeScreen()));
          await _settle(tester);

          await tester.ensureVisible(find.text('Accept Task'));
          await _settle(tester);
          await tester.tap(find.text('Accept Task'));
          await _settle(tester);
        },
        () => _buildClient(task: _assignedTaskFixture, acceptSucceeds: false),
      );

      expect(find.text('Task is no longer available.'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    _tlTest('bottom nav shows the four real Team Leader destinations', (tester) async {
      await _pumpTlHome(tester);

      final nav = find.byType(TlBottomNav);
      expect(nav, findsOneWidget);
      for (final label in ['Home', 'My Task', 'History', 'Profile']) {
        expect(find.descendant(of: nav, matching: find.text(label)), findsOneWidget);
      }
    });

    _tlTest('tapping My Task navigates to the exact existing /tl-active-task route', (tester) async {
      String? capturedRoute;
      await _pumpTlHome(tester, onNavigate: (route, args) => capturedRoute = route);

      await tester.tap(find.text('My Task'));
      await _settle(tester);

      expect(capturedRoute, '/tl-active-task');
    });

    _tlTest('tapping History navigates to the exact existing /tl-history route', (tester) async {
      String? capturedRoute;
      await _pumpTlHome(tester, onNavigate: (route, args) => capturedRoute = route);

      await tester.tap(find.text('History'));
      await _settle(tester);

      expect(capturedRoute, '/tl-history');
    });

    _tlTest('tapping Profile navigates to the exact existing /tl-profile route', (tester) async {
      String? capturedRoute;
      await _pumpTlHome(tester, onNavigate: (route, args) => capturedRoute = route);

      await tester.tap(find.text('Profile'));
      await _settle(tester);

      expect(capturedRoute, '/tl-profile');
    });

    _tlTest('tapping the already-selected Home tab does not navigate away', (tester) async {
      final routes = <String>[];
      await _pumpTlHome(tester, onNavigate: (route, args) => routes.add(route));

      await tester.tap(find.text('Home'));
      await _settle(tester);

      expect(routes, isEmpty);
    });

    _tlTest('pull to refresh triggers a task refresh without throwing', (tester) async {
      await http.runWithClient(
        () async {
          await _pumpTlHome(tester);
          await tester.fling(find.byType(SingleChildScrollView), const Offset(0, 300), 1000);
          await _settle(tester);
        },
        () => _buildClient(),
      );

      expect(tester.takeException(), isNull);
    });

    for (final width in [320.0, 340.0, 360.0, 375.0, 390.0, 412.0]) {
      _tlTest('renders without horizontal overflow at $width px width', (tester) async {
        final originalSize = tester.view.physicalSize;
        final originalRatio = tester.view.devicePixelRatio;
        tester.view.physicalSize = Size(width, 800);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(() {
          tester.view.physicalSize = originalSize;
          tester.view.devicePixelRatio = originalRatio;
        });

        await _pumpTlHome(tester, task: _assignedTaskFixture);

        expect(tester.takeException(), isNull);
        expect(find.byType(TlBottomNav), findsOneWidget);
      });
    }

    _tlTest('renders correctly in light mode', (tester) async {
      await _pumpTlHome(tester, theme: AppTheme.light, themeMode: ThemeMode.light);

      expect(tester.takeException(), isNull);
      expect(find.text('Ariel Santos'), findsOneWidget);
    });

    _tlTest('renders correctly in dark mode', (tester) async {
      await _pumpTlHome(tester, theme: AppTheme.dark, themeMode: ThemeMode.dark);

      expect(tester.takeException(), isNull);
      expect(find.text('Ariel Santos'), findsOneWidget);
    });
  });
}
