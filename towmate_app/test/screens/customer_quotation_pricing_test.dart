import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/models/quotation_model.dart';
import 'package:towmate_app/screens/customer/customer_quotation_screen.dart';
import 'package:towmate_app/widgets/quotation_price_cards.dart';

Map<String, dynamic> _soloJson({
  double baseRate = 1500,
  double distanceFee = 480,
  double subtotal = 1980,
  double vatRate = 0.12,
  double? vatAmount,
  double discount = 0,
  double estimatedPrice = 2217.6,
}) {
  return {
    'id': 1,
    'quotation_number': 'QT-TEST-0001',
    'status': 'sent',
    'estimated_price': estimatedPrice,
    'base_rate': baseRate,
    'distance_km': 12.0,
    'distance_fee': distanceFee,
    'subtotal': subtotal,
    'vat_amount': vatAmount ?? double.parse((subtotal * vatRate).toStringAsFixed(2)),
    'vat_rate': vatRate,
    'discount': discount,
    'additional_fee': estimatedPrice - subtotal - (vatAmount ?? subtotal * vatRate),
    'pickup_address': 'Pasay',
    'dropoff_address': 'Makati',
    'truck_type_name': 'Light Duty',
  };
}

Map<String, dynamic> _vehicleJson({
  required int bookingId,
  required double baseRate,
  required double distanceFee,
  double vatRate = 0.12,
}) {
  final vatAmount = double.parse(((baseRate + distanceFee) * vatRate).toStringAsFixed(2));
  return {
    'booking_id': bookingId,
    'vehicle_name': 'Vehicle $bookingId',
    'truck_type_name': 'Truck $bookingId',
    'base_rate': baseRate,
    'distance_fee': distanceFee,
    'vat_amount': vatAmount,
    'vat_rate': vatRate,
    'final_total': double.parse((baseRate + distanceFee + vatAmount).toStringAsFixed(2)),
  };
}

Map<String, dynamic> _groupedJson({
  double discount = 0,
  required double estimatedPrice,
  double primaryBaseRate = 2000,
  double primaryDistanceFee = 600,
}) {
  final primaryVatAmount = double.parse(((primaryBaseRate + primaryDistanceFee) * 0.12).toStringAsFixed(2));
  return {
    'id': 2,
    'quotation_number': 'QT-TEST-0002',
    'status': 'sent',
    'estimated_price': estimatedPrice,
    'base_rate': primaryBaseRate,
    'distance_km': 12.0,
    'distance_fee': primaryDistanceFee,
    'subtotal': primaryBaseRate + primaryDistanceFee,
    'vat_amount': primaryVatAmount,
    'vat_rate': 0.12,
    'discount': discount,
    'pickup_address': 'Pasay',
    'dropoff_address': 'Makati',
    'truck_type_name': 'Light Duty',
    'extra_vehicles': [
      _vehicleJson(bookingId: 101, baseRate: 1500, distanceFee: 480),
      _vehicleJson(bookingId: 102, baseRate: 900, distanceFee: 320),
    ],
  };
}

