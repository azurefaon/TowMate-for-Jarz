import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/models/quotation_model.dart';
import 'package:towmate_app/screens/customer/customer_quotation_screen.dart';

Map<String, dynamic> _baseJson({
  required String status,
  String? expiresAt,
}) {
  return {
    'id': 1,
    'quotation_number': 'QT-TEST-STATUS',
    'status': status,
    'estimated_price': 2217.6,
    'base_rate': 1500,
    'distance_km': 12.0,
    'distance_fee': 480,
    'subtotal': 1980,
    'vat_amount': 237.6,
    'vat_rate': 0.12,
    'discount': 0,
    'pickup_address': 'Pasay',
    'dropoff_address': 'Makati',
    'truck_type_name': 'Light Duty',
    if (expiresAt != null) 'expires_at': expiresAt,
  };
}

Future<void> _pumpQuotationScreen(
  WidgetTester tester,
  Map<String, dynamic> json, {
  double width = 390,
  ThemeData? theme,
}) async {
  final quotation = QuotationModel.fromJson(json);
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  await tester.pumpWidget(MaterialApp(
    theme: theme,
    onGenerateRoute: (settings) => MaterialPageRoute(
      builder: (_) => const CustomerQuotationScreen(),
      settings: RouteSettings(arguments: quotation),
    ),
  ));
  await tester.pump(const Duration(milliseconds: 50));
}

Color? _containerColorBehind(WidgetTester tester, Finder textFinder) {
  final container = find.ancestor(
    of: textFinder,
    matching: find.byType(Container),
  ).first;
  final widget = tester.widget<Container>(container);
  return (widget.decoration as BoxDecoration?)?.color;
}

Color? _textColor(WidgetTester tester, Finder textFinder) {
  return tester.widget<Text>(textFinder).style?.color;
}

void main() {
  group('Quotation Ready status', () {
    for (final width in [320.0, 360.0, 390.0, 412.0]) {
      testWidgets('renders solid green with no overflow at ${width.toInt()}px', (tester) async {
        await _pumpQuotationScreen(
          tester,
          _baseJson(status: 'sent'),
          width: width,
        );

        expect(find.text('Quotation Ready'), findsOneWidget);
        expect(tester.takeException(), isNull);

        final bg = _containerColorBehind(tester, find.text('Quotation Ready'));
        expect(bg, TmColors.success);
        expect(_textColor(tester, find.text('Quotation Ready')), TmColors.black);
      });
    }

    testWidgets('renders without exception in dark mode', (tester) async {
      await _pumpQuotationScreen(
        tester,
        _baseJson(status: 'sent'),
        theme: AppTheme.dark,
      );

      expect(find.text('Quotation Ready'), findsOneWidget);
      expect(tester.takeException(), isNull);

      final bg = _containerColorBehind(tester, find.text('Quotation Ready'));
      expect(bg, TmColors.success);
      expect(_textColor(tester, find.text('Quotation Ready')), TmColors.black);
    });
  });

  group('Expired status', () {
    for (final width in [320.0, 360.0, 390.0, 412.0]) {
      testWidgets('renders a neutral, non-green state with no overflow at ${width.toInt()}px', (tester) async {
        await _pumpQuotationScreen(
          tester,
          _baseJson(status: 'expired'),
          width: width,
        );

        expect(find.text('Expired'), findsOneWidget);
        expect(find.text('Quotation Ready'), findsNothing);
        expect(tester.takeException(), isNull);

        final bg = _containerColorBehind(tester, find.text('Expired'));
        expect(bg, isNot(TmColors.success));
        expect(bg, isNot(TmColors.yellow));
        expect(bg, isNot(TmColors.error));
      });
    }

    testWidgets('renders a visibly distinct treatment in dark mode, not black-on-black', (tester) async {
      await _pumpQuotationScreen(
        tester,
        _baseJson(status: 'expired'),
        theme: AppTheme.dark,
      );

      expect(find.text('Expired'), findsOneWidget);
      expect(tester.takeException(), isNull);

      final bg = _containerColorBehind(tester, find.text('Expired'));
      expect(bg, TmColors.grey300);
    });

    testWidgets('a sent quotation past its expiry shows Expired, not Quotation Ready', (tester) async {
      await _pumpQuotationScreen(
        tester,
        _baseJson(
          status: 'sent',
          expiresAt: DateTime.now().subtract(const Duration(hours: 1)).toIso8601String(),
        ),
      );

      expect(find.text('Expired'), findsOneWidget);
      expect(find.text('Quotation Ready'), findsNothing);
    });
  });

  testWidgets('Quotation Ready and Expired use visually distinct background colors', (tester) async {
    await _pumpQuotationScreen(tester, _baseJson(status: 'sent'));
    final readyBg = _containerColorBehind(tester, find.text('Quotation Ready'));

    await tester.pumpWidget(const SizedBox());

    await _pumpQuotationScreen(tester, _baseJson(status: 'expired'));
    final expiredBg = _containerColorBehind(tester, find.text('Expired'));

    expect(readyBg, isNot(expiredBg));
  });

  testWidgets('price review requested status keeps its existing yellow treatment', (tester) async {
    await _pumpQuotationScreen(
      tester,
      _baseJson(status: 'price_review_requested'),
      width: 600,
    );

    expect(find.text('Price Review Requested'), findsWidgets);
    final bg = _containerColorBehind(tester, find.text('Price Review Requested').first);
    expect(bg, TmColors.yellow.withValues(alpha: 0.12));
  });

  group('Price Review Requested info card', () {
    for (final width in [320.0, 360.0, 390.0, 412.0]) {
      testWidgets('renders without overflow at ${width.toInt()}px', (tester) async {
        await _pumpQuotationScreen(
          tester,
          _baseJson(status: 'price_review_requested'),
          width: width,
        );

        expect(find.text('Price Review Requested'), findsWidgets);
        expect(
          find.text("We're reviewing your request. You'll be notified once it's resolved."),
          findsOneWidget,
        );
        expect(find.byIcon(Icons.hourglass_top_rounded), findsWidgets);
        expect(tester.takeException(), isNull);
      });
    }

    testWidgets('renders without exception in dark mode', (tester) async {
      await _pumpQuotationScreen(
        tester,
        _baseJson(status: 'price_review_requested'),
        width: 320,
        theme: AppTheme.dark,
      );

      expect(find.text('Price Review Requested'), findsWidgets);
      expect(tester.takeException(), isNull);
    });
  });

  testWidgets('an unmapped status keeps the existing neutral fallback treatment', (tester) async {
    await _pumpQuotationScreen(
      tester,
      _baseJson(status: 'accepted'),
    );

    expect(find.text('Accepted'), findsOneWidget);
  });
}
