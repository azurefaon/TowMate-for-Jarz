import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';
import '../models/online_payment_model.dart';
import 'quotation_price_cards.dart';

/// How the customer pays, stored as bookings.preferred_payment_method. Backend identifiers on the left;
/// `bank_transfer` is shown as "Online Banking".
///  - Cash: submitted at once; the Team Leader collects cash after the tow.
///  - GCash: "Pay & Submit" on PayMongo's TEST checkout; the booking is submitted after the verified payment.
///  - Online Banking: "Pay & Submit" on the chosen bank's PayMongo TEST page, like GCash, when the server offers banks.
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
    this.onlinePayAvailable = false,
    this.onlineBankingAvailable = false,
    this.onlineUnavailableReason,
    this.banks = const [],
    this.selectedBank,
    this.onBankChanged,
  });

  final String? value;
  final ValueChanged<String> onChanged;
  final bool enabled;
  final bool showRequiredError;

  /// Server-decided: may this customer pay with GCash now (PayMongo TEST mode, Book Now only)?
  final bool onlinePayAvailable;

  /// Server-decided: Online Banking is switched on and has banks to offer.
  final bool onlineBankingAvailable;

  /// Shown under GCash when it cannot be used (e.g. scheduled bookings).
  final String? onlineUnavailableReason;

  /// The banks shown under Online Banking while it is selected.
  final List<OnlineBank> banks;
  final String? selectedBank;
  final ValueChanged<String>? onBankChanged;

  bool _available(String method) => switch (method) {
        'cash' => true,
        'gcash' => onlinePayAvailable,
        _ => onlineBankingAvailable,
      };

  String? _note(String method) => switch (method) {
        'gcash' when !onlinePayAvailable => onlineUnavailableReason ?? 'Online payment is not available for your account',
        'bank_transfer' when !onlineBankingAvailable => 'Not available yet',
        'gcash' || 'bank_transfer' => 'Pay now · TEST MODE, no real money',
        _ => 'Pay the Team Leader after the tow',
      };

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
            note: _note(option.value),
            selected: value == option.value,
            onTap: enabled && _available(option.value) ? () => onChanged(option.value) : null,
          ),
          const SizedBox(height: 8),
          if (option.value == 'bank_transfer' && value == 'bank_transfer')
            for (final bank in banks) ...[
              Padding(
                padding: const EdgeInsets.only(left: 24),
                child: _PaymentOptionTile(
                  key: Key('bank_option_${bank.code}'),
                  label: bank.label,
                  selected: selectedBank == bank.code,
                  onTap: enabled ? () => onBankChanged?.call(bank.code) : null,
                ),
              ),
              const SizedBox(height: 8),
            ],
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
          value == 'gcash' || value == 'bank_transfer'
              ? 'You pay now on PayMongo\'s secure page (TEST MODE — no real money). '
                  'Your booking is submitted only after the payment is verified.'
              : 'Cash: your booking is submitted now and you pay the Team Leader '
                  'after the tow. You will not be asked for a GCash number, bank '
                  'account, or card details.',
          key: const Key('payment_method_help'),
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
    this.note,
  });

  final String label;
  final bool selected;
  final VoidCallback? onTap;
  final String? note;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: selected,
      enabled: onTap != null,
      label: label,
      child: Opacity(
        opacity: onTap == null && !selected ? 0.55 : 1,
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
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      label,
                      style: GoogleFonts.inter(
                        color: context.textPrimary,
                        fontSize: 14.5,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    if (note != null)
                      Text(
                        note!,
                        style: GoogleFonts.inter(
                          color: secondaryTextColor(context),
                          fontSize: 12,
                        ),
                      ),
                  ],
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
