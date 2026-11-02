import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/team_leader/tl_active_task_shell.dart';
import 'package:towmate_app/screens/team_leader/tl_awaiting_confirm_screen.dart';
import 'package:towmate_app/services/tl_presence_controller.dart';

Map<String, dynamic> taskJson({
  String bookingCode = 'TM-00100',
  String status = 'arrived_dropoff',
  String pickupAddress = '123 Commonwealth Avenue, Quezon City, Metro Manila',
  String dropoffAddress = '456 Ayala Avenue, Makati City, Metro Manila',
  double distanceKm = 8.4,
  String customerName = 'Juan Dela Cruz',
  String customerPhone = '09171234567',
  String customerEmail = 'juan@example.test',
  double finalTotal = 4658.08,
  String truckTypeName = 'Medium Duty',
  String serviceType = 'book_now',
  String? notes,
  String? groupCode,
  int groupVehicleCount = 1,
  int groupPosition = 1,
  bool groupReadyForPayment = false,
  Map<String, dynamic>? payment,
}) {
  return {
    if (payment != null) 'payment': payment,
    'id': bookingCode.hashCode,
    'booking_code': bookingCode,
    'status': status,
    'pickup_address': pickupAddress,
    'dropoff_address': dropoffAddress,
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
    'group_code': groupCode,
    'group_vehicle_count': groupVehicleCount,
    'group_position': groupPosition,
    'group_ready_for_payment': groupReadyForPayment,
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
    List<String>? statusRequestsLog,
    List<String>? completeRequestsLog,
  }) {
    return MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/v1/team-leader/task') && request.method == 'GET') {
        return jsonResponse({'success': true, 'data': task});
      }
      if (path.endsWith('/complete') && request.method == 'POST') {
        completeRequestsLog?.add(request.body);
        return jsonResponse({'success': true, 'data': Map<String, dynamic>.from(task)..['status'] = 'completed'});
      }
      if (path.contains('/status') && request.method == 'PATCH') {
        statusRequestsLog?.add(request.body);
        if (slowStatus) {
          await Future<void>.delayed(const Duration(milliseconds: 400));
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
    List<String>? statusRequestsLog,
    List<String>? completeRequestsLog,
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
        statusRequestsLog: statusRequestsLog,
        completeRequestsLog: completeRequestsLog,
      ),
    );
    TlPresenceController.stop();
  }

  group('TlArrivedDropoffScreen (Step 4 of 6)', () {
    testWidgets('shows the Arrived at Drop-off title with Step 4 progress', (tester) async {
      await pumpShell(tester, task: taskJson());
      expect(find.text('Arrived at Drop-off'), findsOneWidget);
      expect(find.text('Step 4 of 6'), findsOneWidget);
      expect(find.text('4 / 6'), findsOneWidget);
    });

    testWidgets('does not show a redundant Dropoff status pill in the header', (tester) async {
      await pumpShell(tester, task: taskJson());
      expect(find.text('Dropoff'), findsNothing);
      expect(find.text('Live'), findsNothing);
    });

    testWidgets('replaces the old near-empty Step 4 presentation', (tester) async {
      await pumpShell(tester, task: taskJson());
      expect(find.textContaining('Arrived at:'), findsNothing);
    });

    testWidgets('renders the real drop-off address', (tester) async {
      await pumpShell(tester, task: taskJson());
      expect(find.text('456 Ayala Avenue, Makati City, Metro Manila'), findsOneWidget);
    });

    testWidgets('renders the real customer name and phone', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(customerName: 'Juan Dela Cruz', customerPhone: '09171234567'),
      );
      expect(find.text('Juan Dela Cruz'), findsOneWidget);
      expect(find.text('09171234567'), findsOneWidget);
    });

    testWidgets('never displays the customer email', (tester) async {
      await pumpShell(tester, task: taskJson(customerEmail: 'juan@example.test'));
      expect(find.text('juan@example.test'), findsNothing);
    });

    testWidgets('renders real service details combining truck type, distance, and schedule', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(truckTypeName: 'Medium Duty', distanceKm: 8.4, serviceType: 'book_now'),
      );
      expect(find.text('Medium Duty · 8.4 km · Immediate'), findsOneWidget);
    });

    testWidgets('renders the real pickup address', (tester) async {
      await pumpShell(tester, task: taskJson());
      expect(find.text('123 Commonwealth Avenue, Quezon City, Metro Manila'), findsOneWidget);
    });

    testWidgets('renders the customer note when present', (tester) async {
      await pumpShell(tester, task: taskJson(notes: 'Handle with care.'));
      expect(find.text('CUSTOMER NOTE'), findsOneWidget);
      expect(find.text('Handle with care.'), findsOneWidget);
    });

    testWidgets('omits the customer note section when absent', (tester) async {
      await pumpShell(tester, task: taskJson(notes: null));
      expect(find.text('CUSTOMER NOTE'), findsNothing);
    });

    testWidgets('never displays the total fare', (tester) async {
      await pumpShell(tester, task: taskJson(finalTotal: 4658.08));
      expect(find.textContaining('4658.08'), findsNothing);
      expect(find.textContaining('4,658.08'), findsNothing);
    });

    testWidgets('shows the Cash Payment action context for a cash booking', (tester) async {
      await pumpShell(tester, task: taskJson());
      expect(find.text('Cash Payment'), findsOneWidget);
      expect(find.text('Collect cash: amount received, payment photo and customer signature, then submit to the dispatcher.'), findsOneWidget);
      expect(find.text('Complete Task'), findsNothing);
    });

    testWidgets('a verified online-paid booking shows Payment Already Completed Online and COMPLETE TASK, never a cash form', (tester) async {
      final completes = <String>[];
      await pumpShell(
        tester,
        task: taskJson(payment: {'mode': 'online_prepaid', 'amount_due': '4658.08', 'amount_paid': '4658.08', 'test_mode': true}),
        completeRequestsLog: completes,
        andThen: (tester) async {
          expect(find.text('Payment Already Completed Online'), findsOneWidget);
          expect(find.textContaining('TEST / DEMO'), findsOneWidget);
          expect(find.text('Collect Cash Payment'), findsNothing);

          await tester.ensureVisible(find.text('Complete Task'));
          await tester.tap(find.text('Complete Task'));
          await settle(tester);

          expect(find.byType(TlAwaitingConfirmScreen), findsNothing);
          expect(completes, hasLength(1));
          expect(completes.single, isNot(contains('cash_received')));
          expect(completes.single, isNot(contains('payment_method')));
        },
      );
    });

    testWidgets('an online payment under review blocks collection and completion', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(payment: {'mode': 'online_review', 'message': 'Do not collect payment; contact the dispatcher.'}),
      );

      expect(find.text('Do Not Collect Payment'), findsOneWidget);
      expect(find.text('Do not collect payment; contact the dispatcher.'), findsOneWidget);
      expect(tester.widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Contact the Dispatcher')).onPressed, isNull);
      expect(find.text('Collect Cash Payment'), findsNothing);
      expect(find.text('Complete Task'), findsNothing);
    });

    testWidgets('an unknown payment mode from the server is never treated as paid', (tester) async {
      await pumpShell(tester, task: taskJson(payment: {'mode': 'something_new'}));

      expect(find.text('Complete Task'), findsNothing);
      expect(find.text('Do Not Collect Payment'), findsOneWidget);
    });

    testWidgets('shows Collect Cash Payment and never the old Proceed to Payment label', (tester) async {
      await pumpShell(tester, task: taskJson());
      expect(find.text('Collect Cash Payment'), findsOneWidget);
      expect(find.text('Proceed to Payment'), findsNothing);
    });

    testWidgets('Collect Cash Payment opens the same existing TlAwaitingConfirmScreen', (tester) async {
      await pumpShell(tester, task: taskJson());

      await tester.ensureVisible(find.text('Collect Cash Payment'));
      await tester.tap(find.text('Collect Cash Payment'));
      await settle(tester);

      expect(find.byType(TlAwaitingConfirmScreen), findsOneWidget);
    });

    testWidgets('Back sends the real reverse transition to on_job', (tester) async {
      final log = <String>[];
      await pumpShell(
        tester,
        task: taskJson(),
        statusRequestsLog: log,
        andThen: (tester) async {
          await tester.ensureVisible(find.text('Back'));
          await tester.tap(find.text('Back'));
          await settle(tester);
        },
      );

      expect(log.any((b) => jsonDecode(b)['status'] == 'on_job'), isTrue);
    });

    testWidgets('shows a loading spinner while Back is in flight', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(),
        slowStatus: true,
        andThen: (tester) async {
          await tester.ensureVisible(find.text('Back'));
          await tester.tap(find.text('Back'));
          await tester.pump(const Duration(milliseconds: 50));

          expect(find.byType(CircularProgressIndicator), findsOneWidget);

          await tester.pump(const Duration(milliseconds: 500));
          await settle(tester);
        },
      );
    });

    testWidgets('Collect Cash Payment stays disabled while Back is loading, matching existing shared-loading behavior', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(),
        slowStatus: true,
        andThen: (tester) async {
          await tester.ensureVisible(find.text('Back'));
          await tester.tap(find.text('Back'));
          await tester.pump(const Duration(milliseconds: 50));

          final button = tester.widget<ElevatedButton>(find.byType(ElevatedButton));
          expect(button.onPressed, isNull);

          await tester.pump(const Duration(milliseconds: 500));
          await settle(tester);
        },
      );
    });

    testWidgets('renders correctly in light mode', (tester) async {
      await pumpShell(tester, task: taskJson(), theme: AppTheme.light, themeMode: ThemeMode.light);
      expect(tester.takeException(), isNull);
      expect(find.text('Arrived at Drop-off'), findsOneWidget);
    });

    testWidgets('renders correctly in dark mode', (tester) async {
      await pumpShell(tester, task: taskJson(), theme: AppTheme.dark, themeMode: ThemeMode.dark);
      expect(tester.takeException(), isNull);
      expect(find.text('Arrived at Drop-off'), findsOneWidget);
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

    testWidgets('wraps long drop-off/pickup addresses, customer name, phone, and note without overflow', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          dropoffAddress:
              'Basement Parking Level 3, One Ayala Avenue Tower, corner Ayala Avenue and Makati Avenue, Barangay Bel-Air, Makati City, Metro Manila, Philippines 1226',
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

  group('TlActiveTaskShell header on Step 4', () {
    testWidgets('hides the shared status pill and status timeline on Step 4', (tester) async {
      await pumpShell(tester, task: taskJson(status: 'arrived_dropoff'));

      expect(find.text('Live'), findsNothing);
    });

    testWidgets('still shows the status pill on other non-redesigned steps', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(status: 'waiting_verification')..['payment_method'] = 'cash',
      );

      expect(find.text('Pending Payment'), findsWidgets);
    });
  });

  group('TlArrivedDropoffScreen with a real ready normalized group', () {
    testWidgets('renders a 3-vehicle group already ready for payment without throwing', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'DEMOGRP-test',
          groupVehicleCount: 3,
          groupPosition: 3,
          groupReadyForPayment: true,
        ),
      );

      expect(tester.takeException(), isNull);
      expect(find.text('Arrived at Drop-off'), findsOneWidget);
      expect(find.text('Collect Cash Payment'), findsOneWidget);
      expect(find.text('Waiting for Other Vehicle'), findsNothing);
    });

    testWidgets('keeps polling for group updates over time without throwing', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'DEMOGRP-test',
          groupVehicleCount: 3,
          groupPosition: 3,
          groupReadyForPayment: true,
        ),
        andThen: (tester) async {
          await tester.pump(const Duration(seconds: 6));
          await tester.pump(const Duration(seconds: 6));
          expect(tester.takeException(), isNull);
        },
      );
    });

    testWidgets('Collect Cash Payment opens Step 5 for a ready group', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'DEMOGRP-test',
          groupVehicleCount: 3,
          groupPosition: 3,
          groupReadyForPayment: true,
        ),
      );

      await tester.ensureVisible(find.text('Collect Cash Payment'));
      await tester.tap(find.text('Collect Cash Payment'));
      await settle(tester);

      expect(find.byType(TlAwaitingConfirmScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('shows the waiting state and blocks Proceed when the group is not yet ready', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'DEMOGRP-test',
          groupVehicleCount: 3,
          groupPosition: 1,
          groupReadyForPayment: false,
        ),
      );

      expect(tester.takeException(), isNull);
      expect(find.text('Waiting for Other Vehicle'), findsOneWidget);
      expect(find.textContaining('Vehicle 1 of 3'), findsWidgets);
    });
  });
}
