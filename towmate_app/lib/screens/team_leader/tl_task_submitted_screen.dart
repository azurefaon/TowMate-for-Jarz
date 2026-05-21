import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';

class TlTaskSubmittedScreen extends StatelessWidget {
  const TlTaskSubmittedScreen({super.key, required this.task});
  final TaskModel task;

  String _paymentLabel(String? method) => switch (method) {
        'gcash' => 'GCash',
        'bank_transfer' => 'Bank Transfer',
        'cash' => 'Cash',
        _ => method ?? 'Cash',
      };

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SizedBox(height: 16),
            Container(
              padding: const EdgeInsets.all(28),
              decoration: BoxDecoration(
                color: TmColors.yellow.withValues(alpha: 0.08),
                shape: BoxShape.circle,
              ),
              child: const Icon(
                Icons.hourglass_top_rounded,
                color: TmColors.yellow,
                size: 52,
              ),
            ),
            const SizedBox(height: 24),
            Text(
              'Payment Collected',
              style: GoogleFonts.inter(
                color: TmColors.black,
                fontSize: 20,
                letterSpacing: -0.4,
              ),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              'Waiting for dispatcher to confirm and close the job.',
              style: GoogleFonts.inter(
                color: TmColors.grey500,
                fontSize: 13,
                height: 1.5,
              ),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 32),
            _summaryRow(
              Icons.bookmark_rounded,
              'Booking',
              task.bookingCode,
            ),
            _summaryRow(
              Icons.payments_rounded,
              'Payment Method',
              _paymentLabel(task.paymentMethod),
            ),
            _summaryRow(
              Icons.attach_money_rounded,
              'Total Collected',
              '₱${task.finalTotal.toStringAsFixed(2)}',
            ),
            const SizedBox(height: 28),
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: TmColors.grey100,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: TmColors.grey300),
              ),
              child: Row(
                children: [
                  const Icon(Icons.info_outline_rounded,
                      color: TmColors.grey500, size: 18),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'The dispatcher will finalize the job and send the receipt to the customer.',
                      style: GoogleFonts.inter(
                        color: TmColors.grey500,
                        fontSize: 12,
                        height: 1.45,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _summaryRow(IconData icon, String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              color: TmColors.grey100,
              borderRadius: BorderRadius.circular(8),
            ),
            child: Icon(icon, color: TmColors.grey700, size: 18),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: GoogleFonts.inter(
                      color: TmColors.grey500, fontSize: 11),
                ),
                Text(
                  value,
                  style: GoogleFonts.inter(
                      color: TmColors.black,
                      fontSize: 14,
                      letterSpacing: -0.2),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
