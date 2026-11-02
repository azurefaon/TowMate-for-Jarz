import 'dart:async';

import 'package:flutter/material.dart';

import '../screens/customer/online_payment_status_screen.dart';

/// A parsed `https://www.jarztowing.com/pay/return/<outcome>?booking=<code>` App Link.
///
/// The outcome is kept only so the screen can be opened; it is NEVER used to decide whether a payment happened.
/// The status screen always asks the TowMate server for the verified state.
class PaymentReturnLink {
  const PaymentReturnLink({required this.bookingCode, required this.outcome});

  /// The canonical public domain. Must match the Android intent filter and assetlinks.json.
  static const host = 'www.jarztowing.com';
  static const pathPrefix = '/pay/return/';
  static const outcomes = {'success', 'cancelled', 'failed'};

  final String bookingCode;
  final String outcome;

  static final _bookingCode = RegExp(r'^[A-Za-z0-9-]{1,40}$');

  /// Null for anything that is not exactly a TowMate payment return link.
  static PaymentReturnLink? parse(Uri? uri) {
    if (uri == null || uri.scheme != 'https' || uri.host.toLowerCase() != host) return null;
    if (uri.hasPort && uri.port != 443) return null;
    if (uri.userInfo.isNotEmpty || !uri.path.startsWith(pathPrefix)) return null;

    final outcome = uri.path.substring(pathPrefix.length);
    final booking = uri.queryParameters['booking'];

    if (!outcomes.contains(outcome) || booking == null || !_bookingCode.hasMatch(booking)) return null;

    return PaymentReturnLink(bookingCode: booking, outcome: outcome);
  }
}

/// Opens the payment status screen when the app is opened from a payment return link: cold start, background and
/// foreground alike.
///
/// - A cold-start link waits until the launch routing has finished (`markReady`), so the sign-in gate cannot replace it.
/// - A link received while signed out is dropped; nothing is opened and nothing is remembered.
/// - The same link (or a screen already showing that booking) never opens a second screen.
class PaymentReturnLinkHandler {
  PaymentReturnLinkHandler({
    required this.links,
    required this.initialLink,
    required this.navigatorKey,
    required this.isSignedInCustomer,
    this.screenBuilder = _defaultScreen,
    this.duplicateWindow = const Duration(seconds: 3),
  });

  final Stream<Uri> links;
  final Future<Uri?> Function() initialLink;
  final GlobalKey<NavigatorState> navigatorKey;
  final Future<bool> Function() isSignedInCustomer;
  final Widget Function(String bookingCode) screenBuilder;
  final Duration duplicateWindow;

  StreamSubscription<Uri>? _subscription;
  PaymentReturnLink? _pending;
  bool _ready = false;
  String? _lastKey;
  DateTime? _lastAt;

  static Widget _defaultScreen(String bookingCode) => OnlinePaymentStatusScreen(bookingCode: bookingCode);

  Future<void> start() async {
    _subscription = links.listen(_receive, onError: (_) {});

    try {
      _receive(await initialLink());
    } catch (_) {
      // No initial link, or the platform could not provide one.
    }
  }

  void dispose() => _subscription?.cancel();

  /// The launch routing is done and a signed-in customer is on a stable screen: open anything that arrived early.
  void markReady() {
    _ready = true;
    final pending = _pending;
    _pending = null;
    if (pending != null) unawaited(_open(pending));
  }

  /// Launch routing ended somewhere that is not a signed-in customer (login, Team Leader, terms): forget early links.
  void discardPending() {
    _ready = true;
    _pending = null;
  }

  void _receive(Uri? uri) {
    final link = PaymentReturnLink.parse(uri);
    if (link == null) return;

    if (!_ready) {
      _pending = link; // a cold start: wait for the launch routing
      return;
    }

    unawaited(_open(link));
  }

  Future<void> _open(PaymentReturnLink link) async {
    final key = link.bookingCode;
    final now = DateTime.now();

    if (_lastKey == key && _lastAt != null && now.difference(_lastAt!) < duplicateWindow) return;
    if (OnlinePaymentStatusScreen.isVisible(link.bookingCode)) return; // it re-checks on resume by itself

    if (!await isSignedInCustomer()) return;

    final navigator = navigatorKey.currentState;
    if (navigator == null) return;

    _lastKey = key;
    _lastAt = now;

    unawaited(navigator.push(MaterialPageRoute<void>(
      settings: const RouteSettings(name: '/online-payment-status'),
      builder: (_) => screenBuilder(link.bookingCode),
    )));
  }
}
