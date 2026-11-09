import 'dart:async';

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';

import '../../core/theme.dart';
import '../../models/online_payment_model.dart';
import '../../services/api_service.dart';
import '../../services/checkout_launcher.dart';
import '../../widgets/online_payment_card.dart' show TestPaymentBanner;
import '../../widgets/quotation_price_cards.dart';
import 'booking_success_screen.dart';

/// Where the customer lands after the hosted checkout. It shows ONLY what the Laravel backend has verified
/// (signed PayMongo webhook, or the server asking PayMongo directly): the browser closing, a redirect, or the GCash app
/// or bank page returning proves nothing here.
///
/// Pay & Submit: "Verifying payment" until the server reports the payment verified AND the booking submitted, then
/// BOOKING SUCCESSFUL. A cancelled, failed or expired payment leaves the booking unsubmitted, with a retry.
class OnlinePaymentStatusScreen extends StatefulWidget {
  const OnlinePaymentStatusScreen({
    super.key,
    required this.bookingCode,
    this.launcher = const UrlLauncherCheckoutLauncher(),
    this.pollInterval = const Duration(seconds: 3),
    this.maxPolls = 100,
    this.onSubmitted,
  });

  final String bookingCode;
  final CheckoutLauncher launcher;
  final Duration pollInterval;

  /// Called once when the paid booking has been submitted (e.g. to clear the saved booking draft).
  final VoidCallback? onSubmitted;

  /// How many automatic checks before waiting for the customer to ask again (about 5 minutes by default).
  final int maxPolls;

  static final _visible = <String>{};

  /// True while a status screen for this booking is on screen, so a return link does not stack a second one.
  static bool isVisible(String bookingCode) => _visible.contains(bookingCode);

  @override
  State<OnlinePaymentStatusScreen> createState() => _OnlinePaymentStatusScreenState();
}

class _OnlinePaymentStatusScreenState extends State<OnlinePaymentStatusScreen> with WidgetsBindingObserver {
  static final _money = NumberFormat('#,##0.00', 'en_PH');

  OnlinePaymentTransaction? _transaction;
  bool _checking = true;
  bool _unavailable = false;
  bool _unreachable = false;
  bool _busy = false;
  int _polls = 0;
  Timer? _timer;
  bool _leftForSuccess = false;

  bool get _payAtBooking => _transaction?.payAtBooking ?? false;

  /// Paid at PayMongo, but the server has not submitted the booking yet: still verifying, keep asking.
  bool get _awaitingSubmission => _payAtBooking && (_transaction?.status.isPaid ?? false) && !(_transaction?.bookingSubmitted ?? false);

