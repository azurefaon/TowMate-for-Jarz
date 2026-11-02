import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';

import '../core/theme.dart';
import '../models/online_payment_model.dart';
import '../screens/customer/online_payment_status_screen.dart';
import '../services/api_service.dart';
import '../services/checkout_launcher.dart';
import 'quotation_price_cards.dart';

/// "Pay online" on the booking detail. Shown only when the backend offers it (an eligible booking, or one that
/// already has an online payment to report). The amount and eligibility come from the server; this widget only
/// displays them, starts a checkout, and hands the customer to the status screen, which reads the verified state.
class OnlinePaymentCard extends StatefulWidget {
  const OnlinePaymentCard({
    super.key,
    required this.bookingCode,
    required this.offer,
    required this.onChanged,
    this.launcher = const UrlLauncherCheckoutLauncher(),
  });

  final String bookingCode;
  final OnlinePaymentOffer offer;

  /// Called after the customer returns from the payment status screen, so the booking can be refreshed.
  final VoidCallback onChanged;
  final CheckoutLauncher launcher;

  @override
  State<OnlinePaymentCard> createState() => _OnlinePaymentCardState();
}

class _OnlinePaymentCardState extends State<OnlinePaymentCard> {
  static final _money = NumberFormat('#,##0.00', 'en_PH');
  bool _busy = false;

  Future<void> _payNow() async {
    if (_busy) return;
    setState(() => _busy = true);

    // The server decides eligibility and the amount; only the channel is sent.
    final started = await ApiService.startOnlineCheckout(widget.bookingCode);
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
      return;
    }

    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => OnlinePaymentStatusScreen(bookingCode: widget.bookingCode, launcher: widget.launcher),
      ),
    );
    if (mounted) widget.onChanged();
  }

  Future<void> _openStatus() async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => OnlinePaymentStatusScreen(bookingCode: widget.bookingCode, launcher: widget.launcher),
      ),
    );
    if (mounted) widget.onChanged();
  }

  void _snack(String message) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));

  @override
  Widget build(BuildContext context) {
    final offer = widget.offer;
    final transaction = offer.transaction;
    final phase = OnlinePaymentPhase.of(transaction, checking: false);
    final resuming = transaction?.status == OnlinePaymentStatus.pending;

    return Column(
      key: const Key('online_payment_card'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('PAY ONLINE', style: sectionEyebrowStyle(context)),
        const SizedBox(height: 8),
        const TestPaymentBanner(),
        const SizedBox(height: 8),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: context.card,
            border: Border.all(color: context.divider),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                offer.isGroup ? 'Total for all vehicles' : 'Final amount',
                style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 13),
              ),
              const SizedBox(height: 2),
              Text(
                '₱${_money.format(double.tryParse(offer.amount) ?? 0)}',
                key: const Key('online_payment_amount'),
                style: GoogleFonts.inter(color: context.textPrimary, fontSize: 28, fontWeight: FontWeight.w800, letterSpacing: -0.6),
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  Text('Pay with  ', style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 13)),
                  Text(
                    offer.methodLabel,
                    key: const Key('online_payment_method'),
                    style: GoogleFonts.inter(color: context.textPrimary, fontSize: 14, fontWeight: FontWeight.w700),
                  ),
                ],
              ),
              if (transaction != null) ...[
                const SizedBox(height: 10),
                Text(
                  phase.label,
                  key: const Key('online_payment_phase'),
                  style: GoogleFonts.inter(
                    color: transaction.status.isPaid ? TmColors.success : context.textPrimary,
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                if (transaction.status.isPaid) ...[
                  const SizedBox(height: 4),
                  Text(
                    'Your payment was received. Your booking is completed once the dispatcher confirms it.',
                    style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 12.5, height: 1.45),
                  ),
                ],
              ],
              if (offer.eligible) ...[
                const SizedBox(height: 14),
                SizedBox(
                  width: double.infinity,
                  height: 52,
                  child: ElevatedButton(
                    key: const Key('online_payment_pay_now'),
                    onPressed: _busy ? null : _payNow,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: TmColors.yellow,
                      foregroundColor: TmColors.black,
                      disabledBackgroundColor: TmColors.yellow.withValues(alpha: 0.5),
                      elevation: 0,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                    ),
                    child: _busy
                        ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: TmColors.black))
                        : Text(resuming ? 'Continue Payment' : 'Pay Now', style: GoogleFonts.inter(fontSize: 15, fontWeight: FontWeight.w700)),
                  ),
                ),
                const SizedBox(height: 10),
                Text(
                  'You will pay on PayMongo\'s secure page. TowMate never sees your GCash PIN or OTP.',
                  style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 12, height: 1.45),
                ),
              ] else if (transaction != null) ...[
                const SizedBox(height: 12),
                SizedBox(
                  width: double.infinity,
                  height: 46,
                  child: OutlinedButton(
                    key: const Key('online_payment_view_status'),
                    onPressed: _openStatus,
                    style: OutlinedButton.styleFrom(
                      foregroundColor: context.textPrimary,
                      side: BorderSide(color: context.divider),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                    ),
                    child: Text('View payment status', style: GoogleFonts.inter(fontSize: 14, fontWeight: FontWeight.w600)),
                  ),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

/// Online payment in TowMate is a PayMongo TEST MODE demonstration. Shown wherever it can be started or reviewed.
class TestPaymentBanner extends StatelessWidget {
  const TestPaymentBanner({super.key});

  static const text = 'TEST PAYMENT — No real money will be charged.';

  @override
  Widget build(BuildContext context) {
    return Container(
      key: const Key('test_payment_banner'),
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: TmColors.yellow.withValues(alpha: 0.16),
        border: Border.all(color: TmColors.yellow),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Icon(Icons.science_outlined, size: 18, color: context.textPrimary),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: GoogleFonts.inter(color: context.textPrimary, fontSize: 13, fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
    );
  }
}
