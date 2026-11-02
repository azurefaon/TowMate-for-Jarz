import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/models/booking_model.dart';
import 'package:towmate_app/models/online_payment_model.dart';
import 'package:towmate_app/screens/customer/online_payment_status_screen.dart';
import 'package:towmate_app/services/checkout_launcher.dart';
import 'package:towmate_app/widgets/online_payment_card.dart';

const _checkoutUrl = 'https://checkout.paymongo.com/cs_test_abc';

http.Response _json(Object body, {int status = 200}) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});

Map<String, dynamic> _tx(String status, {bool withUrl = false, bool completed = false}) => {
      'reference': 'TM-PAY-1',
      'status': status,
      'method': 'gcash',
      'amount': '2352.35',
      'currency': 'PHP',
      'expires_at': null,
      'paid_at': status == 'paid' ? '2026-11-01T10:00:00+08:00' : null,
      if (withUrl) 'checkout_url': _checkoutUrl,
      'booking_status': completed ? 'completed' : 'arrived_dropoff',
      'booking_completed': completed,
    };

class _FakeLauncher implements CheckoutLauncher {
  _FakeLauncher([this.result = CheckoutLaunchResult.secureInAppBrowser]);

  final CheckoutLaunchResult result;
  final opened = <String>[];

  @override
  Future<CheckoutLaunchResult> open(String checkoutUrl) async {
    opened.add(checkoutUrl);
    return result;
  }
}

/// A backend whose payment state the test controls. Records every call.
class _Backend {
  Map<String, dynamic>? transaction;
  int statusCalls = 0;
  int startCalls = 0;
  int cancelCalls = 0;
  Map<String, dynamic>? startTransaction;
  bool startOk = true;
  int statusCode = 200;

  http.Client get client => MockClient((request) async {
        final path = request.url.path;

        if (path.endsWith('/online-payment/checkout')) {
          startCalls++;
          // The client may only name the channel; never an amount.
          expect(jsonDecode(request.body), {'method': 'gcash'});
          return startOk
              ? _json({'success': true, 'data': startTransaction ?? _tx('pending', withUrl: true)})
              : _json({'success': false, 'reason': 'not_eligible', 'message': 'Online payment is not available.'}, status: 422);
        }

        if (path.endsWith('/online-payment/cancel')) {
          cancelCalls++;
          transaction = _tx('cancelled');
          return _json({'success': true, 'data': transaction});
        }

        if (path.endsWith('/online-payment')) {
          statusCalls++;
          if (statusCode != 200) return _json({'success': false}, status: statusCode);
          return _json({'success': true, 'data': transaction});
        }

        return _json({'success': false}, status: 404);
      });
}

