import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/team_leader/tl_active_task_shell.dart';

Map<String, dynamic> baseTaskJson({
  required String bookingCode,
  required String status,
  int groupVehicleCount = 1,
  int groupPosition = 1,
  bool hasClaimableSibling = false,
  String? groupCode,
}) {
  return {
    'id': bookingCode.hashCode,
    'booking_code': bookingCode,
    'status': status,
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
    'final_total': 4658.08,
    'truck_type_name': 'Medium Duty',
    'service_type': 'book_now',
    'notes': null,
    'payment_method': status == 'completed' ? 'cash' : null,
    'group_code': groupCode,
    'group_vehicle_count': groupVehicleCount,
    'group_position': groupPosition,
    'group_ready_for_payment': true,
    'group_total': null,
    'group_vehicle_totals': const [],
    'group_vehicle_breakdown': null,
    'group_adjustment': null,
    'has_claimable_sibling': hasClaimableSibling,
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

  http.Client buildClient(Map<String, dynamic> initialTask, Map<String, dynamic> completedTask, List<int> callCount) {
    return MockClient.streaming((request, bodyStream) async {
      final path = request.url.path;
      if (path.endsWith('/v1/team-leader/task') && request.method == 'GET') {
        callCount[0]++;
        final task = callCount[0] == 1 ? initialTask : completedTask;
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

  Future<void> pumpToCompleted(
    WidgetTester tester, {
    required Map<String, dynamic> completedTask,
    ThemeData? theme,
    ThemeMode? themeMode,
  }) async {
    final initialTask = Map<String, dynamic>.from(completedTask)..['status'] = 'arrived_dropoff';
    final callCount = [0];
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
        await tester.pump(const Duration(seconds: 21));
        await settle(tester);
      },
      () => buildClient(initialTask, completedTask, callCount),
    );
  }

  group('TlCompletedScreen', () {
    testWidgets('grouped request with no next vehicle shows Service Complete, not Vehicle N Complete', (tester) async {
      await pumpToCompleted(
        tester,
        completedTask: baseTaskJson(
          bookingCode: 'TM-00240',
          status: 'completed',
          groupCode: 'GRP-6',
          groupVehicleCount: 6,
          groupPosition: 6,
          hasClaimableSibling: false,
        ),
      );

      expect(find.text('Service Complete'), findsOneWidget);
      expect(find.text('Vehicle 6 Complete'), findsNothing);
      expect(
        find.text('The towing service has been completed and verified.'),
        findsOneWidget,
      );
    });

    testWidgets('grouped request with a claimable next vehicle keeps the per-vehicle heading', (tester) async {
      await pumpToCompleted(
        tester,
        completedTask: baseTaskJson(
          bookingCode: 'TM-00235',
          status: 'completed',
          groupCode: 'GRP-6',
          groupVehicleCount: 6,
          groupPosition: 1,
          hasClaimableSibling: true,
        ),
      );

      expect(find.text('Vehicle 1 Complete'), findsOneWidget);
      expect(find.text('Service Complete'), findsNothing);
      expect(find.textContaining('NEXT: Collect Vehicle 2'), findsOneWidget);
    });

    testWidgets('single (non-grouped) booking preserves the existing per-vehicle heading', (tester) async {
      await pumpToCompleted(
        tester,
        completedTask: baseTaskJson(
          bookingCode: 'TM-00100',
          status: 'completed',
          groupVehicleCount: 1,
          groupPosition: 1,
          hasClaimableSibling: false,
        ),
      );

      expect(find.text('Vehicle 1 Complete'), findsOneWidget);
      expect(find.text('Service Complete'), findsNothing);
      expect(
        find.text('The towing service has been completed and verified.'),
        findsOneWidget,
      );
    });

    testWidgets('renders correctly in light mode', (tester) async {
      await pumpToCompleted(
        tester,
        completedTask: baseTaskJson(
          bookingCode: 'TM-00240',
          status: 'completed',
          groupCode: 'GRP-6',
          groupVehicleCount: 6,
          groupPosition: 6,
        ),
        theme: AppTheme.light,
        themeMode: ThemeMode.light,
      );
      expect(tester.takeException(), isNull);
      expect(find.text('Service Complete'), findsOneWidget);
    });

    testWidgets('renders correctly in dark mode', (tester) async {
      await pumpToCompleted(
        tester,
        completedTask: baseTaskJson(
          bookingCode: 'TM-00240',
          status: 'completed',
          groupCode: 'GRP-6',
          groupVehicleCount: 6,
          groupPosition: 6,
        ),
        theme: AppTheme.dark,
        themeMode: ThemeMode.dark,
      );
      expect(tester.takeException(), isNull);
      expect(find.text('Service Complete'), findsOneWidget);
    });

    testWidgets('renders without overflow at 320px width for a grouped completion', (tester) async {
      final originalSize = tester.view.physicalSize;
      final originalRatio = tester.view.devicePixelRatio;
      tester.view.physicalSize = const Size(320, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() {
        tester.view.physicalSize = originalSize;
        tester.view.devicePixelRatio = originalRatio;
      });

      await pumpToCompleted(
        tester,
        completedTask: baseTaskJson(
          bookingCode: 'TM-00240',
          status: 'completed',
          groupCode: 'GRP-6',
          groupVehicleCount: 6,
          groupPosition: 6,
        ),
      );

      expect(tester.takeException(), isNull);
      expect(find.text('Service Complete'), findsOneWidget);
    });
  });
}
