import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/models/booking_model.dart';
import 'package:towmate_app/widgets/payment_method_selector.dart';

void main() {
  test('labels map backend identifiers, with bank_transfer shown as Online Banking', () {
    expect(preferredPaymentLabel('cash'), 'Cash');
    expect(preferredPaymentLabel('gcash'), 'GCash');
    expect(preferredPaymentLabel('bank_transfer'), 'Online Banking');
    expect(preferredPaymentLabel(null), isNull);
    expect(preferredPaymentLabel('online_banking'), isNull);
  });

  test('BookingGroupSibling parses the preference and tolerates older responses', () {
    final withPref = BookingGroupSibling.fromJson({
      'booking_code': 'TM-1',
      'service_type': 'book_now',
      'status': 'requested',
      'preferred_payment_method': 'gcash',
    });
    final legacy = BookingGroupSibling.fromJson({
      'booking_code': 'TM-2',
      'service_type': 'book_now',
      'status': 'requested',
    });

    expect(withPref.preferredPaymentMethod, 'gcash');
    expect(legacy.preferredPaymentMethod, isNull);
  });

  testWidgets('shows three options, reports the tapped value, and asks for no account details', (tester) async {
    String? chosen;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: StatefulBuilder(
          builder: (context, setState) => PaymentMethodSelector(
            value: chosen,
            onChanged: (v) => setState(() => chosen = v),
          ),
        ),
      ),
    ));

    expect(find.text('Cash'), findsOneWidget);
    expect(find.text('GCash'), findsOneWidget);
    expect(find.text('Online Banking'), findsOneWidget);
    expect(find.byType(TextField), findsNothing);
    expect(find.byType(TextFormField), findsNothing);

    await tester.tap(find.text('Online Banking'));
    await tester.pump();
    expect(chosen, 'bank_transfer');
    expect(find.byIcon(Icons.radio_button_checked_rounded), findsOneWidget);
  });

  testWidgets('disabled selector ignores taps', (tester) async {
    String? chosen;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: PaymentMethodSelector(value: null, enabled: false, onChanged: (v) => chosen = v),
      ),
    ));

    await tester.tap(find.text('GCash'));
    await tester.pump();
    expect(chosen, isNull);
  });
}
