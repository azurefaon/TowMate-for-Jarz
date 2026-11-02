import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/booking_model.dart';
import '../../widgets/online_payment_card.dart' show TestPaymentBanner;
import '../../widgets/payment_method_selector.dart';
import '../../widgets/quotation_price_cards.dart';

class BookingSuccessScreen extends StatelessWidget {
  const BookingSuccessScreen({super.key, required this.bookings, this.paidOnline = false});
  final List<BookingGroupSibling> bookings;

  /// Pay & Submit: shown only after the server verified the payment AND submitted the booking.
  final bool paidOnline;

  @override
  Widget build(BuildContext context) {
    final isMulti = bookings.length > 1;

    return PopScope(
      canPop: false,
      child: Scaffold(
        backgroundColor: context.bg,
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(24, 32, 24, 24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 64,
                  height: 64,
                  decoration: const BoxDecoration(color: TmColors.yellow, shape: BoxShape.circle),
                  child: const Icon(Icons.check_rounded, color: TmColors.black, size: 34),
                ),
                const SizedBox(height: 20),
                Text(
                  'Booking Successful',
                  key: const Key('booking_success_title'),
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 24,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -0.5,
                    height: 1.2,
                  ),
                ),
                if (isMulti) ...[
                  const SizedBox(height: 6),
                  Text(
                    '${bookings.length} vehicles in this request',
                    style: GoogleFonts.inter(
                      color: secondaryTextColor(context),
                      fontSize: 13.5,
                      letterSpacing: 0.1,
                    ),
                  ),
                ],
                const SizedBox(height: 14),
                if (paidOnline) ...[
                  const TestPaymentBanner(),
                  const SizedBox(height: 12),
                ],
                Text(
                  paidOnline
                      ? 'Payment received and your booking is submitted. Nothing will be '
                          'collected after the tow. We will notify you when a team is assigned.'
                      : 'Your booking is submitted. Pay the Team Leader in cash after the tow. '
                          'This is not your final price yet: TowMate will review your request '
                          'and send you a quotation before the booking is confirmed.',
                  key: const Key('booking_success_message'),
                  style: GoogleFonts.inter(
                    color: secondaryTextColor(context),
                    fontSize: 13.5,
                    letterSpacing: 0.1,
                    height: 1.5,
                  ),
                ),
                const SizedBox(height: 24),
                Expanded(
                  child: SingleChildScrollView(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        for (int i = 0; i < bookings.length; i++) ...[
                          if (i > 0) const SizedBox(height: 12),
                          _SubmittedBookingCard(booking: bookings[i], paidOnline: paidOnline),
                        ],
                        if (isMulti) ...[
                          const SizedBox(height: 16),
                          Text(
                            'Each vehicle may be dispatched, scheduled, and billed separately.',
                            style: GoogleFonts.inter(
                              color: secondaryTextColor(context),
                              fontSize: 12.5,
                              height: 1.5,
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                SizedBox(
                  width: double.infinity,
                  height: 52,
                  child: ElevatedButton(
                    onPressed: () => Navigator.pushNamedAndRemoveUntil(context, '/my-bookings', (_) => false),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: TmColors.yellow,
                      foregroundColor: TmColors.black,
                      elevation: 0,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                    ),
                    child: Text('View My Bookings', style: GoogleFonts.inter(fontSize: 15, fontWeight: FontWeight.w600)),
                  ),
                ),
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  height: 52,
                  child: OutlinedButton(
                    onPressed: () => Navigator.pushNamedAndRemoveUntil(context, '/home', (_) => false),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: context.textPrimary,
                      side: BorderSide(color: context.divider),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                    ),
                    child: Text('Back to Home', style: GoogleFonts.inter(fontSize: 15, fontWeight: FontWeight.w600)),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _SubmittedBookingCard extends StatelessWidget {
  const _SubmittedBookingCard({required this.booking, this.paidOnline = false});
  final BookingGroupSibling booking;
  final bool paidOnline;

  @override
  Widget build(BuildContext context) {
    final isScheduled = booking.serviceType == 'schedule';
    final subtitle = isScheduled && booking.scheduledLabel.isNotEmpty
        ? booking.scheduledLabel
        : booking.humanStatus;

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        border: Border.all(color: context.divider),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            booking.bookingCode,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 13,
              fontWeight: FontWeight.w600,
              letterSpacing: 0.1,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            booking.displayVehicleName,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 16,
              fontWeight: FontWeight.w700,
              letterSpacing: -0.2,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            isScheduled ? 'Scheduled' : 'Book Now',
            style: GoogleFonts.inter(
              color: secondaryTextColor(context),
              fontSize: 12.5,
              fontWeight: FontWeight.w500,
              letterSpacing: 0.1,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            subtitle,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 12.5,
              letterSpacing: 0.1,
            ),
          ),
          if (preferredPaymentLabel(booking.preferredPaymentMethod) != null) ...[
            const SizedBox(height: 8),
            Text(
              paidOnline
                  ? 'Paid online: ${preferredPaymentLabel(booking.preferredPaymentMethod)} (TEST / DEMO)'
                  : 'Preferred payment: ${preferredPaymentLabel(booking.preferredPaymentMethod)} · not paid yet',
              key: const Key('success_preferred_payment'),
              style: GoogleFonts.inter(
                color: secondaryTextColor(context),
                fontSize: 12.5,
                letterSpacing: 0.1,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
