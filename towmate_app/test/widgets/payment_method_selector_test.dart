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

  Future<String?> pumpAndTap(WidgetTester tester, String label, {required bool onlinePayAvailable}) async {
    String? chosen;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: StatefulBuilder(
          builder: (context, setState) => PaymentMethodSelector(
            value: chosen,
            onlinePayAvailable: onlinePayAvailable,
            onChanged: (v) => setState(() => chosen = v),
          ),
        ),
      ),
    ));
    await tester.tap(find.text(label));
    await tester.pump();
    return chosen;
  }

  testWidgets('shows three options and asks for no account details; Cash is always available', (tester) async {
    expect(await pumpAndTap(tester, 'Cash', onlinePayAvailable: false), 'cash');

    expect(find.text('GCash'), findsOneWidget);
    expect(find.text('Online Banking'), findsOneWidget);
    expect(find.byType(TextField), findsNothing);
    expect(find.byType(TextFormField), findsNothing);
    expect(find.byIcon(Icons.radio_button_checked_rounded), findsOneWidget);
    expect(find.textContaining('submitted now and you pay the Team Leader'), findsOneWidget);
  });

  testWidgets('GCash (Pay & Submit) can be chosen only when the server allows online payment', (tester) async {
    expect(await pumpAndTap(tester, 'GCash', onlinePayAvailable: false), isNull);
    expect(find.text('Online payment is not available for your account'), findsOneWidget);

    expect(await pumpAndTap(tester, 'GCash', onlinePayAvailable: true), 'gcash');
    expect(find.textContaining('TEST MODE — no real money'), findsOneWidget);
    expect(find.textContaining('submitted only after the payment is verified'), findsOneWidget);
  });

  testWidgets('Online Banking is visibly unavailable and cannot be chosen until the server switches it on', (tester) async {
    expect(await pumpAndTap(tester, 'Online Banking', onlinePayAvailable: true), isNull);
    expect(find.text('Not available yet'), findsOneWidget);

    String? chosen;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: PaymentMethodSelector(value: null, onlinePayAvailable: true, onlineBankingAvailable: true, onChanged: (v) => chosen = v),
      ),
    ));
    await tester.tap(find.text('Online Banking'));
    await tester.pump();
    expect(chosen, 'bank_transfer');
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
