import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/booking_model.dart';
import '../../widgets/quotation_price_cards.dart';

class BookingSuccessScreen extends StatelessWidget {
  const BookingSuccessScreen({super.key, required this.bookings});
  final List<BookingGroupSibling> bookings;

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
                  'Booking Request Submitted',
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
                Text(
                  'This is not your final price yet. TowMate will review your '
                  'request and send you a quotation — please wait for that '
                  'review before the booking is confirmed.',
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
                          _SubmittedBookingCard(booking: bookings[i]),
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
  const _SubmittedBookingCard({required this.booking});
  final BookingGroupSibling booking;

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
        ],
      ),
    );
  }
}
