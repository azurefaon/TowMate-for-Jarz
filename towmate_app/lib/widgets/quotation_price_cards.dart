import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import '../core/theme.dart';

final _pesoFmt = NumberFormat('#,##0.00', 'en_PH');
String formatPeso(double v) => '₱${_pesoFmt.format(v)}';

Color secondaryTextColor(BuildContext context) =>
    context.isDark ? TmColors.grey500 : const Color(0xFF6B6B6B);

TextStyle sectionEyebrowStyle(BuildContext context) => GoogleFonts.inter(
      color: secondaryTextColor(context),
      fontSize: 11,
      fontWeight: FontWeight.w700,
      letterSpacing: 0.8,
    );

class PriceBreakdownCard extends StatelessWidget {
  const PriceBreakdownCard({
    super.key,
    required this.baseRate,
    required this.distanceFee,
    required this.distanceKm,
    required this.vatAmount,
    this.additionalFee = 0,
    this.additionalFeeNote,
  });

  final double baseRate;
  final double distanceFee;
  final double distanceKm;
  final double vatAmount;
  final double additionalFee;
  final String? additionalFeeNote;

  @override
  Widget build(BuildContext context) {
    final hasBase = baseRate > 0;
    final hasVat = vatAmount > 0;
    final hasAdditional = additionalFee != 0;
    final hasBreakdown = hasBase || distanceFee > 0 || hasVat;

    if (!hasBreakdown && !hasAdditional) return const SizedBox.shrink();

    final chargeableKm = (distanceKm - 4).clamp(0, double.infinity);
    final distanceIncluded = hasBreakdown && chargeableKm <= 0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('PRICE BREAKDOWN', style: sectionEyebrowStyle(context)),
        const SizedBox(height: 8),
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: context.card,
            border: Border.all(color: context.divider),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (hasBase)
                PriceLine(label: 'Base Rate', amount: formatPeso(baseRate)),
              if (hasBreakdown)
                PriceLine(
                  label: 'Distance Fee (${distanceKm.toStringAsFixed(2)} km)',
                  amount: formatPeso(distanceFee),
                ),
              if (distanceIncluded)
                Padding(
                  padding: const EdgeInsets.only(bottom: 2),
                  child: Text(
                    'First 4 km included.',
                    style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 11.5),
                  ),
                ),
              if (hasVat)
                PriceLine(label: 'VAT (12%)', amount: formatPeso(vatAmount)),
              if (hasAdditional) ...[
                if (hasBreakdown) ...[
                  const SizedBox(height: 6),
                  Divider(color: context.divider, height: 1),
                  const SizedBox(height: 6),
                ],
                PriceLine(
                  label: 'Additional Fee',
                  amount: formatPeso(additionalFee),
                  highlight: true,
                ),
                if (additionalFeeNote != null && additionalFeeNote!.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 3, left: 4),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('↳ ',
                            style: GoogleFonts.inter(
                                color: secondaryTextColor(context), fontSize: 11)),
                        Expanded(
                          child: Text(
                            additionalFeeNote!,
                            style: GoogleFonts.inter(
                              color: secondaryTextColor(context),
                              fontSize: 12,
                              height: 1.4,
                            ),
                          ),
                        ),
                      ],
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

class PriceLine extends StatelessWidget {
  const PriceLine({
    super.key,
    required this.label,
    required this.amount,
    this.highlight = false,
  });
  final String label;
  final String amount;
  final bool highlight;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Text(
              label,
              style: GoogleFonts.inter(
                color: secondaryTextColor(context),
                fontSize: 13,
                fontWeight: highlight ? FontWeight.w600 : FontWeight.w400,
                letterSpacing: 0.1,
              ),
            ),
          ),
          const SizedBox(width: 10),
          Text(
            amount,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 13,
              fontWeight: highlight ? FontWeight.w600 : FontWeight.w500,
              letterSpacing: 0.1,
            ),
          ),
        ],
      ),
    );
  }
}

class TripDetailsSection extends StatelessWidget {
  const TripDetailsSection({
    super.key,
    required this.pickupAddress,
    required this.dropoffAddress,
    required this.truckTypeName,
    required this.distanceKm,
  });

  final String pickupAddress;
  final String dropoffAddress;
  final String truckTypeName;
  final double distanceKm;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('TRIP DETAILS', style: sectionEyebrowStyle(context)),
        const SizedBox(height: 8),
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: context.card,
            border: Border.all(color: context.divider),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Column(
            children: [
              TripAddressRow(
                icon: Icons.arrow_upward_rounded,
                iconColor: TmColors.success,
                label: 'Pickup',
                address: pickupAddress,
              ),
              Padding(
                padding: const EdgeInsets.only(left: 37, top: 2, bottom: 2),
                child: Divider(color: context.divider, height: 1),
              ),
              TripAddressRow(
                icon: Icons.location_on_rounded,
                iconColor: TmColors.error,
                label: 'Dropoff',
                address: dropoffAddress,
              ),
            ],
          ),
        ),
        const SizedBox(height: 10),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          decoration: BoxDecoration(
            border: Border.all(color: context.divider),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(
            children: [
              Expanded(
                child: Row(
                  children: [
                    Icon(Icons.local_shipping_outlined,
                        size: 16, color: secondaryTextColor(context)),
                    const SizedBox(width: 7),
                    Expanded(
                      child: Text(
                        truckTypeName,
                        style: GoogleFonts.inter(
                            color: context.textPrimary, fontSize: 12.5),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ],
                ),
              ),
              Container(
                width: 1,
                height: 18,
                color: context.divider,
                margin: const EdgeInsets.symmetric(horizontal: 12),
              ),
              Row(
                children: [
                  Icon(Icons.route_outlined,
                      size: 16, color: secondaryTextColor(context)),
                  const SizedBox(width: 7),
                  Text(
                    '${distanceKm.toStringAsFixed(1)} km',
                    style: GoogleFonts.inter(
                        color: context.textPrimary, fontSize: 12.5),
                  ),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class TripAddressRow extends StatelessWidget {
  const TripAddressRow({
    super.key,
    required this.icon,
    required this.iconColor,
    required this.label,
    required this.address,
  });
  final IconData icon;
  final Color iconColor;
  final String label;
  final String address;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 26,
            height: 26,
            margin: const EdgeInsets.only(top: 1),
            decoration: BoxDecoration(color: iconColor, shape: BoxShape.circle),
            child: Icon(icon, size: 13, color: TmColors.white),
          ),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: GoogleFonts.inter(
                    color: secondaryTextColor(context),
                    fontSize: 12,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                const SizedBox(height: 1),
                Text(
                  address,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 12,
                    height: 1.4,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