Future<void> _pumpQuotationScreen(
  WidgetTester tester,
  Map<String, dynamic> json, {
  double width = 600,
}) async {
  final quotation = QuotationModel.fromJson(json);
  final originalSize = tester.view.physicalSize;
  final originalRatio = tester.view.devicePixelRatio;
  tester.view.physicalSize = Size(width, 2600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(() {
    tester.view.physicalSize = originalSize;
    tester.view.devicePixelRatio = originalRatio;
  });

  await tester.pumpWidget(MaterialApp(
    onGenerateRoute: (settings) => MaterialPageRoute(
      builder: (_) => const CustomerQuotationScreen(),
      settings: RouteSettings(arguments: quotation),
    ),
  ));
  await tester.pump(const Duration(milliseconds: 50));
}

void main() {
  testWidgets('single quotation shows taxable subtotal, VAT and service total with no adjustment rows', (tester) async {
    await _pumpQuotationScreen(tester, _soloJson());

    expect(find.text('Taxable Subtotal'), findsOneWidget);
    expect(find.text('VAT (12%)'), findsOneWidget);
    expect(find.text('Service Total (incl. VAT)'), findsOneWidget);
    expect(find.text(formatPeso(1980)), findsOneWidget);
    expect(find.text(formatPeso(237.6)), findsOneWidget);
    expect(find.text(formatPeso(2217.6)), findsWidgets);
    expect(find.text('Additional Fee'), findsNothing);
    expect(find.text('Discount'), findsNothing);
    expect(find.text('Service Discount'), findsNothing);
  });

  testWidgets('grouped quotation shows every vehicle including the primary, with its own towing class, and one combined final quoted total', (tester) async {
    await _pumpQuotationScreen(
      tester,
      _groupedJson(estimatedPrice: 6496.0),
    );

    expect(find.text('Vehicle 1 — Light Duty'), findsWidgets);
    expect(find.text('Vehicle 2 — Vehicle 101'), findsOneWidget);
    expect(find.text('Vehicle 3 — Vehicle 102'), findsOneWidget);
    expect(find.text('Vehicle Service Total'), findsNWidgets(3));
    expect(find.text(formatPeso(2912.0)), findsWidgets);
    expect(find.text(formatPeso(2217.6)), findsWidgets);
    expect(find.text(formatPeso(1366.4)), findsWidgets);

    expect(find.text('Towing Class'), findsNWidgets(3));
    expect(find.text('Truck 101'), findsOneWidget);
    expect(find.text('Truck 102'), findsOneWidget);

    expect(find.text('Taxable Subtotal'), findsNWidgets(4));
    expect(find.text(formatPeso(5800.0)), findsOneWidget);
    expect(find.text('VAT (12%)'), findsNWidgets(4));
    expect(find.text(formatPeso(696.0)), findsOneWidget);
    expect(find.text('Service Total (incl. VAT)'), findsOneWidget);
    expect(find.text(formatPeso(6496.0)), findsWidgets);
    expect(find.text('Final Quoted Total'), findsOneWidget);
    expect(find.text('Service Discount'), findsNothing);
  });

  testWidgets('solo quotation with a positive adjustment shows an Additional Fee row that reconciles', (tester) async {
    await _pumpQuotationScreen(
      tester,
      _soloJson(estimatedPrice: 2517.6),
    );

    expect(find.text('Additional Fee'), findsOneWidget);
    expect(find.text(formatPeso(300)), findsOneWidget);
    expect(find.text(formatPeso(2517.6)), findsWidgets);
    expect(find.text('Discount'), findsNothing);
  });

  testWidgets('solo quotation with a negative adjustment shows a Discount row that reconciles', (tester) async {
    await _pumpQuotationScreen(
      tester,
      _soloJson(estimatedPrice: 2017.6),
    );

    expect(find.text('Discount'), findsOneWidget);
    expect(find.text('-${formatPeso(200)}'), findsOneWidget);
    expect(find.text(formatPeso(2017.6)), findsWidgets);
    expect(find.text('Additional Fee'), findsNothing);
  });

  testWidgets('solo quotation combines a Service Discount with a separate adjustment and still reconciles', (tester) async {
    await _pumpQuotationScreen(
      tester,
      _soloJson(discount: 150, estimatedPrice: 2167.6),
    );

    expect(find.text('Service Discount'), findsOneWidget);
    expect(find.text('-${formatPeso(150)}'), findsOneWidget);
    expect(find.text('Additional Fee'), findsOneWidget);
    expect(find.text(formatPeso(100)), findsOneWidget);
    expect(find.text('Discount'), findsNothing);
    expect(find.text(formatPeso(2167.6)), findsWidgets);
  });

  testWidgets('grouped quotation combines a Service Discount with a negative adjustment without double-counting', (tester) async {
    await _pumpQuotationScreen(
      tester,
      _groupedJson(discount: 150, estimatedPrice: 6246.0),
    );

    expect(find.text('Service Discount'), findsOneWidget);
    expect(find.text('-${formatPeso(150)}'), findsOneWidget);
    expect(find.text('Discount'), findsOneWidget);
    expect(find.text('-${formatPeso(100)}'), findsOneWidget);
    expect(find.text('Quotation Adjustment'), findsNothing);
    expect(find.text('Final Quoted Total'), findsOneWidget);
    expect(find.text(formatPeso(6246.0)), findsWidgets);
  });

  testWidgets('grouped quotation combines a Service Discount with a separate adjustment and still reconciles', (tester) async {
    await _pumpQuotationScreen(
      tester,
      _groupedJson(discount: 150, estimatedPrice: 6446.0),
    );

    expect(find.text('Service Discount'), findsOneWidget);
    expect(find.text('-${formatPeso(150)}'), findsOneWidget);
    expect(find.text('Quotation Adjustment'), findsOneWidget);
    expect(find.text(formatPeso(100)), findsOneWidget);
    expect(find.text('Final Quoted Total'), findsOneWidget);
    expect(find.text(formatPeso(6446.0)), findsWidgets);
  });

  testWidgets('solo quotation with itemized price adjustments shows each addition and deduction as its own row', (tester) async {
    final json = _soloJson(estimatedPrice: 2417.6);
    json['price_adjustments'] = [
      {'type': 'add', 'amount': 300.0, 'reason': 'Extra crew'},
      {'type': 'deduct', 'amount': 100.0, 'reason': 'Loyalty discount'},
    ];

    await _pumpQuotationScreen(tester, json);

    expect(find.text('Extra crew'), findsOneWidget);
    expect(find.text(formatPeso(300)), findsOneWidget);
    expect(find.text('Loyalty discount'), findsOneWidget);
    expect(find.text('-${formatPeso(100)}'), findsOneWidget);
    expect(find.text('Additional Fee'), findsNothing);
    expect(find.text('Discount'), findsNothing);
    expect(find.text(formatPeso(2417.6)), findsWidgets);
  });

  testWidgets('grouped quotation with itemized price adjustments shows each addition and deduction as its own row', (tester) async {
    final json = _groupedJson(estimatedPrice: 6796.0);
    json['price_adjustments'] = [
      {'type': 'add', 'amount': 400.0, 'reason': 'Fuel surcharge'},
      {'type': 'deduct', 'amount': 100.0, 'reason': 'Group discount'},
    ];

    await _pumpQuotationScreen(tester, json);

    expect(find.text('Fuel surcharge'), findsOneWidget);
    expect(find.text(formatPeso(400)), findsOneWidget);
    expect(find.text('Group discount'), findsOneWidget);
    expect(find.text('-${formatPeso(100)}'), findsOneWidget);
    expect(find.text('Quotation Adjustment'), findsNothing);
    expect(find.text(formatPeso(6796.0)), findsWidgets);
  });

  testWidgets('quotation with a saved VAT rate different from the 12% default renders that saved rate', (tester) async {
    await _pumpQuotationScreen(
      tester,
      _soloJson(vatRate: 0.15, estimatedPrice: 2277.0),
    );

    expect(find.text('VAT (15%)'), findsOneWidget);
    expect(find.text('VAT (12%)'), findsNothing);
    expect(find.text(formatPeso(297.0)), findsOneWidget);
    expect(find.text(formatPeso(2277.0)), findsWidgets);
  });

  for (final width in [320.0, 360.0, 390.0, 412.0, 430.0]) {
    testWidgets('renders the Request Price Review button without overflow at ${width}dp width', (tester) async {
      await _pumpQuotationScreen(tester, _soloJson(), width: width);

      expect(find.text('Request Price Review'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
