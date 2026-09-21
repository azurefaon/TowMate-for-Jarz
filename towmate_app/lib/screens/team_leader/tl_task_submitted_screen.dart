import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../widgets/quotation_price_cards.dart' show formatPeso;

class TlTaskSubmittedScreen extends StatelessWidget {
  const TlTaskSubmittedScreen({super.key, required this.task});
  final TaskModel task;

  String _paymentLabel(String? method) => switch (method) {
        'gcash' => 'GCash',
        'bank_transfer' => 'Bank Transfer',
        'cash' => 'Cash',
        _ => method ?? 'Cash',
      };

  double get _amountCollected =>
      task.isGroupBooking && task.groupTotal != null
          ? task.groupTotal!
          : task.finalTotal;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      height: double.infinity,
      color: context.bg,
      child: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 28),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _stepHeader(context),
              const SizedBox(height: 18),
              _statusCard(context),
              const SizedBox(height: 14),
              _summaryCard(context),
              const SizedBox(height: 14),
              _infoBox(context),
            ],
          ),
        ),
      ),
    );
  }

  Widget _stepHeader(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Pending Payment',
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 26,
            fontWeight: FontWeight.w800,
            letterSpacing: -0.5,
            height: 1.15,
          ),
        ),
        const SizedBox(height: 14),
        Row(
          children: [
            Text(
              'Step 5 of 6',
              style: GoogleFonts.inter(
                color: context.textTertiary,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
            const Spacer(),
            Text(
              '5 / 6',
              style: GoogleFonts.inter(
                color: context.textTertiary,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: 5 / 6,
            minHeight: 6,
            backgroundColor: context.divider,
            valueColor: const AlwaysStoppedAnimation(TmColors.yellow),
          ),
        ),
      ],
    );
  }

  BoxDecoration _cardDecoration(BuildContext context) {
    return BoxDecoration(
      color: context.card,
      borderRadius: BorderRadius.circular(23),
      border: Border.all(color: context.divider),
      boxShadow: [
        BoxShadow(
          color: Colors.black.withValues(alpha: context.isDark ? 0.24 : 0.06),
          blurRadius: 20,
          offset: const Offset(0, 8),
        ),
      ],
    );
  }

  Widget _statusCard(BuildContext context) {
    final submittedLine = task.isGroupBooking
        ? 'Consolidated payment for ${task.groupVehicleCount} vehicles has been submitted.'
        : 'Payment has been submitted.';
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
      decoration: _cardDecoration(context),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(
                  color: TmColors.success.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: const Icon(Icons.check_rounded, color: TmColors.success, size: 20),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'Payment Collected',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 17,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -0.3,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            submittedLine,
            style: GoogleFonts.inter(
              color: context.textSecondary,
              fontSize: 13,
              height: 1.5,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'Waiting for dispatcher confirmation to close the job.',
            style: GoogleFonts.inter(
              color: context.textSecondary,
              fontSize: 13,
              fontWeight: FontWeight.w600,
              height: 1.5,
            ),
          ),
        ],
      ),
    );
  }

  Widget _summaryCard(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
      decoration: _cardDecoration(context),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Payment Summary',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 16,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 14),
          _summaryRow(context, 'Booking', task.bookingCode),
          if (task.isGroupBooking) ...[
            const SizedBox(height: 10),
            _summaryRow(context, 'Vehicles', '${task.groupVehicleCount}'),
          ],
          const SizedBox(height: 10),
          _summaryRow(context, 'Payment Method', _paymentLabel(task.paymentMethod)),
          const SizedBox(height: 12),
          Divider(height: 1, thickness: 1, color: context.divider),
          const SizedBox(height: 12),
          Text(
            'Total Collected',
            style: GoogleFonts.inter(
              color: context.textTertiary,
              fontSize: 13,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            formatPeso(_amountCollected),
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 26,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.5,
            ),
          ),
        ],
      ),
    );
  }

  Widget _summaryRow(BuildContext context, String label, String value) {
    return Row(
      children: [
        Expanded(
          child: Text(
            label,
            style: GoogleFonts.inter(
              color: context.textTertiary,
              fontSize: 13,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
        const SizedBox(width: 10),
        Flexible(
          child: Text(
            value,
            textAlign: TextAlign.right,
            overflow: TextOverflow.ellipsis,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 14,
              fontWeight: FontWeight.w700,
            ),
          ),
        ),
      ],
    );
  }

  Widget _infoBox(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: context.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: context.divider),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.info_outline_rounded, color: context.textTertiary, size: 18),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              'The dispatcher will confirm the payment and finalize the job. The customer will receive the receipt after confirmation.',
              style: GoogleFonts.inter(
                color: context.textSecondary,
                fontSize: 12,
                height: 1.45,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
