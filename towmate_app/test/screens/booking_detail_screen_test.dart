import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/customer/booking_detail_screen.dart';
import 'package:towmate_app/widgets/skeleton_box.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

Map<String, dynamic> _detail({
  String code = 'TM-00225',
  String status = 'requested',
  String serviceType = 'book_now',
  double baseRate = 2500.0,
  double perKmRate = 60.0,
  double distanceKm = 1.21,
  double? distanceFee,
  double vatAmount = 300.0,
  double? additionalFee,
  double? finalTotal,
  double? computedTotal,
  String? vehicleTypeName,
  bool pricingIsProvisional = false,
  String? groupCode,
  List<Map<String, dynamic>>? groupSiblings,
  String? groupBookingCode,
  String? quotationNumber,
  Map<String, dynamic>? groupTotals,
  List<Map<String, dynamic>>? groupVehicles,
}) {
  return {
    'success': true,
    'data': {
      'booking_code': code,
      'status': status,
      'service_type': serviceType,
      'pickup_address': 'Sumilang Street, Pasig',
      'pickup_lat': 14.5, 'pickup_lng': 121.0,
      'dropoff_address': 'Quirino Highway, QC',
      'dropoff_lat': 14.6, 'dropoff_lng': 121.1,
      'distance_km': distanceKm,
      'pickup_notes': null,
      'truck_type_id': 1,
      'truck_type_name': 'Light Duty',
      'truck_type_class': 'light',
      'vehicle_type_name': vehicleTypeName,
      'base_rate': baseRate,
      'per_km_rate': perKmRate,
      'distance_fee': pricingIsProvisional ? null : (distanceFee ?? 0.0),
      'pricing_is_provisional': pricingIsProvisional,
      'computed_total': computedTotal ?? (baseRate + (distanceFee ?? 0.0)),
      'additional_fee': additionalFee,
      'vat_amount': vatAmount,
      'final_total': finalTotal ?? (baseRate + (distanceFee ?? 0.0) + vatAmount + (additionalFee ?? 0.0)),
      'payment_method': null,
      'scheduled_date': null,
      'scheduled_time': null,
      'scheduled_for': null,
      'scheduling_bucket': null,
      'team_leader_name': null,
      'driver_name': null,
      'arrival_photo_url': null,
      'dropoff_photo_url': null,
      'created_at': '2026-09-01 10:00:00',
      'completed_at': null,
      'cancelled_at': null,
      'price_change_log': [],
      'group_code': groupCode,
      'group_booking_code': groupBookingCode ?? code,
      'group_siblings': groupSiblings ?? [],
      'group_totals': groupTotals,
      'group_vehicles': groupVehicles ?? [],
      'quotation_number': quotationNumber,
    },
  };
}

http.Client _clientFor(
  Map<String, dynamic> detail, {
  bool cancelSucceeds = true,
  void Function()? onCancelCalled,
}) {
  return MockClient((request) async {
    final path = request.url.path;
    if (path.contains('/cancel')) {
      onCancelCalled?.call();
      return cancelSucceeds
          ? _json({'success': true, 'message': 'Booking cancelled successfully.'})
          : _json({'success': false, 'message': 'Could not cancel.'}, status: 422);
    }
    if (path.contains('/detail')) return _json(detail);
    return _json({'success': false}, status: 404);
  });
}

