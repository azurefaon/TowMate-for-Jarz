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
      'group_siblings': groupSiblings ?? [],
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

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pumpScreen(WidgetTester tester, http.Client client, {String code = 'TM-00225'}) async {
  SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
  await http.runWithClient(
    () async {
      await tester.pumpWidget(MaterialApp(home: BookingDetailScreen(bookingCode: code)));
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

    testWidgets('does not show THIS REQUEST for a standalone booking', (tester) async {
      await _pumpScreen(tester, _clientFor(_detail()));
      expect(find.text('THIS REQUEST'), findsNothing);
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
  });
}
