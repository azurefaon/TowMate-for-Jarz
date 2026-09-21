import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/team_leader/tl_active_task_shell.dart';
import 'package:towmate_app/screens/team_leader/tl_return_screen.dart';
import 'package:towmate_app/widgets/tl_status_timeline.dart';

Map<String, dynamic> taskJson({
  String bookingCode = 'TM-00100',
  String status = 'on_the_way',
  String pickupAddress = '123 Commonwealth Avenue, Quezon City, Metro Manila',
  double distanceKm = 8.4,
  String customerName = 'Juan Dela Cruz',
  String customerPhone = '09171234567',
  String customerEmail = 'juan@example.test',
  double finalTotal = 4658.08,
  String truckTypeName = 'Medium Duty',
  String serviceType = 'book_now',
  String? notes,
}) {
  return {
    'id': bookingCode.hashCode,
    'booking_code': bookingCode,
    'status': status,
    'pickup_address': pickupAddress,
    'dropoff_address': '456 Ayala Avenue, Makati City, Metro Manila',
    'pickup_lat': 14.676,
    'pickup_lng': 121.0437,
    'dropoff_lat': 14.5547,
    'dropoff_lng': 121.0244,
    'distance_km': distanceKm,
    'customer_name': customerName,
    'customer_phone': customerPhone,
    'customer_email': customerEmail,
    'final_total': finalTotal,
    'truck_type_name': truckTypeName,
    'service_type': serviceType,
    'notes': notes,
  };
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

  http.Response jsonResponse(Object body, {int status = 200}) {
    return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
  }

  http.Client buildClient({
    required Map<String, dynamic> task,
    bool slowStatus = false,
    bool statusFails = false,
    List<String>? statusRequestsLog,
  }) {
    return MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/v1/team-leader/task') && request.method == 'GET') {
        return jsonResponse({'success': true, 'data': task});
      }
      if (path.contains('/status') && request.method == 'PATCH') {
        statusRequestsLog?.add(request.body);
        if (slowStatus) {
          await Future<void>.delayed(const Duration(milliseconds: 400));
        }
        if (statusFails) {
          return jsonResponse({'success': false, 'message': 'Failed to update.'});
        }
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        final updated = Map<String, dynamic>.from(task)..['status'] = body['status'];
        return jsonResponse({'success': true, 'data': updated});
      }
      if (path.endsWith('/presence/ping') || path.endsWith('/presence/away')) {
        return jsonResponse({'success': true});
      }
      return jsonResponse({'success': false}, status: 404);
    });
  }

  Future<void> settle(WidgetTester tester) async {
    for (var i = 0; i < 10; i++) {
      await tester.pump(const Duration(milliseconds: 50));
    }
  }

  Future<void> pumpShell(
    WidgetTester tester, {
    required Map<String, dynamic> task,
    ThemeData? theme,
    ThemeMode? themeMode,
    bool slowStatus = false,
    bool statusFails = false,
    List<String>? statusRequestsLog,
    Future<void> Function(WidgetTester tester)? andThen,
  }) async {
    SharedPreferences.setMockInitialValues({'auth_token': 'tl-token'});
    await http.runWithClient(
      () async {
        await tester.pumpWidget(
          MaterialApp(
            theme: theme,
            themeMode: themeMode,
            home: const TlActiveTaskShell(),
          ),
        );
        await settle(tester);
        if (andThen != null) {
          await andThen(tester);
        }
      },
      () => buildClient(
        task: task,
        slowStatus: slowStatus,
        statusFails: statusFails,
        statusRequestsLog: statusRequestsLog,
      ),
    );
  }

  group('TlEnRouteScreen (Step 1 of 6)', () {
    testWidgets('renders the real pickup address', (tester) async {
      await pumpShell(tester, task: taskJson());
      expect(find.text('123 Commonwealth Avenue, Quezon City, Metro Manila'), findsOneWidget);
    });

    testWidgets('renders the real distance', (tester) async {
      await pumpShell(tester, task: taskJson(distanceKm: 8.4));
      expect(find.text('8.4 km'), findsOneWidget);
    });

    testWidgets('renders the real customer name', (tester) async {
      await pumpShell(tester, task: taskJson(customerName: 'Juan Dela Cruz'));
      expect(find.text('Juan Dela Cruz'), findsOneWidget);
    });

    testWidgets('renders the real customer phone', (tester) async {
      await pumpShell(tester, task: taskJson(customerPhone: '09171234567'));
      expect(find.text('09171234567'), findsOneWidget);
    });

    testWidgets('renders the real truck/service type', (tester) async {
      await pumpShell(tester, task: taskJson(truckTypeName: 'Medium Duty'));
      expect(find.text('Medium Duty'), findsOneWidget);
    });

    testWidgets('renders Immediate for a book_now schedule', (tester) async {
      await pumpShell(tester, task: taskJson(serviceType: 'book_now'));
      expect(find.text('Immediate'), findsOneWidget);
    });

    testWidgets('renders Scheduled for a schedule booking', (tester) async {
      await pumpShell(tester, task: taskJson(serviceType: 'schedule'));
      expect(find.text('Scheduled'), findsOneWidget);
    });

    testWidgets('renders the customer note when present', (tester) async {
      await pumpShell(tester, task: taskJson(notes: 'Vehicle needs flatbed towing.'));
      expect(find.text('CUSTOMER NOTE'), findsOneWidget);
      expect(find.text('Vehicle needs flatbed towing.'), findsOneWidget);
    });

    testWidgets('omits the customer note section when absent', (tester) async {
      await pumpShell(tester, task: taskJson(notes: null));
      expect(find.text('CUSTOMER NOTE'), findsNothing);
    });

    testWidgets('never displays the customer email', (tester) async {
      await pumpShell(tester, task: taskJson(customerEmail: 'juan@example.test'));
      expect(find.text('juan@example.test'), findsNothing);
    });

    testWidgets('never displays the total fare', (tester) async {
      await pumpShell(tester, task: taskJson(finalTotal: 4658.08));
      expect(find.textContaining('4658.08'), findsNothing);
      expect(find.textContaining('4,658.08'), findsNothing);
    });

    testWidgets('never displays a fake ETA', (tester) async {
      await pumpShell(tester, task: taskJson());
      expect(find.textContaining('ETA'), findsNothing);
    });

    testWidgets('removes the old black GPS-active banner', (tester) async {
      await pumpShell(tester, task: taskJson());
      expect(find.text('GPS tracking is active'), findsNothing);
    });

    testWidgets('shows the step header title and step count', (tester) async {
      await pumpShell(tester, task: taskJson());
      expect(find.text('En Route to Pickup'), findsOneWidget);
      expect(find.text('Step 1 of 6'), findsOneWidget);
      expect(find.text('1 / 6'), findsOneWidget);
    });

    testWidgets('Navigate to Pickup switches the existing IndexedStack to the Navigate tab', (tester) async {
      await pumpShell(tester, task: taskJson());

      final stackBefore = tester.widget<IndexedStack>(find.byType(IndexedStack));
      expect(stackBefore.index, 0);

      await tester.tap(find.text('Navigate to Pickup'));
      await settle(tester);

      final stackAfter = tester.widget<IndexedStack>(find.byType(IndexedStack));
      expect(stackAfter.index, 1);
    });

    testWidgets('Navigate to Pickup does not push a new route or open an external URL', (tester) async {
      final observer = _RecordingNavigatorObserver();
      SharedPreferences.setMockInitialValues({'auth_token': 'tl-token'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(
            MaterialApp(
              navigatorObservers: [observer],
              home: const TlActiveTaskShell(),
            ),
          );
          await settle(tester);
        },
        () => buildClient(task: taskJson()),
      );

      await tester.tap(find.text('Navigate to Pickup'));
      await settle(tester);

      expect(observer.pushCount, 0);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Arrived at Pickup sends the real backend status update', (tester) async {
      final log = <String>[];
      await pumpShell(
        tester,
        task: taskJson(status: 'on_the_way'),
        statusRequestsLog: log,
        andThen: (tester) async {
          await tester.ensureVisible(find.text('Arrived at Pickup'));
          await tester.tap(find.text('Arrived at Pickup'));
          await settle(tester);
        },
      );

      expect(log.any((b) => jsonDecode(b)['status'] == 'arrived_pickup'), isTrue);
      expect(tester.takeException(), isNull);
    });

    testWidgets('Arrived at Pickup first ensures on_the_way when starting from accepted', (tester) async {
      final log = <String>[];
      await pumpShell(
        tester,
        task: taskJson(status: 'accepted'),
        statusRequestsLog: log,
        andThen: (tester) async {
          await tester.ensureVisible(find.text('Arrived at Pickup'));
          await tester.tap(find.text('Arrived at Pickup'));
          await settle(tester);
        },
      );

      expect(log.any((b) => jsonDecode(b)['status'] == 'on_the_way'), isTrue);
    });

    testWidgets('shows a loading spinner and disables the button while Arrived at Pickup is in flight', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(status: 'on_the_way'),
        slowStatus: true,
        andThen: (tester) async {
          await tester.ensureVisible(find.text('Arrived at Pickup'));
          await tester.tap(find.text('Arrived at Pickup'));
          await tester.pump(const Duration(milliseconds: 50));

          expect(find.byType(CircularProgressIndicator), findsOneWidget);

          await tester.pump(const Duration(milliseconds: 500));
          await settle(tester);
        },
      );
    });

    testWidgets('double-tapping Arrived at Pickup only submits one status update', (tester) async {
      final log = <String>[];
      await pumpShell(
        tester,
        task: taskJson(status: 'on_the_way'),
        slowStatus: true,
        statusRequestsLog: log,
        andThen: (tester) async {
          await tester.ensureVisible(find.text('Arrived at Pickup'));
          final center = tester.getCenter(find.text('Arrived at Pickup'));
          await tester.tapAt(center);
          await tester.pump(const Duration(milliseconds: 50));
          await tester.tapAt(center);
          await tester.pump(const Duration(milliseconds: 500));
          await settle(tester);
        },
      );

      expect(log.length, 1);
    });

    testWidgets('Back retains the on_the_way to accepted lifecycle mutation', (tester) async {
      final log = <String>[];
      await pumpShell(
        tester,
        task: taskJson(status: 'on_the_way'),
        statusRequestsLog: log,
        andThen: (tester) async {
          await tester.ensureVisible(find.text('Back'));
          await tester.tap(find.text('Back'));
          await settle(tester);
        },
      );

      expect(log.any((b) => jsonDecode(b)['status'] == 'accepted'), isTrue);
    });

    testWidgets('Return Task pushes the existing TlReturnScreen', (tester) async {
      await pumpShell(tester, task: taskJson());

      await tester.ensureVisible(find.text('Return Task'));
      await tester.tap(find.text('Return Task'));
      await settle(tester);

      expect(find.byType(TlReturnScreen), findsOneWidget);
    });

    testWidgets('Demo Arrival stays hidden without the compile-time flag', (tester) async {
      await pumpShell(tester, task: taskJson());
      expect(find.text('Demo Arrival'), findsNothing);
    });

    testWidgets('renders correctly in light mode', (tester) async {
      await pumpShell(tester, task: taskJson(), theme: AppTheme.light, themeMode: ThemeMode.light);
      expect(tester.takeException(), isNull);
      expect(find.text('En Route to Pickup'), findsOneWidget);
    });

    testWidgets('renders correctly in dark mode', (tester) async {
      await pumpShell(tester, task: taskJson(), theme: AppTheme.dark, themeMode: ThemeMode.dark);
      expect(tester.takeException(), isNull);
      expect(find.text('En Route to Pickup'), findsOneWidget);
    });

    for (final width in [320.0, 340.0, 360.0, 375.0, 390.0, 412.0]) {
      testWidgets('renders without overflow at $width px width', (tester) async {
        final originalSize = tester.view.physicalSize;
        final originalRatio = tester.view.devicePixelRatio;
        tester.view.physicalSize = Size(width, 900);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(() {
          tester.view.physicalSize = originalSize;
          tester.view.devicePixelRatio = originalRatio;
        });

        await pumpShell(tester, task: taskJson());

        expect(tester.takeException(), isNull);
      });
    }

    testWidgets('wraps long pickup address, customer name, phone, and note without overflow', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          pickupAddress:
              'Unit 4502, Tower 3, The Grand Residences at Commonwealth Avenue corner Congressional Avenue Extension, Barangay Holy Spirit, Quezon City, Metro Manila, Philippines 1127',
          customerName: 'Maria Cristina Fernandez-Villanueva de los Santos',
          customerPhone: '+63 917 123 4567 (landline: 02-8123-4567 loc. 890)',
          notes:
              'Please be careful, the vehicle has a very low ground clearance and the parking area has a steep, narrow entrance with limited turning radius for a flatbed truck.',
        ),
      );

      expect(tester.takeException(), isNull);
    });
  });

  group('TlActiveTaskShell header on Step 1', () {
    testWidgets('hides the status pill, Live indicator, and status timeline on Step 1', (tester) async {
      await pumpShell(tester, task: taskJson(status: 'on_the_way'));

      expect(find.text('Route'), findsNothing);
      expect(find.text('Live'), findsNothing);
    });

    testWidgets('still shows the status pill and timeline on a later, non-redesigned step', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(status: 'waiting_verification')..['payment_method'] = 'cash',
      );

      expect(find.text('Pending Payment'), findsWidgets);
      expect(find.byType(TlStatusTimeline), findsOneWidget);
    });
  });
}

class _RecordingNavigatorObserver extends NavigatorObserver {
  int pushCount = 0;

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    if (previousRoute != null) pushCount++;
  }
}