http.Client _clientForGroupCancel({
  required List<Map<String, dynamic>> detailResponses,
  required Map<String, dynamic> cancelResponse,
  int cancelStatus = 200,
  void Function(Map<String, dynamic> body)? onCancelCalled,
}) {
  var detailCallCount = 0;
  return MockClient((request) async {
    final path = request.url.path;
    if (path.contains('/bookings/group/') && path.endsWith('/cancel')) {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      onCancelCalled?.call(body);
      return _json(cancelResponse, status: cancelStatus);
    }
    if (path.contains('/detail')) {
      final index = detailCallCount < detailResponses.length ? detailCallCount : detailResponses.length - 1;
      detailCallCount++;
      return _json(detailResponses[index]);
    }
    return _json({'success': false}, status: 404);
  });
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pumpScreen(
  WidgetTester tester,
  http.Client client, {
  String code = 'TM-00225',
  bool asGroupOverview = false,
}) async {
  SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
  await http.runWithClient(
    () async {
      await tester.pumpWidget(MaterialApp(
        home: BookingDetailScreen(bookingCode: code, asGroupOverview: asGroupOverview),
      ));
      await _settle(tester);
    },
    () => client,
  );
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  group('BookingDetailScreen', () {
    testWidgets('shows the Distance Fee row as ₱0.00 when the trip is within the free 4 km', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail(distanceFee: 0.0, distanceKm: 1.21)));

      expect(find.textContaining('Distance Fee'), findsOneWidget);
      expect(find.textContaining('1.21 km'), findsOneWidget);
      expect(find.text('₱0.00'), findsOneWidget);
      expect(find.text('First 4 km included.'), findsOneWidget);

      final labelLeft = tester.getTopLeft(find.textContaining('Distance Fee')).dx;
      final helperLeft = tester.getTopLeft(find.text('First 4 km included.')).dx;
      expect(helperLeft, closeTo(labelLeft, 0.5));

      final labelTop = tester.getBottomLeft(find.textContaining('Distance Fee')).dy;
      final helperTop = tester.getTopLeft(find.text('First 4 km included.')).dy;
      expect(helperTop, greaterThanOrEqualTo(labelTop));
      expect(helperTop - labelTop, lessThan(12));

      final amountLeft = tester.getTopLeft(find.text('₱0.00')).dx;
      expect(amountLeft, greaterThan(helperLeft));
    });

    testWidgets('shows a real nonzero Distance Fee for a trip beyond 4 km, without the included-km hint', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail(distanceFee: 360.0, distanceKm: 10.0, perKmRate: 60.0)));

      expect(find.textContaining('Distance Fee'), findsOneWidget);
      expect(find.text('₱360.00'), findsOneWidget);
      expect(find.text('First 4 km included.'), findsNothing);
    });

    testWidgets('Base Rate, VAT and Total reflect the authoritative server response, not a Flutter recalculation', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          baseRate: 2500.0,
          distanceFee: 0.0,
          vatAmount: 300.0,
          finalTotal: 2800.0,
        )),
      );

      expect(find.text('₱2,500.00'), findsOneWidget);
      expect(find.text('₱300.00'), findsOneWidget);
      expect(find.text('₱2,800.00'), findsOneWidget);
    });

    testWidgets('shows a separate Price Adjustment-style Additional Fee line only when one actually exists', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail(additionalFee: 150.0, finalTotal: 2950.0)));
      expect(find.text('Additional Fee'), findsOneWidget);
      expect(find.text('₱150.00'), findsOneWidget);
    });

    testWidgets('does not show an Additional Fee line when none exists', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail()));
      expect(find.text('Additional Fee'), findsNothing);
    });

    testWidgets('renders the TowMate wordmark with Tow in the primary color and Mate in yellow', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail()));

      final richTextFinder = find.byWidgetPredicate(
        (w) => w is RichText && w.text.toPlainText() == 'TowMate',
      );
      expect(richTextFinder, findsOneWidget);

      final richText = tester.widget<RichText>(richTextFinder);
      final span = richText.text as TextSpan;
      final children = span.children!;
      expect((children[0] as TextSpan).text, 'Tow');
      expect((children[1] as TextSpan).text, 'Mate');
      expect((children[1] as TextSpan).style?.color, TmColors.yellow);
      expect((children[0] as TextSpan).style?.color, isNot(TmColors.yellow));
    });

    testWidgets('an active job status (e.g. on_the_way) shows no Cancel Booking control', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail(status: 'on_the_way')));
      expect(find.text('Cancel Booking'), findsNothing);
    });

    testWidgets('a requested booking shows a solid destructive Cancel Booking button', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail(status: 'requested')));

      final button = find.widgetWithText(ElevatedButton, 'Cancel Booking');
      expect(button, findsOneWidget);
      final widget = tester.widget<ElevatedButton>(button);
      final bg = widget.style?.backgroundColor?.resolve({});
      expect(bg, TmColors.destructive);
    });

    testWidgets('tapping Cancel Booking requires confirmation before calling the API', (tester) async {
      final originalSize = tester.view.physicalSize;
      final originalRatio = tester.view.devicePixelRatio;
      tester.view.physicalSize = const Size(400, 1400);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() {
        tester.view.physicalSize = originalSize;
        tester.view.devicePixelRatio = originalRatio;
      });

      var cancelCalled = false;
      await http.runWithClient(
        () async {
          SharedPreferences.setMockInitialValues({'auth_token': 't', 'user_role': 'Customer'});
          await tester.pumpWidget(
            MaterialApp(home: BookingDetailScreen(bookingCode: 'TM-00225')),
          );
          await _settle(tester);

          await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
          await _settle(tester);

          expect(cancelCalled, isFalse);
          expect(find.text('Cancel this booking?'), findsOneWidget);
          expect(find.text('TM-00225'), findsWidgets);
        },
        () => _clientFor(_detail(status: 'requested'), onCancelCalled: () => cancelCalled = true),
      );
    });

    testWidgets('confirming cancellation calls the real cancel API', (tester) async {
      final originalSize = tester.view.physicalSize;
      final originalRatio = tester.view.devicePixelRatio;
      tester.view.physicalSize = const Size(400, 1400);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() {
        tester.view.physicalSize = originalSize;
        tester.view.devicePixelRatio = originalRatio;
      });

      var cancelCalled = false;
      await http.runWithClient(
        () async {
          SharedPreferences.setMockInitialValues({'auth_token': 't', 'user_role': 'Customer'});
          await tester.pumpWidget(
            MaterialApp(home: BookingDetailScreen(bookingCode: 'TM-00225')),
          );
          await _settle(tester);

          await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
          await _settle(tester);
          await tester.tap(find.widgetWithText(TextButton, 'Cancel Booking'));
          await _settle(tester);
        },
        () => _clientFor(_detail(status: 'requested'), onCancelCalled: () => cancelCalled = true),
      );

      expect(cancelCalled, isTrue);
    });

    testWidgets('dismissing with Keep Booking performs no cancellation', (tester) async {
      final originalSize = tester.view.physicalSize;
      final originalRatio = tester.view.devicePixelRatio;
      tester.view.physicalSize = const Size(400, 1400);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() {
        tester.view.physicalSize = originalSize;
        tester.view.devicePixelRatio = originalRatio;
      });

      var cancelCalled = false;
      await http.runWithClient(
        () async {
          SharedPreferences.setMockInitialValues({'auth_token': 't', 'user_role': 'Customer'});
          await tester.pumpWidget(
            MaterialApp(home: BookingDetailScreen(bookingCode: 'TM-00225')),
          );
          await _settle(tester);

          await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
          await _settle(tester);
          await tester.tap(find.text('Keep Booking'));
          await _settle(tester);
        },
        () => _clientFor(_detail(status: 'requested'), onCancelCalled: () => cancelCalled = true),
      );

      expect(cancelCalled, isFalse);
    });

    testWidgets('a Scheduled sibling with provisional pricing never shows a misleading Distance Fee', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          serviceType: 'schedule',
          baseRate: 1500.0,
          vatAmount: 180.0,
          finalTotal: 1680.0,
          pricingIsProvisional: true,
        )),
      );

      expect(find.text('Distance Fee'), findsNothing);
      expect(find.textContaining('Distance Fee'), findsNothing);
      expect(find.text('Estimated Base Rate'), findsOneWidget);
      expect(find.text('Estimated VAT'), findsOneWidget);
      expect(find.text('Estimated Total'), findsOneWidget);
      expect(find.text('₱1,500.00'), findsOneWidget);
      expect(find.text('₱180.00'), findsOneWidget);
      expect(find.text('₱1,680.00'), findsWidgets);
      expect(
        find.textContaining('Scheduled vehicle pricing may change after quotation review.'),
        findsOneWidget,
      );
    });

    testWidgets('a Book Now booking keeps the canonical Distance Fee breakdown, not the provisional card', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          serviceType: 'book_now',
          baseRate: 1500.0,
          distanceFee: 492.0,
          distanceKm: 8.2,
          vatAmount: 239.04,
          finalTotal: 2231.04,
          pricingIsProvisional: false,
        )),
      );

      expect(find.textContaining('Distance Fee'), findsOneWidget);
      expect(find.text('₱492.00'), findsOneWidget);
      expect(find.text('Estimated Base Rate'), findsNothing);
    });

    testWidgets('shows the customer-facing Vehicle Type instead of the internal Truck Type when available', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail(vehicleTypeName: 'Sedan')));

      expect(find.textContaining('Sedan'), findsWidgets);
      expect(find.textContaining('Light Duty'), findsNothing);
    });

    testWidgets('a grouped booking shows a THIS REQUEST section listing its siblings', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00227',
          vehicleTypeName: 'Sedan',
          groupCode: 'GRP-1',
          groupSiblings: [
            {
              'booking_code': 'TM-00228',
              'vehicle_type_name': 'Motorcycle',
              'truck_type_name': 'Light Duty',
              'service_type': 'schedule',
              'status': 'scheduled',
              'scheduled_date': '2026-09-17',
              'scheduled_time': '13:00',
              'scheduled_for': null,
              'is_current': false,
            },
          ],
        )),
      );

      expect(find.text('THIS REQUEST'), findsOneWidget);
      expect(find.text('Vehicle 1'), findsOneWidget);
      expect(find.text('Vehicle 2'), findsOneWidget);
      expect(find.textContaining('TM-00227'), findsWidgets);
      expect(find.textContaining('TM-00228'), findsWidgets);
      expect(find.text('You are here'), findsOneWidget);
    });

    testWidgets('tapping a sibling in THIS REQUEST navigates to its own booking detail', (tester) async {
      String? capturedCode;
      await http.runWithClient(() async {
        SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
        await tester.pumpWidget(
          MaterialApp(
            onGenerateRoute: (settings) {
              if (settings.name == '/booking-detail') {
                capturedCode = settings.arguments as String?;
                return MaterialPageRoute(builder: (_) => const Scaffold());
              }
              return MaterialPageRoute(builder: (_) => const BookingDetailScreen(bookingCode: 'TM-00227'));
            },
          ),
        );
        await _settle(tester);

        await tester.ensureVisible(find.textContaining('TM-00228'));
        await tester.tap(find.textContaining('TM-00228'));
        await _settle(tester);
      }, () => _clientFor(_detail(
            code: 'TM-00227',
            groupCode: 'GRP-1',
            groupSiblings: [
              {
                'booking_code': 'TM-00228',
                'vehicle_type_name': 'Motorcycle',
                'service_type': 'schedule',
                'status': 'scheduled',
                'scheduled_date': '2026-09-17',
                'scheduled_time': '13:00',
                'is_current': false,
              },
            ],
          )));

      expect(capturedCode, 'TM-00228');
    });

    testWidgets('an individually opened sibling vehicle shows only its own booking code and vehicle type, never the group anchor code', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00228',
          groupCode: 'GRP-1',
          groupBookingCode: 'TM-00227',
          vehicleTypeName: 'Sedan',
        )),
        code: 'TM-00228',
      );

      expect(find.text('TM-00228 · Sedan'), findsOneWidget);
      expect(find.text('TM-00227'), findsNothing);
      expect(find.textContaining('Vehicle reference'), findsNothing);
    });

    testWidgets('a standalone booking shows its own code and vehicle type with no vehicle-reference line', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail(code: 'TM-00225', vehicleTypeName: 'Sedan')));

      expect(find.text('TM-00225 · Sedan'), findsOneWidget);
      expect(find.textContaining('Vehicle reference:'), findsNothing);
    });

    testWidgets('shows the shared quotation number in the service details', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail(quotationNumber: 'QT-2026-0042')));

      expect(find.text('Quotation No.'), findsOneWidget);
      expect(find.text('QT-2026-0042'), findsOneWidget);
    });

    testWidgets('does not show THIS REQUEST for a standalone booking', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail()));
      expect(find.text('THIS REQUEST'), findsNothing);
    });

    testWidgets('the group overview page shows the combined group total, not just one vehicle\'s share', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00227',
          groupCode: 'GRP-1',
          baseRate: 1500.0,
          distanceFee: 0.0,
          vatAmount: 180.0,
          finalTotal: 1680.0,
          groupSiblings: [
            {
              'booking_code': 'TM-00228',
              'vehicle_type_name': 'Motorcycle',
              'service_type': 'book_now',
              'status': 'requested',
              'is_current': false,
            },
          ],
          groupTotals: {
            'vehicle_count': 2,
            'base_rate': 2500.0,
            'computed_total': 2500.0,
            'vat_amount': 300.0,
            'additional_fee': 0.0,
            'final_total': 2800.0,
          },
          groupVehicles: [
            {
              'booking_code': 'TM-00227',
              'status': 'requested',
              'vehicle_type_name': 'Sedan',
              'base_rate': 1500.0,
              'distance_fee': 0.0,
              'vat_amount': 180.0,
              'final_total': 1680.0,
              'pricing_is_provisional': false,
            },
            {
              'booking_code': 'TM-00228',
              'status': 'requested',
              'vehicle_type_name': 'Motorcycle',
              'base_rate': 1000.0,
              'distance_fee': 0.0,
              'vat_amount': 120.0,
              'final_total': 1120.0,
              'pricing_is_provisional': false,
            },
          ],
        )),
        asGroupOverview: true,
      );

      expect(find.text('Group Total (2 vehicles)'), findsOneWidget);
      expect(find.text('₱2,800.00'), findsOneWidget);
      expect(find.text('Total Amount'), findsNothing);
      expect(find.text('₱1,680.00'), findsWidgets);
      expect(find.textContaining('combined · 2 vehicles'), findsOneWidget);
      expect(find.text('You are here'), findsNothing);
      expect(find.textContaining('TM-00227'), findsWidgets);
      expect(find.textContaining('TM-00228'), findsWidgets);
    });

    testWidgets('a grouped provisional group overview shows an estimated combined total', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00227',
          serviceType: 'schedule',
          groupCode: 'GRP-1',
          baseRate: 1500.0,
          vatAmount: 180.0,
          finalTotal: 1680.0,
          pricingIsProvisional: true,
          groupSiblings: [
            {
              'booking_code': 'TM-00228',
              'vehicle_type_name': 'Motorcycle',
              'service_type': 'schedule',
              'status': 'scheduled',
              'is_current': false,
            },
          ],
          groupTotals: {
            'vehicle_count': 2,
            'base_rate': 2500.0,
            'computed_total': 2500.0,
            'vat_amount': 300.0,
            'additional_fee': 0.0,
            'final_total': 2800.0,
          },
        )),
        asGroupOverview: true,
      );

      expect(find.text('Group Total (2 vehicles)'), findsOneWidget);
      expect(find.textContaining('Estimated Base Rate'), findsOneWidget);
      expect(find.textContaining('combined · 2 vehicles'), findsOneWidget);
      expect(find.text('₱2,500.00'), findsOneWidget);
      expect(find.text('₱2,800.00'), findsWidgets);
      expect(find.text('₱1,680.00'), findsNothing);
    });

    testWidgets('opening a grouped booking individually shows only its own share, not the group total', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00227',
          groupCode: 'GRP-1',
          baseRate: 1500.0,
          distanceFee: 0.0,
          vatAmount: 180.0,
          finalTotal: 1680.0,
          groupSiblings: [
            {
              'booking_code': 'TM-00228',
              'vehicle_type_name': 'Motorcycle',
              'service_type': 'book_now',
              'status': 'requested',
              'is_current': false,
            },
          ],
          groupTotals: {
            'vehicle_count': 2,
            'base_rate': 2500.0,
            'computed_total': 2500.0,
            'vat_amount': 300.0,
            'additional_fee': 0.0,
            'final_total': 2800.0,
          },
        )),
        code: 'TM-00227',
      );

      expect(find.text('Total Amount'), findsOneWidget);
      expect(find.text('₱1,680.00'), findsOneWidget);
      expect(find.textContaining('Group Total'), findsNothing);
      expect(find.text('₱2,800.00'), findsNothing);
    });

    testWidgets('tapping a vehicle in the group overview opens its own individual booking detail', (tester) async {
      Object? capturedArgs;
      await http.runWithClient(() async {
        SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
        await tester.pumpWidget(
          MaterialApp(
            onGenerateRoute: (settings) {
              if (settings.name == '/booking-detail' && settings.arguments != 'TM-00227') {
                capturedArgs = settings.arguments;
                return MaterialPageRoute(builder: (_) => const Scaffold());
              }
              return MaterialPageRoute(
                builder: (_) => const BookingDetailScreen(bookingCode: 'TM-00227', asGroupOverview: true),
              );
            },
          ),
        );
        await _settle(tester);

        await tester.ensureVisible(find.textContaining('TM-00228').first);
        await tester.tap(find.textContaining('TM-00228').first);
        await _settle(tester);
      }, () => _clientFor(_detail(
            code: 'TM-00227',
            groupCode: 'GRP-1',
            groupSiblings: [
              {
                'booking_code': 'TM-00228',
                'vehicle_type_name': 'Motorcycle',
                'service_type': 'book_now',
                'status': 'requested',
                'is_current': false,
              },
            ],
            groupTotals: {
              'vehicle_count': 2,
              'base_rate': 2500.0,
              'computed_total': 2500.0,
              'vat_amount': 300.0,
              'additional_fee': 0.0,
              'final_total': 2800.0,
            },
            groupVehicles: [
              {
                'booking_code': 'TM-00227',
                'status': 'requested',
                'vehicle_type_name': 'Sedan',
                'final_total': 1680.0,
              },
              {
                'booking_code': 'TM-00228',
                'status': 'requested',
                'vehicle_type_name': 'Motorcycle',
                'final_total': 1120.0,
              },
            ],
          )));

      expect(capturedArgs, 'TM-00228');
    });

    testWidgets('a standalone booking is never affected by group total fields', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail(finalTotal: 1680.0)), asGroupOverview: true);

      expect(find.text('Total Amount'), findsOneWidget);
      expect(find.textContaining('Group Total'), findsNothing);
    });

    testWidgets('uses a skeleton shape while loading, not a spinner', (tester) async {
      SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
      final client = MockClient((request) async {
        await Future<void>.delayed(const Duration(milliseconds: 200));
        return _json(_detail());
      });

      await http.runWithClient(() async {
        await tester.pumpWidget(const MaterialApp(home: BookingDetailScreen(bookingCode: 'TM-00225')));
        await tester.pump();

        expect(find.byType(CircularProgressIndicator), findsNothing);
        expect(find.byType(SkeletonBox), findsWidgets);

        await _settle(tester);
        await tester.pump(const Duration(milliseconds: 200));
      }, () => client);
    });

    for (final width in [320.0, 340.0, 360.0, 375.0, 390.0, 412.0]) {
      testWidgets('renders without horizontal overflow at $width px width', (tester) async {
        final originalSize = tester.view.physicalSize;
        final originalRatio = tester.view.devicePixelRatio;
        tester.view.physicalSize = Size(width, 900);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(() {
          tester.view.physicalSize = originalSize;
          tester.view.devicePixelRatio = originalRatio;
        });

        await _pumpScreen(
          tester,
          _clientFor(_detail(
            distanceFee: 360.0,
            distanceKm: 10.0,
            additionalFee: 150.0,
            finalTotal: 2950.0,
          )..update('data', (d) {
              (d as Map<String, dynamic>)['pickup_address'] =
                  'A very long pickup address that should wrap gracefully, Barangay Something, Pasig City';
              d['dropoff_address'] =
                  'An equally long drop-off address somewhere far away, Quezon City, Metro Manila';
              return d;
            })),
        );

        expect(tester.takeException(), isNull);
      });
    }

    testWidgets('the group header shows a request-level heading and the group reference, not an individual booking code', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00227',
          groupCode: 'GRP-000042',
          groupVehicles: [
            {'booking_code': 'TM-00227', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
            {'booking_code': 'TM-00228', 'status': 'requested', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
          ],
        )),
        asGroupOverview: true,
      );

      expect(find.text('Group Request'), findsOneWidget);
      expect(find.text('GRP-000042'), findsOneWidget);
      expect(find.text('2 of 2 vehicles active'), findsOneWidget);
      expect(find.textContaining('Vehicle reference'), findsNothing);
    });

    testWidgets('the group header shows Cancelled once every vehicle in the group is cancelled', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00227',
          status: 'cancelled',
          groupCode: 'GRP-000042',
          groupVehicles: [
            {'booking_code': 'TM-00227', 'status': 'cancelled', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
            {'booking_code': 'TM-00228', 'status': 'cancelled', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
          ],
        )),
        asGroupOverview: true,
      );

      expect(find.text('Cancelled'), findsOneWidget);
      expect(find.widgetWithText(ElevatedButton, 'Cancel Booking'), findsNothing);
    });

    testWidgets('the group overview omits the single-vehicle info row below Trip Details', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00227',
          groupCode: 'GRP-1',
          groupVehicles: [
            {'booking_code': 'TM-00227', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
            {'booking_code': 'TM-00228', 'status': 'requested', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
          ],
        )),
        asGroupOverview: true,
      );

      expect(find.text('Light Duty'), findsNothing);
    });

    testWidgets('an individually opened booking still shows its own vehicle info row below Trip Details', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail()));
      expect(find.text('Light Duty'), findsOneWidget);
    });

    testWidgets('shows a single Cancel Booking action on the group overview when at least one vehicle is eligible', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00227',
          groupCode: 'GRP-1',
          groupVehicles: [
            {'booking_code': 'TM-00227', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
            {'booking_code': 'TM-00228', 'status': 'on_the_way', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
          ],
        )),
        asGroupOverview: true,
      );

      expect(find.widgetWithText(ElevatedButton, 'Cancel Booking'), findsOneWidget);
    });

    testWidgets('does not show a group Cancel Booking action for an individually opened vehicle page', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00227',
          status: 'requested',
          groupCode: 'GRP-1',
          groupVehicles: [
            {'booking_code': 'TM-00227', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
            {'booking_code': 'TM-00228', 'status': 'requested', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
          ],
        )),
      );

      expect(find.widgetWithText(ElevatedButton, 'Cancel Booking'), findsOneWidget);
      final button = tester.widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
      expect(button.onPressed, isNotNull);
    });

    testWidgets('tapping Cancel Booking opens a selection sheet listing every vehicle with its eligibility', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00227',
          groupCode: 'GRP-1',
          groupVehicles: [
            {'booking_code': 'TM-00227', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
            {'booking_code': 'TM-00228', 'status': 'on_the_way', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
          ],
        )),
        asGroupOverview: true,
      );

      await tester.ensureVisible(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
      await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
      await _settle(tester);

      expect(find.text('What would you like to cancel?'), findsOneWidget);
      expect(find.text('Sedan · TM-00227'), findsOneWidget);
      expect(find.text('Motorcycle · TM-00228'), findsOneWidget);
      expect(find.textContaining('Not eligible for cancellation'), findsOneWidget);

      final checkboxes = tester.widgetList<CheckboxListTile>(find.byType(CheckboxListTile)).toList();
      expect(checkboxes.length, 3);
    });

    testWidgets('Select all only selects eligible vehicles', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00227',
          groupCode: 'GRP-1',
          groupVehicles: [
            {'booking_code': 'TM-00227', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
            {'booking_code': 'TM-00228', 'status': 'on_the_way', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
          ],
        )),
        asGroupOverview: true,
      );

      await tester.ensureVisible(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
      await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
      await _settle(tester);

      await tester.tap(find.text('Select all'));
      await _settle(tester);

      expect(find.text('Continue (1 selected)'), findsOneWidget);
    });

    testWidgets('with one vehicle already cancelled, selecting the two remaining active vehicles warns that none will remain active', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00283',
          groupCode: 'GRP-1',
          groupVehicles: [
            {'booking_code': 'TM-00283', 'status': 'cancelled', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
            {'booking_code': 'TM-00284', 'status': 'requested', 'vehicle_type_name': 'Pickup Truck', 'final_total': 2200.0},
            {'booking_code': 'TM-00285', 'status': 'requested', 'vehicle_type_name': 'Compact SUV', 'final_total': 1900.0},
          ],
        )),
        asGroupOverview: true,
      );

      await tester.ensureVisible(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
      await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
      await _settle(tester);

      await tester.tap(find.text('Select all'));
      await _settle(tester);

      await tester.tap(find.text('Continue (2 selected)'));
      await _settle(tester);

      expect(find.text('Cancel this entire request?'), findsOneWidget);
      expect(find.text('All remaining active vehicles in this request will be cancelled.'), findsOneWidget);
      expect(find.textContaining('will remain active and unaffected'), findsNothing);
      expect(find.textContaining('1 vehicle will remain'), findsNothing);
    });

    testWidgets('with one vehicle already cancelled, selecting only one of the two remaining active vehicles shows the correct remaining count', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00283',
          groupCode: 'GRP-1',
          groupVehicles: [
            {'booking_code': 'TM-00283', 'status': 'cancelled', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
            {'booking_code': 'TM-00284', 'status': 'requested', 'vehicle_type_name': 'Pickup Truck', 'final_total': 2200.0},
            {'booking_code': 'TM-00285', 'status': 'requested', 'vehicle_type_name': 'Compact SUV', 'final_total': 1900.0},
          ],
        )),
        asGroupOverview: true,
      );

      await tester.ensureVisible(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
      await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
      await _settle(tester);

      await tester.tap(find.text('Pickup Truck · TM-00284'));
      await _settle(tester);

      await tester.tap(find.text('Continue (1 selected)'));
      await _settle(tester);

      expect(find.text('Cancel selected vehicles?'), findsOneWidget);
      expect(find.text('1 vehicle will remain active and unaffected.'), findsOneWidget);
      expect(find.text('All remaining active vehicles in this request will be cancelled.'), findsNothing);
    });

    testWidgets('selecting a vehicle, confirming, and submitting calls the group cancel endpoint once and refreshes totals', (tester) async {
      Map<String, dynamic>? capturedBody;

      final initialDetail = _detail(
        code: 'TM-00227',
        groupCode: 'GRP-1',
        baseRate: 1500.0,
        distanceFee: 0.0,
        vatAmount: 180.0,
        finalTotal: 1680.0,
        groupTotals: {
          'vehicle_count': 2,
          'base_rate': 2500.0,
          'computed_total': 2500.0,
          'vat_amount': 300.0,
          'additional_fee': 0.0,
          'final_total': 2800.0,
        },
        groupVehicles: [
          {'booking_code': 'TM-00227', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
          {'booking_code': 'TM-00228', 'status': 'requested', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
        ],
      );
      final afterCancelDetail = _detail(
        code: 'TM-00227',
        groupCode: 'GRP-1',
        baseRate: 1500.0,
        distanceFee: 0.0,
        vatAmount: 180.0,
        finalTotal: 1680.0,
        groupTotals: {
          'vehicle_count': 2,
          'base_rate': 1500.0,
          'computed_total': 1500.0,
          'vat_amount': 180.0,
          'additional_fee': 0.0,
          'final_total': 1680.0,
        },
        groupVehicles: [
          {'booking_code': 'TM-00227', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
          {'booking_code': 'TM-00228', 'status': 'cancelled', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
        ],
      );

      final client = _clientForGroupCancel(
        detailResponses: [initialDetail, afterCancelDetail],
        cancelResponse: {
          'success': true,
          'message': 'Selected vehicles cancelled successfully.',
          'cancelled_booking_codes': ['TM-00228'],
        },
        onCancelCalled: (body) => capturedBody = body,
      );

      await http.runWithClient(() async {
        SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
        await tester.pumpWidget(const MaterialApp(
          home: BookingDetailScreen(bookingCode: 'TM-00227', asGroupOverview: true),
        ));
        await _settle(tester);

        expect(find.text('₱2,800.00'), findsOneWidget);

        await tester.ensureVisible(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
        await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
        await _settle(tester);

        await tester.tap(find.text('Motorcycle · TM-00228'));
        await _settle(tester);

        await tester.tap(find.text('Continue (1 selected)'));
        await _settle(tester);

        expect(find.text('Cancel selected vehicles?'), findsOneWidget);
        expect(find.textContaining('1 vehicle will remain active'), findsOneWidget);

        await tester.tap(find.text('Confirm Cancellation'));
        await _settle(tester);
      }, () => client);

      expect(capturedBody, {
        'booking_codes': ['TM-00228'],
      });
      expect(find.text('₱2,800.00'), findsNothing);
      expect(find.text('₱1,680.00'), findsWidgets);
      expect(find.textContaining('TM-00228 · Cancelled'), findsOneWidget);
    });

    testWidgets('does not show a successful cancellation when the backend rejects the selection', (tester) async {
      final detail = _detail(
        code: 'TM-00227',
        groupCode: 'GRP-1',
        groupVehicles: [
          {'booking_code': 'TM-00227', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
          {'booking_code': 'TM-00228', 'status': 'requested', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
        ],
      );

      final client = _clientForGroupCancel(
        detailResponses: [detail, detail],
        cancelResponse: {
          'success': false,
          'message': 'One or more selected vehicles can no longer be cancelled.',
          'ineligible': [
            {'booking_code': 'TM-00228', 'status': 'on_the_way'},
          ],
        },
        cancelStatus: 422,
      );

      await http.runWithClient(() async {
        SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
        await tester.pumpWidget(const MaterialApp(
          home: BookingDetailScreen(bookingCode: 'TM-00227', asGroupOverview: true),
        ));
        await _settle(tester);

        await tester.ensureVisible(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
        await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
        await _settle(tester);
        await tester.tap(find.text('Motorcycle · TM-00228'));
        await _settle(tester);
        await tester.tap(find.text('Continue (1 selected)'));
        await _settle(tester);
        await tester.tap(find.text('Confirm Cancellation'));
        await _settle(tester);
      }, () => client);

      expect(
        find.text('One or more selected vehicles can no longer be cancelled. Please review and try again.'),
        findsOneWidget,
      );
      expect(find.text('Motorcycle · TM-00228'), findsNothing);
    });

    testWidgets('shows a network error message without a successful cancellation when the request fails', (tester) async {
      final detail = _detail(
        code: 'TM-00227',
        groupCode: 'GRP-1',
        groupVehicles: [
          {'booking_code': 'TM-00227', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
          {'booking_code': 'TM-00228', 'status': 'requested', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
        ],
      );

      final client = MockClient((request) async {
        final path = request.url.path;
        if (path.contains('/bookings/group/') && path.endsWith('/cancel')) {
          throw Exception('network down');
        }
        if (path.contains('/detail')) return _json(detail);
        return _json({'success': false}, status: 404);
      });

      await http.runWithClient(() async {
        SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
        await tester.pumpWidget(const MaterialApp(
          home: BookingDetailScreen(bookingCode: 'TM-00227', asGroupOverview: true),
        ));
        await _settle(tester);

        await tester.ensureVisible(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
        await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
        await _settle(tester);
        await tester.tap(find.text('Motorcycle · TM-00228'));
        await _settle(tester);
        await tester.tap(find.text('Continue (1 selected)'));
        await _settle(tester);
        await tester.tap(find.text('Confirm Cancellation'));
        await _settle(tester);
      }, () => client);

      expect(find.text('Network error. Please try again.'), findsOneWidget);
    });

    testWidgets('disables the group Cancel Booking action while a request is in flight, preventing duplicate submissions', (tester) async {
      final detail = _detail(
        code: 'TM-00227',
        groupCode: 'GRP-1',
        groupVehicles: [
          {'booking_code': 'TM-00227', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
          {'booking_code': 'TM-00228', 'status': 'requested', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
        ],
      );

      final completer = Completer<http.Response>();
      final client = MockClient((request) async {
        final path = request.url.path;
        if (path.contains('/bookings/group/') && path.endsWith('/cancel')) {
          return completer.future;
        }
        if (path.contains('/detail')) return _json(detail);
        return _json({'success': false}, status: 404);
      });

      await http.runWithClient(() async {
        SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
        await tester.pumpWidget(const MaterialApp(
          home: BookingDetailScreen(bookingCode: 'TM-00227', asGroupOverview: true),
        ));
        await _settle(tester);

        await tester.ensureVisible(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
        await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
        await _settle(tester);
        await tester.tap(find.text('Motorcycle · TM-00228'));
        await _settle(tester);
        await tester.tap(find.text('Continue (1 selected)'));
        await _settle(tester);
        await tester.tap(find.text('Confirm Cancellation'));
        await tester.pump();

        final button = tester.widget<ElevatedButton>(find.byType(ElevatedButton));
        expect(button.onPressed, isNull);
        expect(find.byType(CircularProgressIndicator), findsOneWidget);

        completer.complete(_json({'success': true, 'message': 'Cancelled.'}));
        await _settle(tester);
      }, () => client);
    });

    testWidgets('after partial cancellation the group overview labels the amount Remaining Total using the active vehicle count', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00283',
          groupCode: 'GRP-000042',
          baseRate: 1500.0,
          distanceFee: 0.0,
          vatAmount: 180.0,
          finalTotal: 1680.0,
          groupTotals: {
            'vehicle_count': 2,
            'base_rate': 1500.0,
            'computed_total': 1500.0,
            'vat_amount': 180.0,
            'additional_fee': 0.0,
            'final_total': 1680.0,
          },
          groupVehicles: [
            {'booking_code': 'TM-00283', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
            {'booking_code': 'TM-00284', 'status': 'cancelled', 'vehicle_type_name': 'Sedan', 'final_total': 1120.0},
          ],
        )),
        asGroupOverview: true,
      );

      expect(find.text('Remaining Total (1 active vehicle)'), findsOneWidget);
      expect(find.text('Group Total (2 vehicles)'), findsNothing);
      expect(find.text('Base Rate'), findsOneWidget);
      expect(find.textContaining('combined · 1 vehicle'), findsNothing);
      expect(find.textContaining('TM-00284 · Cancelled'), findsOneWidget);
      expect(find.textContaining('TM-00283 · Requested'), findsOneWidget);
    });

    testWidgets('before any cancellation the group overview keeps the Group Total label with the full vehicle count', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00283',
          groupCode: 'GRP-000042',
          groupTotals: {
            'vehicle_count': 2,
            'base_rate': 2500.0,
            'computed_total': 2500.0,
            'vat_amount': 300.0,
            'additional_fee': 0.0,
            'final_total': 2800.0,
          },
          groupVehicles: [
            {'booking_code': 'TM-00283', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
            {'booking_code': 'TM-00284', 'status': 'requested', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
          ],
        )),
        asGroupOverview: true,
      );

      expect(find.text('Group Total (2 vehicles)'), findsOneWidget);
      expect(find.textContaining('Remaining Total'), findsNothing);
      expect(find.textContaining('combined · 2 vehicles'), findsOneWidget);
    });

    testWidgets('the individual status card shows only the viewed vehicle\'s own code and vehicle type when cancelled', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00284',
          status: 'cancelled',
          groupCode: 'GRP-000042',
          groupBookingCode: 'TM-00283',
          vehicleTypeName: 'Sedan',
        )),
        code: 'TM-00284',
      );

      expect(find.text('Cancelled'), findsOneWidget);
      expect(find.text('TM-00284 · Sedan'), findsOneWidget);
      expect(find.text('TM-00283'), findsNothing);
      expect(find.textContaining('Vehicle reference'), findsNothing);
    });

    testWidgets('the individual status card shows the own code and vehicle type for a non-cancelled status too', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00284',
          status: 'on_the_way',
          groupCode: 'GRP-000042',
          groupBookingCode: 'TM-00283',
          vehicleTypeName: 'Sedan',
        )),
        code: 'TM-00284',
      );

      expect(find.text('TM-00284 · Sedan'), findsOneWidget);
      expect(find.text('TM-00283'), findsNothing);
    });

    testWidgets('a cancelled individual booking with locked pricing labels its amount as an original agreed amount', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00284',
          status: 'cancelled',
          serviceType: 'schedule',
          pricingIsProvisional: false,
          finalTotal: 1680.0,
        )),
        code: 'TM-00284',
      );

      expect(find.text('Original Agreed Amount'), findsOneWidget);
      expect(find.text('Total Amount'), findsNothing);
    });

    testWidgets('a cancelled individual booking with unlocked pricing labels its amount as an original estimated amount', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(_detail(
          code: 'TM-00284',
          status: 'cancelled',
          serviceType: 'schedule',
          pricingIsProvisional: true,
          finalTotal: 1680.0,
        )),
        code: 'TM-00284',
      );

      expect(find.text('Original Estimated Amount'), findsOneWidget);
      expect(find.text('Total Amount'), findsNothing);
    });

    testWidgets('a non-cancelled individual booking keeps the plain Total Amount label', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail(code: 'TM-00284', status: 'requested')));
      expect(find.text('Total Amount'), findsOneWidget);
      expect(find.textContaining('Original'), findsNothing);
    });
  });
}
