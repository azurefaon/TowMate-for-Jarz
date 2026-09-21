import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import '../core/theme.dart';
import '../models/quotation_model.dart';

final _pesoFmt = NumberFormat('#,##0.00', 'en_PH');
String formatPeso(double v) => '₱${_pesoFmt.format(v)}';

String vatPercentLabel(double rate) {
  final pct = double.parse((rate * 100).toStringAsFixed(2));
  return pct == pct.roundToDouble()
      ? '${pct.toStringAsFixed(0)}%'
      : '${pct.toStringAsFixed(2)}%';
}

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
    required this.subtotal,
    required this.vatAmount,
    required this.vatRate,
    required this.finalTotal,
    this.discount = 0,
    this.additionalFeeNote,
    this.priceAdjustments = const [],
    this.showFullBreakdown = false,
    this.combinedVehicleCount,
  });

  final double baseRate;
  final double distanceFee;
  final double distanceKm;
  final double subtotal;
  final double vatAmount;
  final double vatRate;
  final double finalTotal;
  final double discount;
  final String? additionalFeeNote;
  final List<QuotationAdjustment> priceAdjustments;
  final bool showFullBreakdown;
  final int? combinedVehicleCount;

  @override
  Widget build(BuildContext context) {
    final hasBase = baseRate > 0;
    final hasVat = vatAmount > 0;
    final hasBreakdown = hasBase || distanceFee > 0 || hasVat;
    final serviceTotal = subtotal + vatAmount;
    final adjustment = finalTotal - serviceTotal + discount;
    final hasItemizedAdjustments = priceAdjustments.isNotEmpty;
    final hasAdjustment = !hasItemizedAdjustments && adjustment.abs() >= 0.005;
    final hasDiscount = showFullBreakdown && discount > 0;

    if (!hasBreakdown && !hasAdjustment && !hasItemizedAdjustments) return const SizedBox.shrink();

    final chargeableKm = (distanceKm - 4).clamp(0, double.infinity);
    final distanceIncluded = hasBreakdown && chargeableKm <= 0;
    final isCombined = (combinedVehicleCount ?? 1) > 1;
    final baseLabel = isCombined ? 'Base Rate (combined · $combinedVehicleCount vehicles)' : 'Base Rate';
    final distanceLabel = isCombined
        ? 'Distance Fee (combined · ${distanceKm.toStringAsFixed(2)} km)'
        : 'Distance Fee (${distanceKm.toStringAsFixed(2)} km)';

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
                PriceLine(label: baseLabel, amount: formatPeso(baseRate)),
              if (hasBreakdown)
                PriceLine(
                  label: distanceLabel,
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
              if (showFullBreakdown && hasBreakdown)
                PriceLine(label: 'Taxable Subtotal', amount: formatPeso(subtotal)),
              if (hasVat)
                PriceLine(
                  label: 'VAT (${vatPercentLabel(vatRate)})',
                  amount: formatPeso(vatAmount),
                ),
              if (showFullBreakdown && hasBreakdown)
                PriceLine(
                  label: 'Service Total (incl. VAT)',
                  amount: formatPeso(serviceTotal),
                  highlight: true,
                ),
              if (hasDiscount)
                PriceLine(
                  label: 'Service Discount',
                  amount: '-${formatPeso(discount)}',
                ),
              if (hasItemizedAdjustments) ...[
                if (hasBreakdown) ...[
                  const SizedBox(height: 6),
                  Divider(color: context.divider, height: 1),
                  const SizedBox(height: 6),
                ],
                for (final adj in priceAdjustments)
                  PriceLine(
                    label: adj.displayLabel,
                    amount: adj.isDeduction
                        ? '-${formatPeso(adj.amount)}'
                        : formatPeso(adj.amount),
                    highlight: true,
                  ),
              ] else if (hasAdjustment) ...[
                if (hasBreakdown) ...[
                  const SizedBox(height: 6),
                  Divider(color: context.divider, height: 1),
                  const SizedBox(height: 6),
                ],
                PriceLine(
                  label: adjustment < 0 ? 'Discount' : 'Additional Fee',
                  amount: adjustment < 0
                      ? '-${formatPeso(adjustment.abs())}'
                      : formatPeso(adjustment.abs()),
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

class GroupedPriceBreakdownCard extends StatelessWidget {
  const GroupedPriceBreakdownCard({
    super.key,
    required this.vehicles,
    required this.vatRate,
    required this.finalTotal,
    this.discount = 0,
    this.priceAdjustments = const [],
  });

  final List<QuotationVehicleLine> vehicles;
  final double vatRate;
  final double finalTotal;
  final double discount;
  final List<QuotationAdjustment> priceAdjustments;

  @override
  Widget build(BuildContext context) {
    if (vehicles.isEmpty) return const SizedBox.shrink();

    final subtotalTotal =
        vehicles.fold<double>(0, (sum, v) => sum + v.baseRate + v.distanceFee);
    final vatTotal = vehicles.fold<double>(0, (sum, v) => sum + v.vatAmount);
    final serviceTotal = subtotalTotal + vatTotal;
    final adjustment = finalTotal - serviceTotal + discount;
    final hasItemizedAdjustments = priceAdjustments.isNotEmpty;
    final hasAdjustment = !hasItemizedAdjustments && adjustment.abs() >= 0.005;
    final hasDiscount = discount > 0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('PRICE BREAKDOWN', style: sectionEyebrowStyle(context)),
        const SizedBox(height: 8),
        for (int i = 0; i < vehicles.length; i++) ...[
          Container(
            padding: const EdgeInsets.all(14),
            margin: EdgeInsets.only(bottom: i == vehicles.length - 1 ? 0 : 10),
            decoration: BoxDecoration(
              color: context.card,
              border: Border.all(color: context.divider),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Vehicle ${i + 1} — ${vehicles[i].displayName}',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 0.1,
                  ),
                ),
                const SizedBox(height: 6),
                if ((vehicles[i].truckTypeName ?? '').isNotEmpty)
                  PriceLine(label: 'Towing Class', amount: vehicles[i].truckTypeName!),
                PriceLine(label: 'Base Rate', amount: formatPeso(vehicles[i].baseRate)),
                PriceLine(label: 'Distance Fee', amount: formatPeso(vehicles[i].distanceFee)),
                PriceLine(
                  label: 'Taxable Subtotal',
                  amount: formatPeso(vehicles[i].baseRate + vehicles[i].distanceFee),
                ),
                PriceLine(
                  label: 'VAT (${vatPercentLabel(vehicles[i].vatRate)})',
                  amount: formatPeso(vehicles[i].vatAmount),
                ),
                PriceLine(
                  label: 'Vehicle Service Total',
                  amount: formatPeso(vehicles[i].finalTotal),
                  highlight: true,
                ),
              ],
            ),
          ),
        ],
        const SizedBox(height: 10),
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
              PriceLine(label: 'Taxable Subtotal', amount: formatPeso(subtotalTotal)),
              PriceLine(
                label: 'VAT (${vatPercentLabel(vatRate)})',
                amount: formatPeso(vatTotal),
              ),
              PriceLine(
                label: 'Service Total (incl. VAT)',
                amount: formatPeso(serviceTotal),
              ),
              if (hasDiscount)
                PriceLine(
                  label: 'Service Discount',
                  amount: '-${formatPeso(discount)}',
                ),
              if (hasItemizedAdjustments)
                for (final adj in priceAdjustments)
                  PriceLine(
                    label: adj.displayLabel,
                    amount: adj.isDeduction
                        ? '-${formatPeso(adj.amount)}'
                        : formatPeso(adj.amount),
                  )
              else if (hasAdjustment)
                PriceLine(
                  label: adjustment < 0 ? 'Discount' : 'Quotation Adjustment',
                  amount: adjustment < 0
                      ? '-${formatPeso(adjustment.abs())}'
                      : formatPeso(adjustment.abs()),
                ),
              PriceLine(
                label: 'Final Quoted Total',
                amount: formatPeso(finalTotal),
                highlight: true,
              ),
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
    this.showVehicleInfoRow = true,
  });

  final String pickupAddress;
  final String dropoffAddress;
  final String truckTypeName;
  final double distanceKm;
  final bool showVehicleInfoRow;

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
        if (showVehicleInfoRow) ...[
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
