import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/widgets/next_task_offer_slot.dart';

http.Response _json(Object body, int status) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});

Map<String, dynamic> _offer() => {
      'id': 9,
      'status': 'offered',
      'seconds_left': 480,
      'current_booking_code': 'BK-CURRENT',
      'booking': {
        'booking_code': 'BK-NEXT',
        'pickup_address': '12 Sample St',
        'tow_class': 'Light Duty',
        'is_scheduled': false,
      },
    };

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  setUp(() {
    SharedPreferences.setMockInitialValues({'auth_token': 'live-token', 'user_role': 'Team Leader'});
  });

  // Mirrors the Payment step: a pushed Scaffold whose bottom slot carries the offer.
  Future<void> pumpPaymentRoute(WidgetTester tester, http.Client client) async {
    await tester.runAsync(() => http.runWithClient(() async {
          await tester.pumpWidget(const MaterialApp(
            home: Scaffold(
              body: Center(child: Text('Payment')),
              bottomNavigationBar: NextTaskOfferSlot(),
            ),
          ));
          await Future<void>.delayed(const Duration(milliseconds: 300));
        }, () => client));
    await tester.pump();
  }

  testWidgets('shows the offer card on a screen pushed over the shell', (tester) async {
    final client = MockClient((r) async => _json({'success': true, 'data': _offer()}, 200));
    await pumpPaymentRoute(tester, client);

    expect(find.text('Payment'), findsOneWidget);
    expect(find.text('Next task offer'), findsOneWidget);
    expect(find.textContaining('BK-NEXT'), findsOneWidget);
    expect(find.byKey(const Key('nextTaskAccept')), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('shows nothing when no offer is queued', (tester) async {
    final client = MockClient((r) async => _json({'success': true, 'data': null}, 200));
    await pumpPaymentRoute(tester, client);

    expect(find.text('Payment'), findsOneWidget);
    expect(find.byKey(const Key('nextTaskOfferCard')), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('a failed lookup never breaks the payment screen', (tester) async {
    final client = MockClient((r) async => _json({'message': 'Server Error'}, 500));
    await pumpPaymentRoute(tester, client);

    expect(find.text('Payment'), findsOneWidget);
    expect(find.byKey(const Key('nextTaskOfferCard')), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });
}
