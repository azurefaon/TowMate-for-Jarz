import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';
import '../models/booking_model.dart';

/// Who cancelled or rejected a booking and the reason on record. Renders
/// nothing for bookings that were not cancelled or rejected.
class ClosureNote extends StatelessWidget {
  const ClosureNote({super.key, required this.booking});

  final BookingModel booking;

  @override
  Widget build(BuildContext context) {
    final headline = booking.closureHeadline;
    final reason = booking.closureReasonLine;
    if (reason == null) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (headline != null) ...[
          Text(
            headline,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 13.5,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 2),
        ],
        Text(
          reason,
          style: GoogleFonts.inter(
            color: context.textSecondary,
            fontSize: 13.5,
            height: 1.4,
          ),
        ),
      ],
    );
  }
}