Future<void> _run(WidgetTester tester, _Backend backend, Future<void> Function() body) async {
  // A tall phone, so every status action is laid out (the list builds lazily).
  tester.view.physicalSize = const Size(800, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
  await http.runWithClient(body, () => backend.client);
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 6; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Widget _statusScreen(_FakeLauncher launcher, {int maxPolls = 100}) => MaterialApp(
      home: OnlinePaymentStatusScreen(
        bookingCode: 'TM-00225',
        launcher: launcher,
        pollInterval: const Duration(seconds: 3),
        maxPolls: maxPolls,
      ),
    );

OnlinePaymentOffer _offer({bool eligible = true, Map<String, dynamic>? tx, bool group = false}) =>
    OnlinePaymentOffer.fromJson({
      'available': true,
      'eligible': eligible,
      'reason': eligible ? null : 'already_paid',
      'amount': '2352.35',
      'currency': 'PHP',
      'method': 'gcash',
      'method_label': 'GCash',
      'is_group': group,
      'transaction': tx,
    })!;

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  group('online payment models', () {
    test('statuses map to phases and only a verified paid is paid', () {
      expect(OnlinePaymentStatus.parse('paid').isPaid, isTrue);

      for (final s in ['pending', 'failed', 'cancelled', 'expired', 'review', 'duplicate', 'weird', null]) {
        expect(OnlinePaymentStatus.parse(s).isPaid, isFalse, reason: '$s');
      }

      expect(OnlinePaymentStatus.parse('duplicate'), OnlinePaymentStatus.review);
      expect(OnlinePaymentStatus.parse('weird'), OnlinePaymentStatus.unknown);
      expect(OnlinePaymentStatus.pending.isFinal, isFalse);
      expect(OnlinePaymentStatus.failed.canRetry, isTrue);
      expect(OnlinePaymentStatus.paid.canRetry, isFalse);
      expect(OnlinePaymentStatus.review.canRetry, isFalse);
    });

    test('phase labels follow the verified state, and verifying is only a UI phase', () {
      OnlinePaymentTransaction tx(String s) => OnlinePaymentTransaction.fromJson(_tx(s));

      expect(OnlinePaymentPhase.of(null, checking: false).label, 'Not paid yet');
      expect(OnlinePaymentPhase.of(null, checking: true).label, 'Verifying Payment');
      expect(OnlinePaymentPhase.of(tx('pending'), checking: false).label, 'Payment Pending');
      expect(OnlinePaymentPhase.of(tx('pending'), checking: true).label, 'Verifying Payment');
      expect(OnlinePaymentPhase.of(tx('paid'), checking: true).label, 'Paid');
      expect(OnlinePaymentPhase.of(tx('failed'), checking: false).label, 'Failed');
      expect(OnlinePaymentPhase.of(tx('cancelled'), checking: false).label, 'Cancelled');
      expect(OnlinePaymentPhase.of(tx('weird'), checking: false), isNot(OnlinePaymentPhase.paid));
    });

    test('offer parses only when the backend says it is available', () {
      expect(OnlinePaymentOffer.fromJson(null), isNull);
      expect(OnlinePaymentOffer.fromJson({'available': false}), isNull);
      expect(OnlinePaymentOffer.fromJson('x'), isNull);

      final offer = _offer();
      expect(offer.eligible, isTrue);
      expect(offer.amount, '2352.35');
      expect(offer.worthShowing, isTrue);
      expect(_offer(eligible: false).worthShowing, isFalse);
      expect(_offer(eligible: false, tx: _tx('paid')).worthShowing, isTrue);
    });

    test('the booking model carries the offer and tolerates its absence', () {
      final base = {
        'booking_code': 'TM-1',
        'status': 'arrived_dropoff',
        'service_type': 'book_now',
        'pickup_address': 'A',
        'dropoff_address': 'B',
      };
      final withOffer = BookingModel.fromJson({
        ...base,
        'online_payment': {'available': true, 'eligible': true, 'amount': '10.00', 'method_label': 'GCash'},
      });
      final without = BookingModel.fromJson(base);

      expect(withOffer.onlinePayment?.eligible, isTrue);
      expect(without.onlinePayment, isNull);
    });
  });

  group('UrlLauncherCheckoutLauncher', () {
    bool trusted(String url) => UrlLauncherCheckoutLauncher.isTrustedCheckoutUri(Uri.tryParse(url));

    test('accepts https PayMongo hosts only', () {
      expect(trusted('https://checkout.paymongo.com/cs_test_abc'), isTrue);
      expect(trusted('https://pm.link/abc'), isFalse);
      expect(trusted('http://checkout.paymongo.com/x'), isFalse);
      expect(trusted('https://paymongo.com.evil.example/x'), isFalse);
      expect(trusted('https://evilpaymongo.com/x'), isFalse);
      expect(trusted('https://user:pw@checkout.paymongo.com/x'), isFalse);
      expect(trusted('https://checkout.paymongo.com:8443/x'), isFalse);
      expect(trusted('intent://checkout.paymongo.com/x'), isFalse);
      expect(trusted('javascript:alert(1)'), isFalse);
      expect(trusted('gcash://pay'), isFalse);
      expect(UrlLauncherCheckoutLauncher.isTrustedCheckoutUri(null), isFalse);
    });

    test('refuses an untrusted address without opening anything', () async {
      final result = await const UrlLauncherCheckoutLauncher().open('https://evil.example/pay');
      expect(result, CheckoutLaunchResult.rejected);
    });
  });

  group('OnlinePaymentCard', () {
    Widget host(OnlinePaymentOffer offer, _FakeLauncher launcher, {VoidCallback? onChanged}) => MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(
              child: OnlinePaymentCard(
                bookingCode: 'TM-00225',
                offer: offer,
                launcher: launcher,
                onChanged: onChanged ?? () {},
              ),
            ),
          ),
        );

    testWidgets('shows the server amount, the method and Pay Now when eligible', (tester) async {
      await tester.pumpWidget(host(_offer(), _FakeLauncher()));

      expect(find.text('₱2,352.35'), findsOneWidget);
      expect(find.text('GCash'), findsOneWidget);
      expect(find.text('Pay Now'), findsOneWidget);
      expect(find.textContaining('never sees your GCash PIN'), findsOneWidget);
      expect(find.text('TEST PAYMENT — No real money will be charged.'), findsOneWidget);
    });

    testWidgets('labels a group total', (tester) async {
      await tester.pumpWidget(host(_offer(group: true), _FakeLauncher()));
      expect(find.text('Total for all vehicles'), findsOneWidget);
    });

    testWidgets('no Pay Now when the booking is not eligible; paid says the dispatcher still confirms', (tester) async {
      await tester.pumpWidget(host(_offer(eligible: false, tx: _tx('paid')), _FakeLauncher()));

      expect(find.byKey(const Key('online_payment_pay_now')), findsNothing);
      expect(find.text('Paid'), findsOneWidget);
      expect(find.textContaining('once the dispatcher confirms it'), findsOneWidget);
      expect(find.byKey(const Key('online_payment_view_status')), findsOneWidget);
    });

    testWidgets('a pending attempt offers Continue Payment', (tester) async {
      await tester.pumpWidget(host(_offer(tx: _tx('pending')), _FakeLauncher()));

      expect(find.text('Continue Payment'), findsOneWidget);
      expect(find.text('Payment Pending'), findsOneWidget);
    });

    testWidgets('Pay Now starts checkout, opens the secure browser, then lands on the status screen', (tester) async {
      final backend = _Backend()..transaction = _tx('pending', withUrl: true);
      final launcher = _FakeLauncher();
      var changed = 0;

      await _run(tester, backend, () async {
        await tester.pumpWidget(host(_offer(), launcher, onChanged: () => changed++));
        await tester.tap(find.byKey(const Key('online_payment_pay_now')));
        await _settle(tester);

        expect(backend.startCalls, 1);
        expect(launcher.opened, [_checkoutUrl]);
        expect(find.byType(OnlinePaymentStatusScreen), findsOneWidget);
        // Opening the checkout proves nothing: the status screen asked the backend.
        expect(backend.statusCalls, greaterThanOrEqualTo(1));
        expect(find.text('Payment Pending'), findsOneWidget);

        await tester.tap(find.byKey(const Key('payment_check')));
        await _settle(tester);
        expect(find.text('Paid'), findsNothing);

        // Leaving the status screen refreshes the booking.
        await tester.pageBack();
        await _settle(tester);
        expect(changed, 1);
      });
    });

    testWidgets('a refused start shows the server message and opens nothing', (tester) async {
      final backend = _Backend()..startOk = false;
      final launcher = _FakeLauncher();

      await _run(tester, backend, () async {
        await tester.pumpWidget(host(_offer(), launcher));
        await tester.tap(find.byKey(const Key('online_payment_pay_now')));
        await _settle(tester);

        expect(find.text('Online payment is not available.'), findsOneWidget);
        expect(launcher.opened, isEmpty);
        expect(find.byType(OnlinePaymentStatusScreen), findsNothing);
      });
    });

    testWidgets('if the secure page cannot be opened the customer stays put and is told', (tester) async {
      final backend = _Backend();
      final launcher = _FakeLauncher(CheckoutLaunchResult.rejected);

      await _run(tester, backend, () async {
        await tester.pumpWidget(host(_offer(), launcher));
        await tester.tap(find.byKey(const Key('online_payment_pay_now')));
        await _settle(tester);

        expect(find.text('Could not open the secure payment page. Please try again.'), findsOneWidget);
        expect(find.byType(OnlinePaymentStatusScreen), findsNothing);
      });
    });
  });

  group('OnlinePaymentStatusScreen — Pay & Submit (pay at booking)', () {
    Map<String, dynamic> payAtBooking(String status, {bool submitted = false}) => {
          ..._tx(status),
          'pay_at_booking': true,
          'booking_submitted': submitted,
          'booking_status': submitted ? 'confirmed' : 'awaiting_payment',
          if (submitted)
            'bookings': [
              {'booking_code': 'TM-00225', 'service_type': 'book_now', 'status': 'confirmed', 'preferred_payment_method': 'gcash', 'is_current': true},
            ],
        };

    testWidgets('paid but not yet submitted keeps verifying and shows no success', (tester) async {
      final backend = _Backend()..transaction = payAtBooking('paid');
      await _run(tester, backend, () async {
        await tester.pumpWidget(_statusScreen(_FakeLauncher()));
        await _settle(tester);

        expect(find.byKey(const Key('payment_status_label')), findsOneWidget);
        expect(find.text('Verifying Payment'), findsOneWidget);
        expect(find.text('Booking Successful'), findsNothing);
        expect(find.text('Not submitted yet'), findsOneWidget);
      });
    });

    testWidgets('moves to BOOKING SUCCESSFUL only when the server reports the booking submitted', (tester) async {
      var submittedCallbacks = 0;
      final backend = _Backend()..transaction = payAtBooking('pending', submitted: false);
      await _run(tester, backend, () async {
        await tester.pumpWidget(MaterialApp(
          home: OnlinePaymentStatusScreen(
            bookingCode: 'TM-00225',
            launcher: _FakeLauncher(),
            pollInterval: const Duration(seconds: 3),
            onSubmitted: () => submittedCallbacks++,
          ),
        ));
        await _settle(tester);
        expect(find.text('Booking Successful'), findsNothing);

        backend.transaction = payAtBooking('paid', submitted: true);
        await tester.pump(const Duration(seconds: 3));
        await _settle(tester);
        await tester.pumpAndSettle(); // the status screen is replaced, not stacked under the success screen

        expect(find.byType(OnlinePaymentStatusScreen), findsNothing);
        expect(find.text('Booking Successful'), findsOneWidget);
        expect(find.text('TEST PAYMENT — No real money will be charged.'), findsOneWidget);
        expect(find.text('TM-00225'), findsOneWidget);
        expect(submittedCallbacks, 1);
        expect(find.textContaining('Submit'), findsNothing); // no second Submit button
      });
    });

    testWidgets('a cancelled payment says the booking was not submitted and offers Try again or Back to Review', (tester) async {
      final backend = _Backend()..transaction = payAtBooking('cancelled');
      await _run(tester, backend, () async {
        await tester.pumpWidget(_statusScreen(_FakeLauncher()));
        await _settle(tester);

        expect(find.textContaining('Your booking has not been submitted'), findsOneWidget);
        expect(find.text('Try again'), findsOneWidget);
        expect(find.text('Back to Review'), findsOneWidget);
        expect(find.text('Booking Successful'), findsNothing);
      });
    });

    testWidgets('parses the submission facts and the submitted bookings', (tester) async {
      final tx = OnlinePaymentTransaction.fromJson(payAtBooking('paid', submitted: true));
      expect(tx.payAtBooking, isTrue);
      expect(tx.bookingSubmitted, isTrue);
      expect(tx.bookings.single.bookingCode, 'TM-00225');
    });
  });

  group('OnlinePaymentStatusScreen', () {
    testWidgets('pending stays Payment Pending and never turns Paid on its own', (tester) async {
      final backend = _Backend()..transaction = _tx('pending', withUrl: true);

      await _run(tester, backend, () async {
        await tester.pumpWidget(_statusScreen(_FakeLauncher()));
        await _settle(tester);

        expect(find.byKey(const Key('payment_status_label')), findsOneWidget);
        expect(find.text('Payment Pending'), findsOneWidget);
        expect(find.text('Paid'), findsNothing);

        // Time passes with the backend still pending: it keeps asking, it never assumes.
        await tester.pump(const Duration(seconds: 9));
        expect(backend.statusCalls, greaterThan(1));
        expect(find.text('Payment Pending'), findsOneWidget);

        await tester.pumpWidget(const SizedBox());
      });
    });

    testWidgets('becomes Paid only when the backend reports paid, and says the booking is not completed yet', (tester) async {
      final backend = _Backend()..transaction = _tx('pending', withUrl: true);

      await _run(tester, backend, () async {
        await tester.pumpWidget(_statusScreen(_FakeLauncher()));
        await _settle(tester);
        expect(find.text('Payment Pending'), findsOneWidget);

        backend.transaction = _tx('paid');
        await tester.pump(const Duration(seconds: 3));
        await _settle(tester);

        expect(find.text('Paid'), findsOneWidget);
        expect(find.textContaining('once the dispatcher confirms it'), findsOneWidget);
        expect(find.byKey(const Key('payment_done')), findsOneWidget);

        // Final: polling stops.
        final calls = backend.statusCalls;
        await tester.pump(const Duration(seconds: 12));
        expect(backend.statusCalls, calls);
      });
    });

    testWidgets('a completed booking is reported as such, separately from the payment', (tester) async {
      final backend = _Backend()..transaction = _tx('paid', completed: true);

      await _run(tester, backend, () async {
        await tester.pumpWidget(_statusScreen(_FakeLauncher()));
        await _settle(tester);

        expect(find.text('Paid'), findsOneWidget);
        expect(find.textContaining('once the dispatcher confirms it'), findsNothing);
      });
    });

    testWidgets('failed offers Try again, which starts a fresh checkout and reopens the secure page', (tester) async {
      final backend = _Backend()..transaction = _tx('failed');
      final launcher = _FakeLauncher();

      await _run(tester, backend, () async {
        await tester.pumpWidget(_statusScreen(launcher));
        await _settle(tester);

        expect(find.text('Failed'), findsOneWidget);
        expect(find.byKey(const Key('payment_try_again')), findsOneWidget);

        backend.transaction = _tx('pending', withUrl: true);
        await tester.tap(find.byKey(const Key('payment_try_again')));
        await _settle(tester);

        expect(backend.startCalls, 1);
        expect(launcher.opened, [_checkoutUrl]);
        expect(find.text('Payment Pending'), findsOneWidget);

        await tester.pumpWidget(const SizedBox());
      });
    });

    testWidgets('pending can reopen the page and can be cancelled after confirmation', (tester) async {
      final backend = _Backend()..transaction = _tx('pending', withUrl: true);
      final launcher = _FakeLauncher();
      tester.view.physicalSize = const Size(800, 2400);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await _run(tester, backend, () async {
        await tester.pumpWidget(_statusScreen(launcher));
        await _settle(tester);

        await tester.tap(find.byKey(const Key('payment_reopen')));
        await _settle(tester);
        expect(launcher.opened, [_checkoutUrl]);

        await tester.tap(find.byKey(const Key('payment_cancel')));
        await _settle(tester);
        expect(find.text('Cancel this payment?'), findsOneWidget);

        // "Keep it" cancels nothing.
        await tester.tap(find.text('Keep it'));
        await _settle(tester);
        expect(backend.cancelCalls, 0);

        await tester.tap(find.byKey(const Key('payment_cancel')));
        await _settle(tester);
        await tester.tap(find.text('Cancel payment').last);
        await _settle(tester);

        expect(backend.cancelCalls, 1);
        expect(find.text('Cancelled'), findsOneWidget);
      });
    });

    testWidgets('an unreachable backend keeps the last known state and concludes nothing', (tester) async {
      final backend = _Backend()..transaction = _tx('pending', withUrl: true);

      await _run(tester, backend, () async {
        await tester.pumpWidget(_statusScreen(_FakeLauncher()));
        await _settle(tester);
        expect(find.text('Payment Pending'), findsOneWidget);

        backend
          ..statusCode = 500
          ..transaction = _tx('paid');
        await tester.tap(find.byKey(const Key('payment_check')));
        await _settle(tester);

        expect(find.byKey(const Key('payment_status_offline')), findsOneWidget);
        expect(find.text('Paid'), findsNothing);

        await tester.pumpWidget(const SizedBox());
      });
    });

    testWidgets('returning from the browser (app resumed) re-checks immediately', (tester) async {
      final backend = _Backend()..transaction = _tx('pending', withUrl: true);

      await _run(tester, backend, () async {
        await tester.pumpWidget(_statusScreen(_FakeLauncher()));
        await _settle(tester);
        final before = backend.statusCalls;

        backend.transaction = _tx('paid');
        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
        await _settle(tester);

        expect(backend.statusCalls, greaterThan(before));
        expect(find.text('Paid'), findsOneWidget);
      });
    });

    testWidgets('no payment started yet is Not paid, not Paid', (tester) async {
      final backend = _Backend()..transaction = null;

      await _run(tester, backend, () async {
        await tester.pumpWidget(_statusScreen(_FakeLauncher()));
        await _settle(tester);

        expect(find.text('Not paid yet'), findsOneWidget);
        expect(find.text('TEST PAYMENT — No real money will be charged.'), findsOneWidget);
        expect(find.text('Paid'), findsNothing);

        await tester.pumpWidget(const SizedBox());
      });
    });

    testWidgets('a review state is shown as under review, never as paid', (tester) async {
      final backend = _Backend()..transaction = _tx('review');

      await _run(tester, backend, () async {
        await tester.pumpWidget(_statusScreen(_FakeLauncher()));
        await _settle(tester);

        expect(find.text('Under Review'), findsOneWidget);
        expect(find.text('Paid'), findsNothing);
        expect(find.byKey(const Key('payment_try_again')), findsNothing);
      });
    });
  });
}
