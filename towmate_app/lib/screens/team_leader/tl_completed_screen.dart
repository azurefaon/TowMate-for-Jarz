import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';

class TlCompletedScreen extends StatelessWidget {
  const TlCompletedScreen({super.key, required this.task});
  final TaskModel task;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            const SizedBox(height: 32),
            Container(
              width: 80,
              height: 80,
              decoration: BoxDecoration(
                color: TmColors.success.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.check_rounded,
                  color: TmColors.success, size: 44),
            ),
            const SizedBox(height: 20),
            Text('Task Completed',
                style: GoogleFonts.inter(
                    color: TmColors.black, fontSize: 22, letterSpacing: -0.5)),
            const SizedBox(height: 6),
            Text('The towing service has been completed and verified.',
                textAlign: TextAlign.center,
                style: GoogleFonts.inter(
                    color: TmColors.grey500, fontSize: 13)),
            const SizedBox(height: 32),
            _summaryCard(),
            const SizedBox(height: 36),
            SizedBox(
              width: double.infinity,
              height: 52,
              child: ElevatedButton(
                onPressed: () =>
                    Navigator.pushReplacementNamed(context, '/tl-home'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: TmColors.yellow,
                  foregroundColor: TmColors.black,
                  shape: const StadiumBorder(),
                  elevation: 0,
                ),
                child: Text('Back to Home',
                    style: GoogleFonts.inter(
                        color: TmColors.black, fontSize: 15)),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _summaryCard() {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: TmColors.white,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.05),
            blurRadius: 12,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _row('Booking', task.bookingCode),
          _divider(),
          _row('Customer', task.customerName),
          _divider(),
          _row('Pickup', task.pickupAddress),
          _divider(),
          _row('Drop-off', task.dropoffAddress),
          _divider(),
          _row('Distance', '${task.distanceKm.toStringAsFixed(1)} km'),
        ],
      ),
    );
  }

  Widget _row(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 80,
            child: Text(label,
                style: GoogleFonts.inter(
                    color: TmColors.grey500, fontSize: 12)),
          ),
          Expanded(
            child: Text(value,
                style: GoogleFonts.inter(
                    color: TmColors.black, fontSize: 13),
                maxLines: 2,
                overflow: TextOverflow.ellipsis),
          ),
        ],
      ),
    );
  }

  Widget _divider() =>
      const Divider(height: 1, color: TmColors.grey300);
}
