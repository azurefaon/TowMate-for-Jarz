import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/customer/online_payment_status_screen.dart';
import 'package:towmate_app/services/payment_return_link.dart';

Uri _link(String outcome, [String booking = 'TM-00225']) =>
    Uri.parse('https://www.jarztowing.com/pay/return/$outcome?booking=$booking');

class _Harness {
  _Harness({this.signedIn = true, Uri? initial, Widget Function(String)? builder}) {
    handler = PaymentReturnLinkHandler(
      links: links.stream,
      initialLink: () async => initial,
      navigatorKey: navigatorKey,
      isSignedInCustomer: () async => signedIn,
      screenBuilder: builder ?? (code) => Scaffold(body: Text('status:$code', key: Key('screen_$code'))),
      duplicateWindow: const Duration(seconds: 3),
    );
  }

  final links = StreamController<Uri>.broadcast();
  final navigatorKey = GlobalKey<NavigatorState>();
  bool signedIn;
  late final PaymentReturnLinkHandler handler;

  Widget get app => MaterialApp(navigatorKey: navigatorKey, home: const Scaffold(body: Text('home')));
}

Future<void> _flush(WidgetTester tester) async {
  for (var i = 0; i < 5; i++) {
    await tester.pump(const Duration(milliseconds: 120));
  }
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  group('PaymentReturnLink.parse', () {
    test('accepts only the canonical https App Link', () {
      for (final outcome in ['success', 'cancelled', 'failed']) {
        final link = PaymentReturnLink.parse(_link(outcome));
        expect(link?.bookingCode, 'TM-00225');
        expect(link?.outcome, outcome);
      }
    });

    test('refuses anything else', () {
      for (final url in [
        'http://www.jarztowing.com/pay/return/success?booking=TM-1',
        'https://jarztowing.com/pay/return/success?booking=TM-1',
        'https://jarztowing.up.railway.app/pay/return/success?booking=TM-1',
        'https://www.jarztowing.com.evil.example/pay/return/success?booking=TM-1',
        'https://user@www.jarztowing.com/pay/return/success?booking=TM-1',
        'https://www.jarztowing.com:8443/pay/return/success?booking=TM-1',
        'https://www.jarztowing.com/pay/return/paid?booking=TM-1',
        'https://www.jarztowing.com/pay/return/success',
        'https://www.jarztowing.com/pay/return/success?booking=',
        'https://www.jarztowing.com/pay/return/success?booking=../../x',
        'https://www.jarztowing.com/pay/return/success?booking=<script>',
        'https://www.jarztowing.com/login',
        'towmate://pay/return/success?booking=TM-1',
      ]) {
        expect(PaymentReturnLink.parse(Uri.parse(url)), isNull, reason: url);
      }
      expect(PaymentReturnLink.parse(null), isNull);
    });

    test('a paid-looking parameter changes nothing: only the booking code is read', () {
      final link = PaymentReturnLink.parse(Uri.parse('https://www.jarztowing.com/pay/return/success?booking=TM-1&status=paid&amount=1'));
      expect(link?.bookingCode, 'TM-1');
    });
  });

  group('PaymentReturnLinkHandler', () {
    testWidgets('already running: a link opens the status screen for that booking', (tester) async {
      final h = _Harness();
      await tester.pumpWidget(h.app);
      await h.handler.start();
      h.handler.markReady();

      h.links.add(_link('success'));
      await _flush(tester);

      expect(find.byKey(const Key('screen_TM-00225')), findsOneWidget);
      h.handler.dispose();
    });

    testWidgets('cold start: the link waits for launch routing, then opens once', (tester) async {
      final h = _Harness(initial: _link('success'));
      await tester.pumpWidget(h.app);
      await h.handler.start();
      await _flush(tester);

      expect(find.byKey(const Key('screen_TM-00225')), findsNothing);
      expect(find.text('home'), findsOneWidget);

      h.handler.markReady();
      await _flush(tester);
      expect(find.byKey(const Key('screen_TM-00225')), findsOneWidget);

      h.handler.markReady();
      await _flush(tester);
      h.navigatorKey.currentState!.pop();
      await _flush(tester);
      expect(find.byKey(const Key('screen_TM-00225')), findsNothing);
      h.handler.dispose();
    });

    testWidgets('a cold-start link is dropped when launch routing ends signed out', (tester) async {
      final h = _Harness(initial: _link('success'), signedIn: false);
      await tester.pumpWidget(h.app);
      await h.handler.start();

      h.handler.discardPending();
      h.signedIn = true;
      h.handler.markReady();
      await _flush(tester);

      expect(find.byKey(const Key('screen_TM-00225')), findsNothing);
      h.handler.dispose();
    });

    testWidgets('a signed-out user never sees a payment screen', (tester) async {
      final h = _Harness(signedIn: false);
      await tester.pumpWidget(h.app);
      await h.handler.start();
      h.handler.markReady();

      h.links.add(_link('success'));
      await _flush(tester);

      expect(find.byKey(const Key('screen_TM-00225')), findsNothing);
      h.handler.dispose();
    });

    testWidgets('the same link delivered twice opens one screen', (tester) async {
      final h = _Harness();
      await tester.pumpWidget(h.app);
      await h.handler.start();
      h.handler.markReady();

      h.links.add(_link('success'));
      h.links.add(_link('success'));
      await _flush(tester);

      expect(find.byKey(const Key('screen_TM-00225')), findsOneWidget);
      h.navigatorKey.currentState!.pop();
      await _flush(tester);
      expect(find.byKey(const Key('screen_TM-00225')), findsNothing); // no second screen underneath
      h.handler.dispose();
    });

    testWidgets('a different booking opens its own screen', (tester) async {
      final h = _Harness();
      await tester.pumpWidget(h.app);
      await h.handler.start();
      h.handler.markReady();

      h.links.add(_link('success', 'TM-1'));
      await _flush(tester);
      h.links.add(_link('cancelled', 'TM-2'));
      await _flush(tester);

      expect(find.byKey(const Key('screen_TM-2')), findsOneWidget);
      h.handler.dispose();
    });

    testWidgets('invalid links are ignored', (tester) async {
      final h = _Harness();
      await tester.pumpWidget(h.app);
      await h.handler.start();
      h.handler.markReady();

      h.links.add(Uri.parse('https://evil.example/pay/return/success?booking=TM-1'));
      await _flush(tester);

      expect(find.text('home'), findsOneWidget);
      h.handler.dispose();
    });

    testWidgets('if the status screen for that booking is already showing, no second one is stacked', (tester) async {
      SharedPreferences.setMockInitialValues({'auth_token': 't', 'user_role': 'Customer'});

      var checks = 0;
      final client = MockClient((request) async {
        checks++;
        return http.Response(
          jsonEncode({
            'success': true,
            'data': {'reference': 'R', 'status': 'pending', 'amount': '1.00', 'currency': 'PHP', 'method': 'gcash'},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      });

      await http.runWithClient(() async {
        final h = _Harness(builder: (code) => OnlinePaymentStatusScreen(bookingCode: code));
        await tester.pumpWidget(h.app);
        await h.handler.start();
        h.handler.markReady();

        h.links.add(_link('success'));
        await _flush(tester);
        expect(find.byType(OnlinePaymentStatusScreen), findsOneWidget);
        expect(OnlinePaymentStatusScreen.isVisible('TM-00225'), isTrue);

        await tester.pump(const Duration(seconds: 4)); // outside the duplicate window
        h.links.add(_link('success'));
        await _flush(tester);

        expect(find.byType(OnlinePaymentStatusScreen), findsOneWidget);
        // Whatever the link said, the screen asked the server.
        expect(checks, greaterThanOrEqualTo(1));

        await tester.pumpWidget(const SizedBox());
        expect(OnlinePaymentStatusScreen.isVisible('TM-00225'), isFalse);
        h.handler.dispose();
      }, () => client);
    });
  });
}
