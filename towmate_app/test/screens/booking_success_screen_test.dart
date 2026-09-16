import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/models/booking_model.dart';
import 'package:towmate_app/screens/customer/booking_success_screen.dart';

BookingGroupSibling _booking({
  required String code,
  required String vehicleTypeName,
  String serviceType = 'book_now',
  String status = 'requested',
  String? scheduledDate,
  String? scheduledTime,
  bool isCurrent = false,
}) {
  return BookingGroupSibling(
    bookingCode: code,
    vehicleTypeName: vehicleTypeName,
    truckTypeName: 'Light Duty',
    serviceType: serviceType,
    status: status,
    scheduledDate: scheduledDate,
    scheduledTime: scheduledTime,
    isCurrent: isCurrent,
  );
}

Future<void> _pumpScreen(
  WidgetTester tester,
  List<BookingGroupSibling> bookings, {
  void Function(String route)? onNavigate,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      onGenerateRoute: (settings) {
        if (settings.name == '/' || settings.name == null) {
          return MaterialPageRoute(builder: (_) => BookingSuccessScreen(bookings: bookings));
        }
        onNavigate?.call(settings.name!);
        return MaterialPageRoute(builder: (_) => const Scaffold());
      },
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  group('BookingSuccessScreen', () {
    testWidgets('a single booking shows one card and no multi-vehicle count', (tester) async {
      await _pumpScreen(tester, [
        _booking(code: 'TM-00225', vehicleTypeName: 'Sedan', isCurrent: true),
      ]);

      expect(find.text('Booking request submitted'), findsOneWidget);
      expect(find.text('TM-00225'), findsOneWidget);
      expect(find.text('Sedan'), findsOneWidget);
      expect(find.textContaining('vehicles in this request'), findsNothing);
      expect(find.textContaining('may be dispatched, scheduled, and billed separately'), findsNothing);
    });

    testWidgets('a mixed request shows every created booking with its own state', (tester) async {
      await _pumpScreen(tester, [
        _booking(code: 'TM-00227', vehicleTypeName: 'Sedan', serviceType: 'book_now', status: 'requested', isCurrent: true),
        _booking(
          code: 'TM-00228',
          vehicleTypeName: 'Motorcycle',
          serviceType: 'schedule',
          status: 'scheduled',
          scheduledDate: '2026-09-17',
          scheduledTime: '13:00',
        ),
      ]);

      expect(find.text('2 vehicles in this request'), findsOneWidget);
      expect(find.text('TM-00227'), findsOneWidget);
      expect(find.text('Sedan'), findsOneWidget);
      expect(find.text('Book Now'), findsOneWidget);
      expect(find.text('TM-00228'), findsOneWidget);
      expect(find.text('Motorcycle'), findsOneWidget);
      expect(find.text('Scheduled'), findsOneWidget);
      expect(find.textContaining('Sep 17'), findsOneWidget);
      expect(
        find.text('Each vehicle may be dispatched, scheduled, and billed separately.'),
        findsOneWidget,
      );
    });

    testWidgets('never exposes internal Truck Type labels', (tester) async {
      await _pumpScreen(tester, [
        _booking(code: 'TM-00225', vehicleTypeName: 'Sedan'),
      ]);

      expect(find.textContaining('Light Duty'), findsNothing);
      expect(find.textContaining('Truck Type'), findsNothing);
    });

    testWidgets('View My Bookings navigates to /my-bookings', (tester) async {
      String? captured;
      await _pumpScreen(
        tester,
        [_booking(code: 'TM-00225', vehicleTypeName: 'Sedan')],
        onNavigate: (r) => captured = r,
      );

      await tester.tap(find.text('View My Bookings'));
      await tester.pumpAndSettle();

      expect(captured, '/my-bookings');
    });

    testWidgets('Back to Home navigates to /home', (tester) async {
      String? captured;
      await _pumpScreen(
        tester,
        [_booking(code: 'TM-00225', vehicleTypeName: 'Sedan')],
        onNavigate: (r) => captured = r,
      );

      await tester.tap(find.text('Back to Home'));
      await tester.pumpAndSettle();

      expect(captured, '/home');
    });

    for (final width in [320.0, 360.0, 390.0, 412.0]) {
      testWidgets('renders a mixed request without overflow at ${width.toInt()}px', (tester) async {
        tester.view.physicalSize = Size(width, 900);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        await _pumpScreen(tester, [
          _booking(code: 'TM-00227', vehicleTypeName: 'Sedan', serviceType: 'book_now', status: 'requested'),
          _booking(
            code: 'TM-00228',
            vehicleTypeName: 'Motorcycle',
            serviceType: 'schedule',
            status: 'scheduled',
            scheduledDate: '2026-09-17',
            scheduledTime: '13:00',
          ),
        ]);

        expect(tester.takeException(), isNull);
      });
    }
  });
}