  bool get _isFinal => (_transaction?.status.isFinal ?? false) && !_awaitingSubmission;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    OnlinePaymentStatusScreen._visible.add(widget.bookingCode);
    _check();
    _startPolling();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    OnlinePaymentStatusScreen._visible.remove(widget.bookingCode);
    _timer?.cancel();
    super.dispose();
  }

  /// The customer came back from the browser or the GCash app: ask the backend right away.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && !_isFinal) {
      _polls = 0;
      _check();
      _startPolling();
    }
  }

  void _startPolling() {
    _timer?.cancel();
    _timer = Timer.periodic(widget.pollInterval, (_) {
      if (!mounted || _isFinal || _unavailable || _polls >= widget.maxPolls) {
        _timer?.cancel();
        return;
      }
      _check(silent: true);
    });
  }

  /// Asks the backend for the verified state. Automatic polls are `silent` so the label does not flicker between
  /// "Payment Pending" and "Verifying Payment"; the first check, a return from the browser and the customer's own
  /// "Check status" are visible.
  Future<void> _check({bool silent = false}) async {
    if (mounted && !silent) setState(() => _checking = true);

    final result = await ApiService.fetchOnlinePaymentStatus(widget.bookingCode);
    if (!mounted) return;

    setState(() {
      _checking = false;
      _polls++;

      if (!result.reachable) {
        _unreachable = true; // keep what we last knew; conclude nothing
        return;
      }

      _unreachable = false;
      _unavailable = !result.available;
      if (result.available) _transaction = result.transaction;
    });

    if (_isFinal || _unavailable) _timer?.cancel();
    _showSuccessIfSubmitted();
  }

  /// The server verified the payment and submitted the booking: BOOKING SUCCESSFUL, done (no second Submit).
  void _showSuccessIfSubmitted() {
    final tx = _transaction;
    if (_leftForSuccess || tx == null || !tx.payAtBooking || !tx.status.isPaid || !tx.bookingSubmitted) return;
    _leftForSuccess = true;
    _timer?.cancel();
    widget.onSubmitted?.call();

    if (tx.bookings.isEmpty) {
      Navigator.of(context).pushNamedAndRemoveUntil('/booking-detail', (route) => route.isFirst, arguments: widget.bookingCode);
      return;
    }
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute<void>(builder: (_) => BookingSuccessScreen(bookings: tx.bookings, paidOnline: true)),
      (_) => false,
    );
  }

  void _snack(String message) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _reopen() async {
    final url = _transaction?.checkoutUrl;
    if (url == null || _busy) return;

    setState(() => _busy = true);
    final result = await widget.launcher.open(url);
    if (!mounted) return;
    setState(() => _busy = false);

    if (result == CheckoutLaunchResult.rejected || result == CheckoutLaunchResult.failed) {
      _snack('Could not open the secure payment page. Please try again.');
    }
  }

  /// Starts a fresh attempt after a failed, cancelled or expired one. The server decides whether that is allowed.
  Future<void> _tryAgain() async {
    if (_busy) return;
    setState(() => _busy = true);

    final started = await ApiService.startOnlineCheckout(widget.bookingCode, method: _transaction?.method ?? 'gcash', bank: _transaction?.bank);
    if (!mounted) return;

    final transaction = started['transaction'] as OnlinePaymentTransaction?;
    final url = transaction?.checkoutUrl;

    if (started['success'] != true || url == null) {
      setState(() => _busy = false);
      _snack((started['message'] as String?)?.isNotEmpty == true ? started['message'] as String : 'Could not start the payment. Please try again.');
      return;
    }

    final result = await widget.launcher.open(url);
    if (!mounted) return;
    setState(() => _busy = false);

    if (result == CheckoutLaunchResult.rejected || result == CheckoutLaunchResult.failed) {
      _snack('Could not open the secure payment page. Please try again.');
    }

    _polls = 0;
    await _check();
    _startPolling();
  }

  Future<void> _cancel() async {
    if (_busy) return;

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Cancel this payment?'),
        content: const Text('Only cancel if you have not completed the payment. You can start again at any time.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Keep it')),
          TextButton(onPressed: () => Navigator.pop(context, true), child: const Text('Cancel payment')),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;

    setState(() => _busy = true);
    final result = await ApiService.cancelOnlinePayment(widget.bookingCode);
    if (!mounted) return;
    setState(() => _busy = false);

    if (result['success'] != true) {
      _snack((result['message'] as String?)?.isNotEmpty == true ? result['message'] as String : 'Could not cancel the payment right now.');
    }

    // Whatever happened, the backend's verified state is what we show.
    await _check();
  }

  // ── presentation ──────────────────────────────────────────────────────────

  OnlinePaymentPhase get _phase =>
      _awaitingSubmission ? OnlinePaymentPhase.verifying : OnlinePaymentPhase.of(_transaction, checking: _checking);

  (IconData, Color) _look(OnlinePaymentPhase phase, BuildContext context) => switch (phase) {
        OnlinePaymentPhase.paid => (Icons.check_circle_rounded, TmColors.success),
        OnlinePaymentPhase.failed => (Icons.error_rounded, TmColors.error),
        OnlinePaymentPhase.cancelled => (Icons.cancel_rounded, context.textSecondary),
        OnlinePaymentPhase.expired => (Icons.timer_off_rounded, context.textSecondary),
        OnlinePaymentPhase.review => (Icons.hourglass_top_rounded, TmColors.yellow),
        OnlinePaymentPhase.verifying => (Icons.sync_rounded, TmColors.yellow),
        OnlinePaymentPhase.pending || OnlinePaymentPhase.notStarted => (Icons.schedule_rounded, TmColors.yellow),
      };

  String _explanation(OnlinePaymentPhase phase, OnlinePaymentTransaction? tx) => _payAtBooking
      ? switch (phase) {
          OnlinePaymentPhase.verifying || OnlinePaymentPhase.paid =>
            'Verifying your payment with PayMongo. Your booking is submitted as soon as it is confirmed.',
          OnlinePaymentPhase.pending =>
            'We have not received your payment yet. If you already paid, give it a moment; otherwise reopen the payment page to finish.',
          OnlinePaymentPhase.failed => 'The payment did not go through. Your booking has not been submitted and you have not been charged.',
          OnlinePaymentPhase.cancelled => 'Payment cancelled. Your booking has not been submitted and you have not been charged.',
          OnlinePaymentPhase.expired => 'The payment page expired. Your booking has not been submitted and you have not been charged.',
          OnlinePaymentPhase.review =>
            'We received something that needs a quick check by our team. Please do not pay again; the dispatcher will contact you.',
          OnlinePaymentPhase.notStarted => 'No payment has been started yet.',
        }
      : switch (phase) {
        OnlinePaymentPhase.verifying => 'Checking with TowMate and the payment provider. This can take a few moments.',
        OnlinePaymentPhase.pending =>
          'We have not received a confirmation yet. If you already paid, give it a moment; otherwise reopen the payment page to finish.',
        OnlinePaymentPhase.paid => (tx?.bookingCompleted ?? false)
            ? 'Your payment was received and your booking is complete.'
            : 'Your payment was received. Your booking is completed once the dispatcher confirms it, so it may still show as in progress for a little while.',
        OnlinePaymentPhase.failed => 'The payment did not go through. You have not been charged. You can try again.',
        OnlinePaymentPhase.cancelled => 'This payment was cancelled. You have not been charged. You can start again whenever you are ready.',
        OnlinePaymentPhase.expired => 'This payment page expired before it was completed. You have not been charged. You can start again.',
        OnlinePaymentPhase.review =>
          'We received something that needs a quick check by our team. Please do not pay again; the dispatcher will contact you if anything is needed.',
        OnlinePaymentPhase.notStarted => 'No online payment has been started for this booking.',
      };

  @override
  Widget build(BuildContext context) {
    final tx = _transaction;
    final phase = _phase;
    final (icon, color) = _look(phase, context);

    return Scaffold(
      backgroundColor: context.bg,
      appBar: AppBar(
        backgroundColor: context.bg,
        elevation: 0,
        iconTheme: IconThemeData(color: context.textPrimary),
        title: Text(
          _payAtBooking ? 'Pay & Submit' : 'Online Payment',
          style: GoogleFonts.inter(color: context.textPrimary, fontSize: 16, fontWeight: FontWeight.w700),
        ),
      ),
      body: SafeArea(
        child: _unavailable
            ? _message(context, 'Online payment is not available for this booking.')
            : ListView(
                padding: const EdgeInsets.fromLTRB(24, 16, 24, 32),
                children: [
                  const TestPaymentBanner(),
                  const SizedBox(height: 16),
                  Center(
                    child: _checking && !(tx?.status.isFinal ?? false)
                        ? SizedBox(
                            width: 56,
                            height: 56,
                            child: CircularProgressIndicator(strokeWidth: 4, color: TmColors.yellow, key: const Key('payment_status_spinner')),
                          )
                        : Icon(icon, size: 64, color: color),
                  ),
                  const SizedBox(height: 16),
                  Center(
                    child: Text(
                      phase.label,
                      key: const Key('payment_status_label'),
                      style: GoogleFonts.inter(color: context.textPrimary, fontSize: 24, fontWeight: FontWeight.w800, letterSpacing: -0.4),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    _explanation(phase, tx),
                    key: const Key('payment_status_explanation'),
                    textAlign: TextAlign.center,
                    style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 14, height: 1.5),
                  ),
                  if (tx != null) ...[
                    const SizedBox(height: 24),
                    _summary(context, tx),
                  ],
                  if (_unreachable) ...[
                    const SizedBox(height: 16),
                    Text(
                      "Can't reach TowMate right now. We'll keep trying; nothing has been concluded.",
                      key: const Key('payment_status_offline'),
                      textAlign: TextAlign.center,
                      style: GoogleFonts.inter(color: TmColors.error, fontSize: 13),
                    ),
                  ],
                  const SizedBox(height: 28),
                  ..._actions(context, phase, tx),
                  const SizedBox(height: 20),
                  Text(
                    'Payment is made on PayMongo\'s secure page. TowMate never sees or stores your GCash PIN, bank login, OTP or any account details.',
                    textAlign: TextAlign.center,
                    style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 12, height: 1.5),
                  ),
                ],
              ),
      ),
    );
  }

  Widget _message(BuildContext context, String text) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Text(text, textAlign: TextAlign.center, style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 15)),
        ),
      );

  Widget _summary(BuildContext context, OnlinePaymentTransaction tx) {
    Widget row(String label, String value, {Key? key}) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(label, style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 14)),
              Text(value, key: key, style: GoogleFonts.inter(color: context.textPrimary, fontSize: 14, fontWeight: FontWeight.w700)),
            ],
          ),
        );

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: context.card,
        border: Border.all(color: context.divider),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        children: [
          row('Amount', '₱${_money.format(double.tryParse(tx.amount) ?? 0)}', key: const Key('payment_status_amount')),
          row('Method', preferredLabel(tx.method)),
          row('Booking', widget.bookingCode),
          row('Payment', tx.status.isPaid ? 'Received' : 'Not received yet'),
          if (tx.payAtBooking)
            row('Booking', tx.bookingSubmitted ? 'Submitted' : 'Not submitted yet', key: const Key('payment_status_booking_submitted'))
          else
            row('Booking status', tx.bookingCompleted ? 'Complete' : 'Awaiting dispatcher confirmation'),
        ],
      ),
    );
  }

  static String preferredLabel(String method) => switch (method) {
        'gcash' => 'GCash',
        'bank_transfer' => 'Online Banking',
        'cash' => 'Cash',
        _ => method,
      };

  List<Widget> _actions(BuildContext context, OnlinePaymentPhase phase, OnlinePaymentTransaction? tx) {
    final canReopen = (tx?.status == OnlinePaymentStatus.pending) && tx?.checkoutUrl != null;

    return [
      if (phase == OnlinePaymentPhase.pending || phase == OnlinePaymentPhase.verifying) ...[
        if (canReopen)
          // A bank link works only once, so a bank page is reopened with a fresh link from the server.
          _primary(context, 'Reopen payment page', _busy ? null : (tx?.bank != null ? _tryAgain : _reopen), const Key('payment_reopen')),
        const SizedBox(height: 10),
        _secondary(context, 'Check status', _checking ? null : () => _check(), const Key('payment_check')),
        const SizedBox(height: 10),
        _secondary(context, 'Cancel payment', _busy ? null : _cancel, const Key('payment_cancel')),
      ] else if (tx?.status.canRetry ?? false) ...[
        _primary(context, 'Try again', _busy ? null : _tryAgain, const Key('payment_try_again')),
        const SizedBox(height: 10),
        _secondary(context, _payAtBooking ? 'Back to Review' : 'Back to booking', () => Navigator.pop(context), const Key('payment_done')),
      ] else
        _primary(context, _payAtBooking ? 'Back to Review' : 'Back to booking', () => Navigator.pop(context), const Key('payment_done')),
    ];
  }

  Widget _primary(BuildContext context, String label, VoidCallback? onPressed, Key key) => SizedBox(
        height: 52,
        child: ElevatedButton(
          key: key,
          onPressed: onPressed,
          style: ElevatedButton.styleFrom(
            backgroundColor: TmColors.yellow,
            foregroundColor: TmColors.black,
            disabledBackgroundColor: TmColors.yellow.withValues(alpha: 0.5),
            elevation: 0,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
          child: Text(label, style: GoogleFonts.inter(fontSize: 15, fontWeight: FontWeight.w700)),
        ),
      );

  Widget _secondary(BuildContext context, String label, VoidCallback? onPressed, Key key) => SizedBox(
        height: 48,
        child: OutlinedButton(
          key: key,
          onPressed: onPressed,
          style: OutlinedButton.styleFrom(
            foregroundColor: context.textPrimary,
            side: BorderSide(color: context.divider),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
          child: Text(label, style: GoogleFonts.inter(fontSize: 14, fontWeight: FontWeight.w600)),
        ),
      );
}
