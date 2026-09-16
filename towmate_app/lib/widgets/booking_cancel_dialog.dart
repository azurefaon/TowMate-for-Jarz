import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';
import '../models/booking_model.dart';
import 'quotation_price_cards.dart';

Future<bool?> showCancelBookingDialog(BuildContext context, BookingModel booking) {
  final serviceLabel = booking.serviceType == 'schedule' ? 'Scheduled' : 'Book Now';
  return showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      backgroundColor: ctx.card,
      title: Text(
        'Cancel this booking?',
        style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 16, fontWeight: FontWeight.w600, letterSpacing: -0.2),
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            booking.bookingCode,
            style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14, fontWeight: FontWeight.w700, letterSpacing: 0.1),
          ),
          const SizedBox(height: 2),
          Text(
            '${booking.displayVehicleName} · $serviceLabel',
            style: GoogleFonts.inter(color: secondaryTextColor(ctx), fontSize: 13, letterSpacing: 0.1),
          ),
          if (booking.isGrouped) ...[
            const SizedBox(height: 14),
            Text(
              'This will cancel only this vehicle booking. Other vehicles in this request will not be cancelled.',
              style: GoogleFonts.inter(color: secondaryTextColor(ctx), fontSize: 13, height: 1.5),
            ),
          ],
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(ctx, false),
          child: Text('Keep Booking', style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14, fontWeight: FontWeight.w500)),
        ),
        TextButton(
          onPressed: () => Navigator.pop(ctx, true),
          style: TextButton.styleFrom(
            backgroundColor: TmColors.destructive,
            foregroundColor: TmColors.white,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          ),
          child: Text('Cancel Booking', style: GoogleFonts.inter(fontSize: 14, fontWeight: FontWeight.w600)),
        ),
      ],
    ),
  );
}
