import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:signature/signature.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/team_leader/tl_active_task_shell.dart';

Map<String, dynamic> taskJson({
  String bookingCode = 'TM-00100',
  String status = 'waiting_verification',
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
  String? paymentMethod,
  String? groupCode,
  int groupVehicleCount = 1,
  int groupPosition = 1,
  bool groupReadyForPayment = true,
  double? groupTotal,
  List<double> groupVehicleTotals = const [],
  List<Map<String, dynamic>>? groupVehicleBreakdown,
  double? groupAdjustment,
}) {
  return {
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
    'payment_method': paymentMethod,
    'group_code': groupCode,
    'group_vehicle_count': groupVehicleCount,
    'group_position': groupPosition,
    'group_ready_for_payment': groupReadyForPayment,
    'group_total': groupTotal,
    'group_vehicle_totals': groupVehicleTotals,
    'group_vehicle_breakdown': groupVehicleBreakdown,
    'group_adjustment': groupAdjustment,
  };
}

Map<String, dynamic> vehiclePricing({
  required double baseRate,
  required double distanceFee,
  required double vatAmount,
  required double finalTotal,
}) {
  return {
    'base_rate': baseRate,
    'distance_fee': distanceFee,
    'vat_amount': vatAmount,
    'final_total': finalTotal,
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

  http.StreamedResponse toStreamed(http.Response response, http.BaseRequest request) {
    return http.StreamedResponse(
      http.ByteStream.fromBytes(response.bodyBytes),
      response.statusCode,
      request: request,
      headers: response.headers,
    );
  }

  http.Client buildClient({
    required Map<String, dynamic> task,
    List<String>? statusRequestsLog,
  }) {
    return MockClient.streaming((request, bodyStream) async {
      final path = request.url.path;
      if (path.endsWith('/v1/team-leader/task') && request.method == 'GET') {
        return toStreamed(jsonResponse({'success': true, 'data': task}), request);
      }
      if (path.contains('/complete') && request.method == 'POST') {
        final updated = Map<String, dynamic>.from(task)
          ..['status'] = 'waiting_verification'
          ..['payment_method'] = 'cash';
        return toStreamed(jsonResponse({'success': true, 'data': updated}), request);
      }
      if (path.contains('/photo') && request.method == 'POST') {
        return toStreamed(
          jsonResponse({'success': true, 'path': 'proof.jpg', 'url': 'https://example.test/proof.jpg'}),
          request,
        );
      }
      if (path.contains('/status') && request.method == 'PATCH') {
        final bodyBytes = await bodyStream.toBytes();
        final bodyString = utf8.decode(bodyBytes);
        statusRequestsLog?.add(bodyString);
        final body = jsonDecode(bodyString) as Map<String, dynamic>;
        final updated = Map<String, dynamic>.from(task)..['status'] = body['status'];
        return toStreamed(jsonResponse({'success': true, 'data': updated}), request);
      }
      if (path.endsWith('/presence/ping') || path.endsWith('/presence/away')) {
        return toStreamed(jsonResponse({'success': true}), request);
      }
      return toStreamed(jsonResponse({'success': false}, status: 404), request);
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
        statusRequestsLog: statusRequestsLog,
      ),
    );
  }

  Future<void> signAndPay(
    WidgetTester tester, {
    String method = 'cash',
    String? cashAmount,
  }) async {
    final methodLabel = method == 'gcash' ? 'GCash' : method == 'bank_transfer' ? 'Bank Transfer' : 'Cash';
    await tester.ensureVisible(find.text(methodLabel));
    await tester.tap(find.text(methodLabel));
    await tester.pump();
    await tester.ensureVisible(find.byType(Signature));
    await tester.drag(find.byType(Signature), const Offset(60, 0));
    await tester.pump();
    if (method == 'cash' && cashAmount != null) {
      await tester.ensureVisible(find.byType(TextField));
      await tester.enterText(find.byType(TextField), cashAmount);
      await tester.pump();
    }
  }

  group('TlAwaitingConfirmScreen (Step 5 of 6) — single booking', () {
    testWidgets('shows the step header title and progress', (tester) async {
      await pumpShell(tester, task: taskJson());
      expect(find.text('Customer Verification'), findsOneWidget);
      expect(find.text('Step 5 of 6'), findsOneWidget);
      expect(find.text('5 / 6'), findsOneWidget);
    });

    testWidgets('shows Payment / Amount Due with the real amount for a single booking', (tester) async {
      await pumpShell(tester, task: taskJson(finalTotal: 4658.08));
      expect(find.text('Payment'), findsOneWidget);
      expect(find.text('Amount Due'), findsOneWidget);
      expect(find.text('₱4,658.08'), findsOneWidget);
      expect(find.text('Group Payment'), findsNothing);
    });

    testWidgets('does not show Group Payment when isGroupBooking but groupTotal is null', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-1',
          groupVehicleCount: 2,
          groupPosition: 1,
          groupTotal: null,
          finalTotal: 4658.08,
        ),
      );
      expect(find.text('Group Payment'), findsNothing);
      expect(find.text('Payment'), findsOneWidget);
      expect(find.text('₱4,658.08'), findsOneWidget);
    });

    testWidgets('does not show a redundant status pill in the header', (tester) async {
      await pumpShell(tester, task: taskJson());
      expect(find.text('Pending Payment'), findsNothing);
      expect(find.text('Live'), findsNothing);
    });

    testWidgets('Cash selection shows Cash Received and required Payment Proof', (tester) async {
      await pumpShell(tester, task: taskJson());
      await tester.ensureVisible(find.text('Cash'));
      await tester.tap(find.text('Cash'));
      await settle(tester);

      expect(find.text('Cash Received (₱)'), findsOneWidget);
      expect(find.text('Must be at least ₱4,658.08.'), findsOneWidget);
      expect(find.text('Payment Proof'), findsOneWidget);
      expect(find.text('Required'), findsOneWidget);
    });

    testWidgets('GCash shows required Payment Proof and hides Cash Received', (tester) async {
      await pumpShell(tester, task: taskJson());
      await tester.ensureVisible(find.text('GCash'));
      await tester.tap(find.text('GCash'));
      await settle(tester);

      expect(find.text('Payment Proof'), findsOneWidget);
      expect(find.text('Required'), findsOneWidget);
      expect(find.text('Cash Received (₱)'), findsNothing);
    });

    testWidgets('Bank Transfer shows required Payment Proof and hides Cash Received', (tester) async {
      await pumpShell(tester, task: taskJson());
      await tester.ensureVisible(find.text('Bank Transfer'));
      await tester.tap(find.text('Bank Transfer'));
      await settle(tester);

      expect(find.text('Payment Proof'), findsOneWidget);
      expect(find.text('Required'), findsOneWidget);
      expect(find.text('Cash Received (₱)'), findsNothing);
    });

    testWidgets('Complete Task stays disabled until signature, payment method, and (for cash) a covering amount are all provided', (tester) async {
      await pumpShell(tester, task: taskJson());

      var button = tester.widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Complete Task'));
      expect(button.onPressed, isNull);

      await tester.ensureVisible(find.byType(Signature));
      await tester.drag(find.byType(Signature), const Offset(60, 0));
      await tester.pump();
      button = tester.widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Complete Task'));
      expect(button.onPressed, isNull);

      await tester.ensureVisible(find.text('Cash'));
      await tester.tap(find.text('Cash'));
      await tester.pump();
      await tester.ensureVisible(find.byType(TextField));
      await tester.enterText(find.byType(TextField), '5000');
      await tester.pump();
      button = tester.widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Complete Task'));
      expect(button.onPressed, isNull);
    });

    testWidgets('Cash with a valid amount and signature, but NO proof, stays rejected', (tester) async {
      await pumpShell(tester, task: taskJson(finalTotal: 4658.08));
      await signAndPay(tester, method: 'cash', cashAmount: '5000');
      await settle(tester);

      final button = tester.widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Complete Task'));
      expect(button.onPressed, isNull);
    });

    testWidgets('Cash with an insufficient amount and signature stays rejected', (tester) async {
      await pumpShell(tester, task: taskJson(finalTotal: 4658.08));
      await signAndPay(tester, method: 'cash', cashAmount: '100');
      await settle(tester);

      expect(find.text('Must be at least ₱4,658.08.'), findsOneWidget);
      final button = tester.widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Complete Task'));
      expect(button.onPressed, isNull);
    });

    testWidgets('Complete Task stays disabled for GCash without uploaded proof even with signature', (tester) async {
      await pumpShell(tester, task: taskJson());
      await tester.ensureVisible(find.byType(Signature));
      await tester.drag(find.byType(Signature), const Offset(60, 0));
      await tester.ensureVisible(find.text('GCash'));
      await tester.tap(find.text('GCash'));
      await settle(tester);

      final button = tester.widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Complete Task'));
      expect(button.onPressed, isNull);
    });

    testWidgets('Complete Task stays disabled for Bank Transfer without uploaded proof even with signature', (tester) async {
      await pumpShell(tester, task: taskJson());
      await tester.ensureVisible(find.byType(Signature));
      await tester.drag(find.byType(Signature), const Offset(60, 0));
      await tester.ensureVisible(find.text('Bank Transfer'));
      await tester.tap(find.text('Bank Transfer'));
      await settle(tester);

      final button = tester.widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Complete Task'));
      expect(button.onPressed, isNull);
    });

    testWidgets('tapping the proof upload box opens the existing photo-source flow', (tester) async {
      await pumpShell(tester, task: taskJson());
      await tester.ensureVisible(find.text('GCash'));
      await tester.tap(find.text('GCash'));
      await settle(tester);

      await tester.ensureVisible(find.text('Tap to upload proof'));
      await tester.tap(find.text('Tap to upload proof'));
      await settle(tester);

      expect(find.text('Take Photo'), findsOneWidget);
      expect(find.text('Choose from Gallery'), findsOneWidget);
    });

    testWidgets('signature drawing sets the signature requirement and Clear resets it', (tester) async {
      await pumpShell(tester, task: taskJson());

      Color sigBorderColor() {
        final container = tester.widget<Container>(
          find.ancestor(of: find.byType(Signature), matching: find.byType(Container)).first,
        );
        final decoration = container.decoration as BoxDecoration;
        return (decoration.border as Border).top.color;
      }

      expect(sigBorderColor(), isNot(TmColors.yellow));

      await tester.ensureVisible(find.byType(Signature));
      await tester.drag(find.byType(Signature), const Offset(60, 0));
      await tester.pump();
      expect(sigBorderColor(), TmColors.yellow);

      await tester.ensureVisible(find.text('Clear'));
      await tester.tap(find.text('Clear'));
      await tester.pump();
      expect(sigBorderColor(), isNot(TmColors.yellow));
    });

    testWidgets('tapping a disabled Complete Task button is a no-op: no submission, no spinner', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(bookingCode: 'TM-00100'),
        andThen: (tester) async {
          await signAndPay(tester, method: 'cash', cashAmount: '5000');
          await settle(tester);

          final button = tester.widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Complete Task'));
          expect(button.onPressed, isNull);

          await tester.tap(find.widgetWithText(ElevatedButton, 'Complete Task'), warnIfMissed: false);
          await tester.pump();

          expect(find.byType(CircularProgressIndicator), findsNothing);
          expect(tester.takeException(), isNull);
        },
      );
    });

    testWidgets('Back sends the real reverse transition to arrived_dropoff', (tester) async {
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

      expect(log.any((b) => jsonDecode(b)['status'] == 'arrived_dropoff'), isTrue);
    });

    testWidgets('renders correctly in light mode', (tester) async {
      await pumpShell(tester, task: taskJson(), theme: AppTheme.light, themeMode: ThemeMode.light);
      expect(tester.takeException(), isNull);
      expect(find.text('Customer Verification'), findsOneWidget);
    });

    testWidgets('renders correctly in dark mode', (tester) async {
      await pumpShell(tester, task: taskJson(), theme: AppTheme.dark, themeMode: ThemeMode.dark);
      expect(tester.takeException(), isNull);
      expect(find.text('Customer Verification'), findsOneWidget);
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
        await tester.ensureVisible(find.text('GCash'));
        await tester.tap(find.text('GCash'));
        await settle(tester);

        expect(tester.takeException(), isNull);
      });
    }
  });

  group('TlAwaitingConfirmScreen — consolidated group payment', () {
    testWidgets('2-vehicle group shows Group Payment breakdown with real totals', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-2',
          groupVehicleCount: 2,
          groupTotal: 9316.16,
          groupVehicleTotals: const [4658.08, 4658.08],
          groupAdjustment: 0,
        ),
      );

      expect(find.text('Group Payment'), findsOneWidget);
      expect(find.text('2 vehicles'), findsOneWidget);
      expect(find.text('Vehicle 1'), findsOneWidget);
      expect(find.text('Vehicle 2'), findsOneWidget);
      expect(find.text('₱9,316.16'), findsOneWidget);
      expect(find.text('Adjustment'), findsNothing);
    });

    testWidgets('3-vehicle group with a positive adjustment renders correctly', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-3',
          groupVehicleCount: 3,
          groupTotal: 14174.24,
          groupVehicleTotals: const [4658.08, 4658.08, 4658.08],
          groupAdjustment: 200,
        ),
      );

      expect(find.text('3 vehicles'), findsOneWidget);
      expect(find.text('Vehicle 3'), findsOneWidget);
      expect(find.text('Adjustment'), findsOneWidget);
      expect(find.text('₱200.00'), findsOneWidget);
      expect(find.text('₱14,174.24'), findsOneWidget);
    });

    testWidgets('6-vehicle group with a negative adjustment renders without overflow', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-6',
          groupVehicleCount: 6,
          groupTotal: 27448.48,
          groupVehicleTotals: const [4658.08, 4658.08, 4658.08, 4658.08, 4658.08, 4658.08],
          groupAdjustment: -500,
        ),
      );

      expect(find.text('6 vehicles'), findsOneWidget);
      for (var i = 1; i <= 6; i++) {
        expect(find.text('Vehicle $i'), findsOneWidget);
      }
      expect(find.text('Adjustment'), findsOneWidget);
      expect(find.text('₱27,448.48'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    for (final width in [320.0, 340.0, 360.0, 375.0, 390.0, 412.0]) {
      testWidgets('6-vehicle group renders without overflow at $width px width', (tester) async {
        final originalSize = tester.view.physicalSize;
        final originalRatio = tester.view.devicePixelRatio;
        tester.view.physicalSize = Size(width, 1000);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(() {
          tester.view.physicalSize = originalSize;
          tester.view.devicePixelRatio = originalRatio;
        });

        await pumpShell(
          tester,
          task: taskJson(
            groupCode: 'GRP-6',
            groupVehicleCount: 6,
            groupTotal: 27448.48,
            groupVehicleTotals: const [4658.08, 4658.08, 4658.08, 4658.08, 4658.08, 4658.08],
            groupAdjustment: -500,
          ),
        );

        expect(tester.takeException(), isNull);
      });
    }

    testWidgets('a consolidated group still shows exactly one payment method row, one proof box, and one signature canvas', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-6',
          groupVehicleCount: 6,
          groupTotal: 27448.48,
          groupVehicleTotals: const [4658.08, 4658.08, 4658.08, 4658.08, 4658.08, 4658.08],
          groupAdjustment: 0,
        ),
      );

      expect(find.text('Payment Method'), findsOneWidget);
      expect(find.text('Cash'), findsOneWidget);
      expect(find.byType(Signature), findsOneWidget);

      await tester.ensureVisible(find.text('GCash'));
      await tester.tap(find.text('GCash'));
      await settle(tester);
      expect(find.text('Payment Proof'), findsOneWidget);
    });

    testWidgets('grouped Cash with a valid amount, signature, and NO proof stays rejected', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-2',
          groupVehicleCount: 2,
          groupTotal: 9316.16,
          groupVehicleTotals: const [4658.08, 4658.08],
          groupAdjustment: 0,
        ),
      );

      await tester.ensureVisible(find.text('Cash'));
      await tester.tap(find.text('Cash'));
      await tester.pump();
      await tester.ensureVisible(find.byType(TextField));
      await tester.enterText(find.byType(TextField), '9500');
      await tester.pump();
      await tester.ensureVisible(find.byType(Signature));
      await tester.drag(find.byType(Signature), const Offset(60, 0));
      await settle(tester);

      final button = tester.widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Complete Task'));
      expect(button.onPressed, isNull);
    });

  });

  group('TlAwaitingConfirmScreen — group vehicle pricing breakdown', () {
    testWidgets('2 vehicles initially show only compact rows, not the breakdown', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-2',
          groupVehicleCount: 2,
          groupTotal: 9316.16,
          groupVehicleTotals: const [4658.08, 4658.08],
          groupVehicleBreakdown: [
            vehiclePricing(baseRate: 2500, distanceFee: 1659, vatAmount: 499.08, finalTotal: 4658.08),
            vehiclePricing(baseRate: 2500, distanceFee: 1659, vatAmount: 499.08, finalTotal: 4658.08),
          ],
          groupAdjustment: 0,
        ),
      );

      expect(find.text('Vehicle 1'), findsOneWidget);
      expect(find.text('Vehicle 2'), findsOneWidget);
      expect(find.text('Base Rate'), findsNothing);
      expect(find.text('Distance Fee'), findsNothing);
      expect(find.text('VAT (12%)'), findsNothing);
      expect(find.text('Vehicle Total'), findsNothing);
      expect(find.text('₱4,658.08'), findsNWidgets(2));
      expect(find.text('₱9,316.16'), findsOneWidget);
    });

    testWidgets('tapping a vehicle row expands it to show Base Rate, Distance Fee, VAT, and Vehicle Total', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-2',
          groupVehicleCount: 2,
          groupTotal: 9316.16,
          groupVehicleTotals: const [4658.08, 4658.08],
          groupVehicleBreakdown: [
            vehiclePricing(baseRate: 2500, distanceFee: 1659, vatAmount: 499.08, finalTotal: 4658.08),
            vehiclePricing(baseRate: 2500, distanceFee: 1659, vatAmount: 499.08, finalTotal: 4658.08),
          ],
          groupAdjustment: 0,
        ),
      );

      await tester.tap(find.text('Vehicle 1'));
      await settle(tester);

      expect(find.text('Base Rate'), findsOneWidget);
      expect(find.text('Distance Fee'), findsOneWidget);
      expect(find.text('VAT (12%)'), findsOneWidget);
      expect(find.text('Vehicle Total'), findsOneWidget);
      expect(find.text('₱2,500.00'), findsOneWidget);
      expect(find.text('₱1,659.00'), findsOneWidget);
      expect(find.text('₱499.08'), findsOneWidget);
    });

    testWidgets('expanded vehicle header no longer duplicates the total shown in Vehicle Total', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-3',
          groupVehicleCount: 3,
          groupTotal: 13383.68,
          groupVehicleTotals: const [4278.40, 4658.08, 4447.20],
          groupVehicleBreakdown: [
            vehiclePricing(baseRate: 2500, distanceFee: 1320, vatAmount: 458.40, finalTotal: 4278.40),
            vehiclePricing(baseRate: 2500, distanceFee: 1659, vatAmount: 499.08, finalTotal: 4658.08),
            vehiclePricing(baseRate: 2200, distanceFee: 1770, vatAmount: 477.60, finalTotal: 4447.20),
          ],
          groupAdjustment: 0,
        ),
      );

      expect(find.text('₱4,278.40'), findsOneWidget);

      await tester.tap(find.text('Vehicle 1'));
      await settle(tester);

      expect(find.text('Vehicle Total'), findsOneWidget);
      expect(find.text('₱4,278.40'), findsOneWidget);
      expect(find.text('₱4,658.08'), findsOneWidget);
      expect(find.text('₱4,447.20'), findsOneWidget);
    });

    testWidgets('expanding one vehicle does not expand the others', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-3',
          groupVehicleCount: 3,
          groupTotal: 13383.68,
          groupVehicleTotals: const [4278.40, 4658.08, 4447.20],
          groupVehicleBreakdown: [
            vehiclePricing(baseRate: 2500, distanceFee: 1320, vatAmount: 458.40, finalTotal: 4278.40),
            vehiclePricing(baseRate: 2500, distanceFee: 1659, vatAmount: 499.08, finalTotal: 4658.08),
            vehiclePricing(baseRate: 2200, distanceFee: 1770, vatAmount: 477.60, finalTotal: 4447.20),
          ],
          groupAdjustment: 0,
        ),
      );

      await tester.tap(find.text('Vehicle 2'));
      await settle(tester);

      expect(find.text('Base Rate'), findsOneWidget);
      expect(find.text('₱2,500.00'), findsOneWidget);
      expect(find.text('₱1,659.00'), findsOneWidget);
      expect(find.text('₱499.08'), findsOneWidget);
      expect(find.text('₱1,320.00'), findsNothing);
      expect(find.text('₱1,770.00'), findsNothing);
    });

    testWidgets('tapping an expanded vehicle row collapses it again', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-2',
          groupVehicleCount: 2,
          groupTotal: 9316.16,
          groupVehicleTotals: const [4658.08, 4658.08],
          groupVehicleBreakdown: [
            vehiclePricing(baseRate: 2500, distanceFee: 1659, vatAmount: 499.08, finalTotal: 4658.08),
            vehiclePricing(baseRate: 2500, distanceFee: 1659, vatAmount: 499.08, finalTotal: 4658.08),
          ],
          groupAdjustment: 0,
        ),
      );

      await tester.tap(find.text('Vehicle 1'));
      await settle(tester);
      expect(find.text('Base Rate'), findsOneWidget);

      await tester.tap(find.text('Vehicle 1'));
      await settle(tester);
      expect(find.text('Base Rate'), findsNothing);
    });

    testWidgets('6 vehicles render collapsed without overflow, and expand cleanly', (tester) async {
      final breakdown = List.generate(
        6,
        (_) => vehiclePricing(baseRate: 2500, distanceFee: 1320, vatAmount: 458.40, finalTotal: 4278.40),
      );

      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-6',
          groupVehicleCount: 6,
          groupTotal: 25870.40,
          groupVehicleTotals: List.filled(6, 4278.40),
          groupVehicleBreakdown: breakdown,
          groupAdjustment: 200,
        ),
      );

      for (var i = 1; i <= 6; i++) {
        expect(find.text('Vehicle $i'), findsOneWidget);
      }
      expect(find.text('Base Rate'), findsNothing);
      expect(find.text('Adjustment'), findsOneWidget);
      expect(find.text('₱200.00'), findsOneWidget);
      expect(find.text('₱25,870.40'), findsOneWidget);
      expect(tester.takeException(), isNull);

      await tester.tap(find.text('Vehicle 6'));
      await settle(tester);
      expect(find.text('Base Rate'), findsOneWidget);
      expect(find.text('Vehicle Total'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('positive adjustment renders as a real value with no sign fabrication', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-2',
          groupVehicleCount: 2,
          groupTotal: 9516.16,
          groupVehicleTotals: const [4658.08, 4658.08],
          groupVehicleBreakdown: [
            vehiclePricing(baseRate: 2500, distanceFee: 1659, vatAmount: 499.08, finalTotal: 4658.08),
            vehiclePricing(baseRate: 2500, distanceFee: 1659, vatAmount: 499.08, finalTotal: 4658.08),
          ],
          groupAdjustment: 200,
        ),
      );

      expect(find.text('Adjustment'), findsOneWidget);
      expect(find.text('₱200.00'), findsOneWidget);
    });

    testWidgets('negative adjustment renders correctly', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-2',
          groupVehicleCount: 2,
          groupTotal: 8816.16,
          groupVehicleTotals: const [4658.08, 4658.08],
          groupVehicleBreakdown: [
            vehiclePricing(baseRate: 2500, distanceFee: 1659, vatAmount: 499.08, finalTotal: 4658.08),
            vehiclePricing(baseRate: 2500, distanceFee: 1659, vatAmount: 499.08, finalTotal: 4658.08),
          ],
          groupAdjustment: -500,
        ),
      );

      expect(find.text('Adjustment'), findsOneWidget);
      expect(find.text('₱8,816.16'), findsOneWidget);
    });

    testWidgets('zero adjustment omits the Adjustment row', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-2',
          groupVehicleCount: 2,
          groupTotal: 9316.16,
          groupVehicleTotals: const [4658.08, 4658.08],
          groupVehicleBreakdown: [
            vehiclePricing(baseRate: 2500, distanceFee: 1659, vatAmount: 499.08, finalTotal: 4658.08),
            vehiclePricing(baseRate: 2500, distanceFee: 1659, vatAmount: 499.08, finalTotal: 4658.08),
          ],
          groupAdjustment: 0,
        ),
      );

      expect(find.text('Adjustment'), findsNothing);
    });

    testWidgets('falls back to the simple per-vehicle total when breakdown is null', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-2',
          groupVehicleCount: 2,
          groupTotal: 9316.16,
          groupVehicleTotals: const [4658.08, 4658.08],
          groupVehicleBreakdown: null,
          groupAdjustment: 0,
        ),
      );

      expect(find.text('Vehicle 1'), findsOneWidget);
      expect(find.text('Vehicle 2'), findsOneWidget);
      expect(find.text('Base Rate'), findsNothing);
      expect(find.text('Distance Fee'), findsNothing);
      expect(find.text('VAT (12%)'), findsNothing);
      expect(find.text('₱4,658.08'), findsNWidgets(2));
      expect(find.text('₱9,316.16'), findsOneWidget);
    });

    testWidgets('falls back to the simple per-vehicle total when breakdown is an empty list', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-2',
          groupVehicleCount: 2,
          groupTotal: 9316.16,
          groupVehicleTotals: const [4658.08, 4658.08],
          groupVehicleBreakdown: const [],
          groupAdjustment: 0,
        ),
      );

      expect(find.text('Base Rate'), findsNothing);
      expect(find.text('Vehicle 1'), findsOneWidget);
    });

    testWidgets('single-vehicle Payment card is unaffected by breakdown support', (tester) async {
      await pumpShell(tester, task: taskJson(finalTotal: 4658.08));

      expect(find.text('Payment'), findsOneWidget);
      expect(find.text('Amount Due'), findsOneWidget);
      expect(find.text('₱4,658.08'), findsOneWidget);
      expect(find.text('Group Payment'), findsNothing);
      expect(find.text('Base Rate'), findsNothing);
    });

    for (final width in [320.0, 340.0, 360.0, 375.0, 390.0, 412.0]) {
      testWidgets('6-vehicle breakdown renders without overflow at $width px width', (tester) async {
        final originalSize = tester.view.physicalSize;
        final originalRatio = tester.view.devicePixelRatio;
        tester.view.physicalSize = Size(width, 1400);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(() {
          tester.view.physicalSize = originalSize;
          tester.view.devicePixelRatio = originalRatio;
        });

        final breakdown = List.generate(
          6,
          (_) => vehiclePricing(baseRate: 2500, distanceFee: 1320, vatAmount: 458.40, finalTotal: 4278.40),
        );

        await pumpShell(
          tester,
          task: taskJson(
            groupCode: 'GRP-6',
            groupVehicleCount: 6,
            groupTotal: 25870.40,
            groupVehicleTotals: List.filled(6, 4278.40),
            groupVehicleBreakdown: breakdown,
            groupAdjustment: 200,
          ),
        );

        expect(tester.takeException(), isNull);
      });
    }

    testWidgets('renders correctly in light mode with an expanded breakdown', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-3',
          groupVehicleCount: 3,
          groupTotal: 13035.20,
          groupVehicleTotals: const [4278.40, 4278.40, 4278.40],
          groupVehicleBreakdown: [
            vehiclePricing(baseRate: 2500, distanceFee: 1320, vatAmount: 458.40, finalTotal: 4278.40),
            vehiclePricing(baseRate: 2500, distanceFee: 1320, vatAmount: 458.40, finalTotal: 4278.40),
            vehiclePricing(baseRate: 2500, distanceFee: 1320, vatAmount: 458.40, finalTotal: 4278.40),
          ],
          groupAdjustment: 200,
        ),
        theme: AppTheme.light,
        themeMode: ThemeMode.light,
      );

      for (var i = 1; i <= 3; i++) {
        await tester.ensureVisible(find.text('Vehicle $i'));
        await tester.tap(find.text('Vehicle $i'));
        await settle(tester);
      }
      expect(tester.takeException(), isNull);
      expect(find.text('Vehicle Total'), findsNWidgets(3));
    });

    testWidgets('renders correctly in dark mode with an expanded breakdown', (tester) async {
      await pumpShell(
        tester,
        task: taskJson(
          groupCode: 'GRP-3',
          groupVehicleCount: 3,
          groupTotal: 13035.20,
          groupVehicleTotals: const [4278.40, 4278.40, 4278.40],
          groupVehicleBreakdown: [
            vehiclePricing(baseRate: 2500, distanceFee: 1320, vatAmount: 458.40, finalTotal: 4278.40),
            vehiclePricing(baseRate: 2500, distanceFee: 1320, vatAmount: 458.40, finalTotal: 4278.40),
            vehiclePricing(baseRate: 2500, distanceFee: 1320, vatAmount: 458.40, finalTotal: 4278.40),
          ],
          groupAdjustment: 200,
        ),
        theme: AppTheme.dark,
        themeMode: ThemeMode.dark,
      );

      for (var i = 1; i <= 3; i++) {
        await tester.ensureVisible(find.text('Vehicle $i'));
        await tester.tap(find.text('Vehicle $i'));
        await settle(tester);
      }
      expect(tester.takeException(), isNull);
      expect(find.text('Vehicle Total'), findsNWidgets(3));
    });
  });
}
