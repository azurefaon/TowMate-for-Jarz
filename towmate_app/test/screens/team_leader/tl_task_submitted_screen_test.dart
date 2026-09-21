import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/team_leader/tl_active_task_shell.dart';

Map<String, dynamic> submittedTaskJson({
  String bookingCode = 'TM-00100',
  double finalTotal = 4658.08,
  String? paymentMethod = 'cash',
  String? groupCode,
  int groupVehicleCount = 1,
  double? groupTotal,
}) {
  return {
    'id': bookingCode.hashCode,
    'booking_code': bookingCode,
    'status': 'waiting_verification',
    'pickup_address': '123 Commonwealth Avenue, Quezon City, Metro Manila',
    'dropoff_address': '456 Ayala Avenue, Makati City, Metro Manila',
    'pickup_lat': 14.676,
    'pickup_lng': 121.0437,
    'dropoff_lat': 14.5547,
    'dropoff_lng': 121.0244,
    'distance_km': 8.4,
    'customer_name': 'Juan Dela Cruz',
    'customer_phone': '09171234567',
    'customer_email': 'juan@example.test',
    'final_total': finalTotal,
    'truck_type_name': 'Medium Duty',
    'service_type': 'book_now',
    'notes': null,
    'payment_method': paymentMethod,
    'group_code': groupCode,
    'group_vehicle_count': groupVehicleCount,
    'group_position': 1,
    'group_ready_for_payment': true,
    'group_total': groupTotal,
    'group_vehicle_totals': const [],
    'group_vehicle_breakdown': null,
    'group_adjustment': null,
  };
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  http.StreamedResponse jsonStreamed(Object body, http.BaseRequest request, {int status = 200}) {
    return http.StreamedResponse(
      http.ByteStream.fromBytes(utf8.encode(jsonEncode(body))),
      status,
      request: request,
      headers: {'content-type': 'application/json'},
    );
  }

  http.Client buildClient(Map<String, dynamic> task) {
    return MockClient.streaming((request, bodyStream) async {
      final path = request.url.path;
      if (path.endsWith('/v1/team-leader/task') && request.method == 'GET') {
        return jsonStreamed({'success': true, 'data': task}, request);
      }
      if (path.endsWith('/presence/ping') || path.endsWith('/presence/away')) {
        return jsonStreamed({'success': true}, request);
      }
      return jsonStreamed({'success': false}, request, status: 404);
    });
  }

  Future<void> settle(WidgetTester tester) async {
    for (var i = 0; i < 10; i++) {
      await tester.pump(const Duration(milliseconds: 50));
    }
  }

  Future<void> pumpScreen(
    WidgetTester tester, {
    required Map<String, dynamic> task,
    ThemeData? theme,
    ThemeMode? themeMode,
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
      },
      () => buildClient(task),
    );
  }

  group('TlTaskSubmittedScreen (post Step 5 pending dispatcher confirmation)', () {
    testWidgets('single booking shows the Step 5 continuation header and real values', (tester) async {
      await pumpScreen(
        tester,
        task: submittedTaskJson(
          bookingCode: 'TM-00200',
          finalTotal: 4658.08,
          paymentMethod: 'gcash',
        ),
      );

      expect(find.text('Pending Payment'), findsOneWidget);
      expect(find.text('Step 5 of 6'), findsOneWidget);
      expect(find.text('5 / 6'), findsOneWidget);
      expect(find.text('Payment Collected'), findsOneWidget);
      expect(find.text('Payment has been submitted.'), findsOneWidget);
      expect(find.text('Waiting for dispatcher confirmation to close the job.'), findsOneWidget);
      expect(find.text('TM-00200'), findsOneWidget);
      expect(find.text('GCash'), findsOneWidget);
      expect(find.text('₱4,658.08'), findsOneWidget);
      expect(find.text('Vehicles'), findsNothing);
    });

    testWidgets('grouped booking shows the correct group vehicle count and consolidated wording', (tester) async {
      await pumpScreen(
        tester,
        task: submittedTaskJson(
          bookingCode: 'TM-00300',
          paymentMethod: 'cash',
          groupCode: 'GRP-6',
          groupVehicleCount: 6,
          groupTotal: 27448.48,
        ),
      );

      expect(find.text('Vehicles'), findsOneWidget);
      expect(find.text('6'), findsOneWidget);
      expect(
        find.text('Consolidated payment for 6 vehicles has been submitted.'),
        findsOneWidget,
      );
      expect(find.text('Waiting for dispatcher confirmation to close the job.'), findsOneWidget);
      expect(find.text('Cash'), findsOneWidget);
      expect(find.text('₱27,448.48'), findsOneWidget);
    });

    testWidgets('does not imply the job is already completed', (tester) async {
      await pumpScreen(tester, task: submittedTaskJson());

      expect(find.textContaining('Job completed'), findsNothing);
      expect(find.textContaining('has been completed'), findsNothing);
      expect(find.text('Waiting for dispatcher confirmation to close the job.'), findsOneWidget);
    });

    testWidgets('preserves the existing active-task bottom navigation tabs', (tester) async {
      await pumpScreen(tester, task: submittedTaskJson());

      expect(find.text('Task'), findsOneWidget);
      expect(find.text('Navigate'), findsOneWidget);
      expect(find.text('Emergency'), findsOneWidget);
    });

    testWidgets('renders correctly in light mode', (tester) async {
      await pumpScreen(tester, task: submittedTaskJson(), theme: AppTheme.light, themeMode: ThemeMode.light);
      expect(tester.takeException(), isNull);
      expect(find.text('Pending Payment'), findsOneWidget);
    });

    testWidgets('renders correctly in dark mode', (tester) async {
      await pumpScreen(tester, task: submittedTaskJson(), theme: AppTheme.dark, themeMode: ThemeMode.dark);
      expect(tester.takeException(), isNull);
      expect(find.text('Pending Payment'), findsOneWidget);
    });

    for (final width in [320.0, 412.0]) {
      testWidgets('renders without overflow at $width px width', (tester) async {
        final originalSize = tester.view.physicalSize;
        final originalRatio = tester.view.devicePixelRatio;
        tester.view.physicalSize = Size(width, 900);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(() {
          tester.view.physicalSize = originalSize;
          tester.view.devicePixelRatio = originalRatio;
        });

        await pumpScreen(
          tester,
          task: submittedTaskJson(
            groupCode: 'GRP-6',
            groupVehicleCount: 6,
            groupTotal: 27448.48,
          ),
        );

        expect(tester.takeException(), isNull);
      });
    }
  });
}
