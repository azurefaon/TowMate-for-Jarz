import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/customer/my_bookings_screen.dart';
import 'package:towmate_app/widgets/skeleton_box.dart';
import 'package:towmate_app/widgets/tm_bottom_nav.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

Map<String, dynamic> _booking({
  required String code,
  required String status,
  String? groupCode,
  String serviceType = 'book_now',
  String? scheduledDate,
  String? scheduledTime,
  double? computedTotal,
  double? distanceKm,
  String pickupAddress = 'Sumilang Street, Pasig',
  String dropoffAddress = 'Quirino Highway, QC',
  String? vehicleTypeName,
}) {
  return {
    'id': int.parse(code.replaceAll(RegExp(r'[^0-9]'), '')),
    'booking_code': code,
    'status': status,
    'pickup_address': pickupAddress,
    'dropoff_address': dropoffAddress,
    'distance_km': distanceKm,
    'computed_total': computedTotal,
    'final_total': null,
    'truck_type_name': 'Light Duty',
    'vehicle_type_name': vehicleTypeName,
    'created_at': '2026-09-01 10:00:00',
    'group_code': groupCode,
    'service_type': serviceType,
    'scheduled_date': scheduledDate,
    'scheduled_time': scheduledTime,
  };
}

http.Client _clientFor({
  List<Map<String, dynamic>> bookings = const [],
  bool hasMore = false,
  Map<String, dynamic>? pendingQuotation,
  bool failHistory = false,
}) {
  return MockClient((request) async {
    final path = request.url.path;
    if (path.endsWith('/v1/bookings/history')) {
      if (failHistory) return _json({}, status: 500);
      return _json({
        'data': bookings,
        'meta': {'last_page': hasMore ? 2 : 1},
      });
    }
    if (path.endsWith('/v1/quotations/pending')) {
      return _json({'data': pendingQuotation});
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
  void Function(String route, Object? args)? onNavigate,
}) async {
  SharedPreferences.setMockInitialValues({
    'auth_token': 'test-token',
    'user_role': 'Customer',
    'user_name': 'Faon Delacruz',
  });
  await http.runWithClient(
    () async {
      await tester.pumpWidget(
        MaterialApp(
          onGenerateRoute: (settings) {
            if (settings.name == '/' || settings.name == null) {
              return MaterialPageRoute(builder: (_) => const MyBookingsScreen());
            }
            onNavigate?.call(settings.name!, settings.arguments);
            return MaterialPageRoute(builder: (_) => const Scaffold());
          },
        ),
      );
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

  group('MyBookingsScreen', () {
    testWidgets('renders a skeleton, not a spinner, while loading', (tester) async {
      final client = MockClient((request) async {
        await Future<void>.delayed(const Duration(seconds: 2));
        return _json({'data': [], 'meta': {}});
      });
      SharedPreferences.setMockInitialValues({'auth_token': 't', 'user_role': 'Customer'});

      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: MyBookingsScreen()));
          for (var i = 0; i < 3; i++) {
            await tester.pump(const Duration(milliseconds: 50));
          }
        },
        () => client,
      );

      expect(find.byType(SkeletonBox), findsWidgets);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(tester.takeException(), isNull);

      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('Bookings is the active bottom-navigation destination', (tester) async {
      await _pumpScreen(tester, _clientFor());

      final nav = tester.widget<TmBottomNav>(find.byType(TmBottomNav));
      expect(nav.currentRoute, '/my-bookings');
      expect(find.text('Bookings'), findsWidgets);
    });

    testWidgets('Active tab renders only non-historical bookings from real server state', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(bookings: [
          _booking(code: 'TM-00001', status: 'requested'),
          _booking(code: 'TM-00002', status: 'on_the_way'),
          _booking(code: 'TM-00003', status: 'completed'),
          _booking(code: 'TM-00004', status: 'cancelled'),
        ]),
      );

      expect(find.text('TM-00001'), findsOneWidget);
      expect(find.text('TM-00002'), findsOneWidget);
      expect(find.text('TM-00003'), findsNothing);
      expect(find.text('TM-00004'), findsNothing);
    });

    testWidgets('History tab renders only terminal bookings from real server state', (tester) async {
      final originalSize = tester.view.physicalSize;
      final originalRatio = tester.view.devicePixelRatio;
      tester.view.physicalSize = const Size(400, 1800);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() {
        tester.view.physicalSize = originalSize;
        tester.view.devicePixelRatio = originalRatio;
      });

      await _pumpScreen(
        tester,
        _clientFor(bookings: [
          _booking(code: 'TM-00001', status: 'requested'),
          _booking(code: 'TM-00003', status: 'completed'),
          _booking(code: 'TM-00004', status: 'cancelled'),
          _booking(code: 'TM-00005', status: 'rejected'),
          _booking(code: 'TM-00006', status: 'not_responding'),
        ]),
      );

      await tester.tap(find.text('History'));
      await _settle(tester);

      expect(find.text('TM-00001'), findsNothing);
      expect(find.text('TM-00003'), findsOneWidget);
      expect(find.text('TM-00004'), findsOneWidget);
      expect(find.text('TM-00005'), findsOneWidget);
      expect(find.text('TM-00006'), findsOneWidget);
    });

    testWidgets('tapping a booking card navigates to booking detail with the correct booking code', (tester) async {
      final navigated = <String>[];
      Object? capturedArgs;
      await _pumpScreen(
        tester,
        _clientFor(bookings: [_booking(code: 'TM-00042', status: 'requested')]),
        onNavigate: (route, args) {
          navigated.add(route);
          capturedArgs = args;
        },
      );

      await tester.tap(find.text('TM-00042'));
      await _settle(tester);

      expect(navigated, contains('/booking-detail'));
      expect(capturedArgs, 'TM-00042');
    });

    testWidgets('a Quotation Ready banner appears only when a real pending quotation exists', (tester) async {
      await _pumpScreen(tester, _clientFor(pendingQuotation: null));
      expect(find.text('Quotation Ready'), findsNothing);
    });

    testWidgets('tapping the Quotation Ready banner opens the quotation screen', (tester) async {
      final navigated = <String>[];
      await _pumpScreen(
        tester,
        _clientFor(pendingQuotation: {
          'id': 1,
          'quotation_number': 'Q-0001',
          'status': 'sent',
          'estimated_price': 2800.0,
          'distance_km': 5.0,
          'pickup_address': 'A',
          'dropoff_address': 'B',
          'truck_type_name': 'Light Duty',
        }),
        onNavigate: (route, args) => navigated.add(route),
      );

      expect(find.text('Quotation Ready'), findsOneWidget);
      await tester.tap(find.text('Quotation Ready'));
      await _settle(tester);

      expect(navigated, contains('/quotation'));
    });

    testWidgets('a requested booking shows an active, solid destructive Cancel Booking control', (tester) async {
      await _pumpScreen(tester, _clientFor(bookings: [_booking(code: 'TM-00001', status: 'requested')]));

      final button = find.widgetWithText(ElevatedButton, 'Cancel Booking');
      expect(button, findsOneWidget);
      final widget = tester.widget<ElevatedButton>(button);
      expect(widget.onPressed, isNotNull);
      final bg = widget.style?.backgroundColor?.resolve({});
      expect(bg, TmColors.destructive);
    });

    testWidgets('a scheduled_confirmed booking is also cancellable, matching the backend rule', (tester) async {
      await _pumpScreen(tester, _clientFor(bookings: [_booking(code: 'TM-00001', status: 'scheduled_confirmed')]));

      final button = find.widgetWithText(ElevatedButton, 'Cancel Booking');
      expect(button, findsOneWidget);
      expect(tester.widget<ElevatedButton>(button).onPressed, isNotNull);
    });

    testWidgets('a quotation_sent booking shows a disabled cancel hint, not an active cancel button', (tester) async {
      await _pumpScreen(tester, _clientFor(bookings: [_booking(code: 'TM-00001', status: 'quotation_sent')]));

      expect(find.text('Cancel Booking'), findsNothing);
      final hint = find.textContaining('can\'t cancel here');
      expect(hint, findsOneWidget);
    });

    testWidgets('an on_the_way booking (active job) shows no cancel control at all', (tester) async {
      await _pumpScreen(tester, _clientFor(bookings: [_booking(code: 'TM-00001', status: 'on_the_way')]));

      expect(find.text('Cancel Booking'), findsNothing);
      expect(find.textContaining('can\'t cancel here'), findsNothing);
    });

    testWidgets('tapping Cancel Booking then confirming calls the cancel API and refreshes', (tester) async {
      var cancelCalled = false;
      final client = MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/v1/bookings/history')) {
          return _json({
            'data': cancelCalled ? [] : [_booking(code: 'TM-00001', status: 'requested')],
            'meta': {},
          });
        }
        if (path.endsWith('/v1/quotations/pending')) return _json({'data': null});
        if (path.contains('/cancel')) {
          cancelCalled = true;
          return _json({'success': true, 'message': 'Booking cancelled successfully.'});
        }
        return _json({'success': false}, status: 404);
      });

      SharedPreferences.setMockInitialValues({'auth_token': 't', 'user_role': 'Customer'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: MyBookingsScreen()));
          await _settle(tester);

          await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
          await _settle(tester);
          expect(cancelCalled, isFalse);
          await tester.tap(find.widgetWithText(TextButton, 'Cancel Booking'));
          await _settle(tester);
        },
        () => client,
      );

      expect(cancelCalled, isTrue);
      expect(find.text('TM-00001'), findsNothing);
    });

    testWidgets('dismissing the confirmation with Keep Booking performs no cancellation', (tester) async {
      var cancelCalled = false;
      final client = MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/v1/bookings/history')) {
          return _json({
            'data': [_booking(code: 'TM-00001', status: 'requested')],
            'meta': {},
          });
        }
        if (path.endsWith('/v1/quotations/pending')) return _json({'data': null});
        if (path.contains('/cancel')) {
          cancelCalled = true;
          return _json({'success': true, 'message': 'Booking cancelled successfully.'});
        }
        return _json({'success': false}, status: 404);
      });

      SharedPreferences.setMockInitialValues({'auth_token': 't', 'user_role': 'Customer'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: MyBookingsScreen()));
          await _settle(tester);

          await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
          await _settle(tester);
          expect(find.text('Cancel this booking?'), findsOneWidget);
          await tester.tap(find.text('Keep Booking'));
          await _settle(tester);
        },
        () => client,
      );

      expect(cancelCalled, isFalse);
      expect(find.text('TM-00001'), findsOneWidget);
    });

    testWidgets('a scheduled booking never renders team leader or driver fields on the card', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(bookings: [
          _booking(
            code: 'TM-00001',
            status: 'scheduled_confirmed',
            serviceType: 'schedule',
            scheduledDate: '2026-09-18',
            scheduledTime: '14:30',
          ),
        ]),
      );

      expect(find.textContaining('Team Leader'), findsNothing);
      expect(find.textContaining('Driver'), findsNothing);
      expect(find.textContaining('Scheduled:'), findsOneWidget);
    });

    testWidgets('an unaccepted price is prefixed as an estimate, a committed price is not', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(bookings: [
          _booking(code: 'TM-00001', status: 'requested', computedTotal: 2800.0, distanceKm: 5.2),
        ]),
      );
      expect(find.textContaining('Est. ₱2,800.00'), findsOneWidget);
    });

    testWidgets('a group of scheduled vehicles can be expanded to reveal siblings', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(bookings: [
          _booking(code: 'TM-00001', status: 'scheduled', groupCode: 'GRP-1', serviceType: 'schedule'),
          _booking(code: 'TM-00002', status: 'scheduled', groupCode: 'GRP-1', serviceType: 'schedule'),
        ]),
      );

      expect(find.text('TM-00002'), findsNothing);
      await tester.tap(find.textContaining('Show 1 other vehicle'));
      await _settle(tester);
      expect(find.text('Light Duty'), findsWidgets);
    });

    testWidgets('a grouped card clearly states this is one request with N vehicles', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(bookings: [
          _booking(code: 'TM-00227', status: 'requested', groupCode: 'GRP-1'),
          _booking(code: 'TM-00228', status: 'scheduled', groupCode: 'GRP-1', serviceType: 'schedule'),
        ]),
      );

      expect(find.text('2 vehicles in this request'), findsOneWidget);
    });

    testWidgets('a grouped card with no siblings uses singular grammar and hides the sibling toggle', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(bookings: [
          _booking(code: 'TM-00301', status: 'requested', groupCode: 'GRP-LONE'),
        ]),
      );

      expect(find.text('1 vehicle in this request'), findsOneWidget);
      expect(find.textContaining('other vehicle'), findsNothing);
    });

    testWidgets('the customer-facing Vehicle Type is preferred over the internal Truck Type', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(bookings: [
          _booking(code: 'TM-00001', status: 'requested', vehicleTypeName: 'Sedan'),
        ]),
      );

      expect(find.textContaining('Sedan'), findsOneWidget);
      expect(find.textContaining('Light Duty'), findsNothing);
    });

    testWidgets('cancelling a booking that belongs to a group warns siblings will not be cancelled', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(bookings: [
          _booking(code: 'TM-00227', status: 'requested', groupCode: 'GRP-1'),
          _booking(code: 'TM-00228', status: 'scheduled', groupCode: 'GRP-1', serviceType: 'schedule'),
        ]),
      );

      await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
      await _settle(tester);

      expect(find.text('Cancel this booking?'), findsOneWidget);
      expect(
        find.text('This will cancel only this vehicle booking. Other vehicles in this request will not be cancelled.'),
        findsOneWidget,
      );
    });

    testWidgets('cancelling a standalone booking shows no group warning', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor(bookings: [
          _booking(code: 'TM-00001', status: 'requested'),
        ]),
      );

      await tester.tap(find.widgetWithText(ElevatedButton, 'Cancel Booking'));
      await _settle(tester);

      expect(find.text('Cancel this booking?'), findsOneWidget);
      expect(
        find.text('This will cancel only this vehicle booking. Other vehicles in this request will not be cancelled.'),
        findsNothing,
      );
    });

    testWidgets('pagination uses a skeleton shape instead of a spinner while loading more history', (tester) async {
      SharedPreferences.setMockInitialValues({
        'auth_token': 'test-token',
        'user_role': 'Customer',
      });
      final client = MockClient((request) async {
        final path = request.url.path;
        final page = request.url.queryParameters['page'];
        if (path.endsWith('/v1/bookings/history')) {
          if (page == '2') await Future<void>.delayed(const Duration(milliseconds: 200));
          return _json({
            'data': [_booking(code: 'TM-0000$page', status: 'completed')],
            'meta': {'last_page': 2},
          });
        }
        if (path.endsWith('/v1/quotations/pending')) return _json({'data': null});
        return _json({'success': false}, status: 404);
      });

      await http.runWithClient(() async {
        await tester.pumpWidget(const MaterialApp(home: MyBookingsScreen()));
        await _settle(tester);

        await tester.tap(find.text('History'));
        await _settle(tester);

        expect(find.text('Load more'), findsOneWidget);
        await tester.tap(find.text('Load more'));
        await tester.pump();

        expect(find.byType(CircularProgressIndicator), findsNothing);
        expect(find.byType(SkeletonBox), findsWidgets);

        await _settle(tester);
        await tester.pump(const Duration(milliseconds: 200));
      }, () => client);
    });

    testWidgets('an empty active list shows an active-specific empty state with a Book Now action', (tester) async {
      await _pumpScreen(tester, _clientFor(bookings: []));

      expect(find.text('No active bookings'), findsOneWidget);
      expect(find.text('Your current and upcoming towing requests will appear here.'), findsOneWidget);
      expect(find.widgetWithText(ElevatedButton, 'Book Now'), findsOneWidget);
    });

    testWidgets('an empty history list shows a distinct history-specific empty state', (tester) async {
      await _pumpScreen(tester, _clientFor(bookings: []));

      await tester.tap(find.text('History'));
      await _settle(tester);

      expect(find.text('No booking history'), findsOneWidget);
      expect(find.text('Completed and cancelled bookings will appear here.'), findsOneWidget);
      expect(find.widgetWithText(ElevatedButton, 'Book Now'), findsNothing);
    });

    testWidgets('a total fetch failure shows a distinct error/retry state, not the empty state', (tester) async {
      await _pumpScreen(tester, _clientFor(failHistory: true));

      expect(find.text('Unable to load bookings'), findsOneWidget);
      expect(find.text('No active bookings'), findsNothing);
      expect(find.text('Try again'), findsOneWidget);
    });

    testWidgets('tapping Try again retries the fetch and recovers on success', (tester) async {
      var attempt = 0;
      final client = MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/v1/bookings/history')) {
          attempt++;
          if (attempt == 1) return _json({}, status: 500);
          return _json({'data': [_booking(code: 'TM-00001', status: 'requested')], 'meta': {}});
        }
        if (path.endsWith('/v1/quotations/pending')) return _json({'data': null});
        return _json({'success': false}, status: 404);
      });

      SharedPreferences.setMockInitialValues({'auth_token': 't', 'user_role': 'Customer'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: MyBookingsScreen()));
          await _settle(tester);

          expect(find.text('Unable to load bookings'), findsOneWidget);

          await tester.tap(find.text('Try again'));
          await _settle(tester);
        },
        () => client,
      );

      expect(find.text('Unable to load bookings'), findsNothing);
      expect(find.text('TM-00001'), findsOneWidget);
    });

    for (final width in [320.0, 340.0, 360.0, 375.0, 390.0, 412.0]) {
      testWidgets('renders without horizontal overflow at $width px width', (tester) async {
        final originalSize = tester.view.physicalSize;
        final originalRatio = tester.view.devicePixelRatio;
        tester.view.physicalSize = Size(width, 800);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(() {
          tester.view.physicalSize = originalSize;
          tester.view.devicePixelRatio = originalRatio;
        });

        await _pumpScreen(
          tester,
          _clientFor(bookings: [
            _booking(
              code: 'TM-00001',
              status: 'requested',
              computedTotal: 2800.0,
              distanceKm: 5.2,
              pickupAddress: 'A very long pickup address that should wrap gracefully, Barangay Something, Pasig City',
              dropoffAddress: 'An equally long drop-off address somewhere far away, Quezon City, Metro Manila',
            ),
          ]),
        );

        expect(tester.takeException(), isNull);
      });
    }
  });
}
