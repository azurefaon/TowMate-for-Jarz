import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';
import 'quotation_price_cards.dart';

/// The customer's payment *preference*, stored as bookings.preferred_payment_method.
/// Backend identifiers on the left; `bank_transfer` is shown as "Online Banking".
/// Choosing one never takes a payment and never asks for account details.
const List<({String value, String label})> kPreferredPaymentMethods = [
  (value: 'cash', label: 'Cash'),
  (value: 'gcash', label: 'GCash'),
  (value: 'bank_transfer', label: 'Online Banking'),
];

String? preferredPaymentLabel(String? method) {
  for (final option in kPreferredPaymentMethods) {
    if (option.value == method) return option.label;
  }
  return null;
}

class PaymentMethodSelector extends StatelessWidget {
  const PaymentMethodSelector({
    super.key,
    required this.value,
    required this.onChanged,
    this.enabled = true,
    this.showRequiredError = false,
  });

  final String? value;
  final ValueChanged<String> onChanged;
  final bool enabled;
  final bool showRequiredError;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('PAYMENT METHOD', style: sectionEyebrowStyle(context)),
        const SizedBox(height: 12),
        for (final option in kPreferredPaymentMethods) ...[
          _PaymentOptionTile(
            key: Key('payment_option_${option.value}'),
            label: option.label,
            selected: value == option.value,
            onTap: enabled ? () => onChanged(option.value) : null,
          ),
          const SizedBox(height: 8),
        ],
        if (showRequiredError && value == null)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Text(
              'Please choose a payment method.',
              style: GoogleFonts.inter(
                color: TmColors.destructive,
                fontSize: 12.5,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        Text(
          'This only records how you plan to pay. Nothing is charged now — '
          'you pay after the service is completed. You will not be asked for '
          'a GCash number, bank account, or card details.',
          style: GoogleFonts.inter(
            color: secondaryTextColor(context),
            fontSize: 12.5,
            letterSpacing: 0.1,
            height: 1.5,
          ),
        ),
      ],
    );
  }
}

class _PaymentOptionTile extends StatelessWidget {
  const _PaymentOptionTile({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: selected,
      label: label,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(10),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          decoration: BoxDecoration(
            color: selected ? TmColors.yellow.withValues(alpha: 0.14) : null,
            border: Border.all(
              color: selected ? TmColors.yellow : context.divider,
              width: selected ? 1.5 : 1,
            ),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Row(
            children: [
              Icon(
                selected
                    ? Icons.radio_button_checked_rounded
                    : Icons.radio_button_unchecked_rounded,
                color: selected ? context.textPrimary : context.textTertiary,
                size: 20,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  label,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 14.5,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
