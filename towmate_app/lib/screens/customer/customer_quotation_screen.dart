import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import '../../core/theme.dart';
import '../../models/quotation_model.dart';
import '../../services/api_service.dart';

class CustomerQuotationScreen extends StatefulWidget {
  const CustomerQuotationScreen({super.key});

  @override
  State<CustomerQuotationScreen> createState() => _CustomerQuotationScreenState();
}

class _CustomerQuotationScreenState extends State<CustomerQuotationScreen> {
  bool _accepting = false;
  bool _declining = false;
  bool _sendingInquiry = false;

  QuotationModel? get _quotationOrNull =>
      ModalRoute.of(context)?.settings.arguments as QuotationModel?;

  QuotationModel get _quotation => _quotationOrNull!;

  Future<void> _accept() async {
    setState(() => _accepting = true);
    final result = await ApiService.acceptQuotation(_quotation.id);
    if (!mounted) return;
    if (result['success'] == true) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Booking confirmed!',
            style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
          ),
          backgroundColor: TmColors.black,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          margin: const EdgeInsets.all(16),
        ),
      );
      Navigator.pop(context);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            result['message'] as String? ?? 'Failed to accept. Please try again.',
            style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
          ),
          backgroundColor: TmColors.black,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          margin: const EdgeInsets.all(16),
        ),
      );
      setState(() => _accepting = false);
    }
  }

  Future<void> _decline() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: ctx.card,
        title: Text(
          'Decline this quotation?',
          style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 16, letterSpacing: -0.2),
        ),
        content: Text(
          'This will cancel the current quotation. You can submit a new booking if needed.',
          style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 13, height: 1.5),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text('Cancel', style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 14)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text('Decline', style: GoogleFonts.inter(color: TmColors.error, fontSize: 14)),
          ),
        ],
      ),
    );
    if (confirmed != true) return;

    setState(() => _declining = true);
    final result = await ApiService.rejectQuotation(_quotation.id);
    if (!mounted) return;
    if (result['success'] == true) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Quotation declined.',
            style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
          ),
          backgroundColor: TmColors.black,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          margin: const EdgeInsets.all(16),
        ),
      );
      Navigator.pop(context);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            result['message'] as String? ?? 'Failed to decline. Please try again.',
            style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
          ),
          backgroundColor: TmColors.black,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          margin: const EdgeInsets.all(16),
        ),
      );
      setState(() => _declining = false);
    }
  }

  Future<void> _showInquirySheet(BuildContext context) async {
    final controller = TextEditingController();
    final msg = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      backgroundColor: context.card,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetCtx) => _InquirySheet(controller: controller),
    );
    controller.dispose();

    if (msg == null || msg.isEmpty || !mounted) return;

    setState(() => _sendingInquiry = true);
    final result = await ApiService.sendQuotationInquiry(_quotation.id, msg);
    if (!mounted) return;
    setState(() => _sendingInquiry = false);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          result['success'] == true
              ? 'Message sent to dispatcher.'
              : (result['message'] as String? ?? 'Failed to send. Try again.'),
          style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
        ),
        backgroundColor: TmColors.black,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        margin: const EdgeInsets.all(16),
      ),
    );
  }

  String _formatExpiry(Duration? remaining) {
    if (remaining == null || remaining == Duration.zero) return 'Expired';
    final h = remaining.inHours;
    final m = remaining.inMinutes % 60;
    if (h > 0) return 'Expires in ${h}h ${m}m';
    return 'Expires in ${m}m';
  }

  @override
  Widget build(BuildContext context) {
    final quotation = _quotation;
    final remaining = quotation.timeRemaining;
    final isUrgent = remaining != null && remaining.inHours < 2 && remaining != Duration.zero;

    return Scaffold(
      backgroundColor: context.bg,
      body: SafeArea(
        child: Column(
          children: [
            // ── Top bar ──────────────────────────────────────────────────
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
              decoration: BoxDecoration(
                border: Border(bottom: BorderSide(color: context.divider, width: 0.5)),
              ),
              child: Row(
                children: [
                  IconButton(
                    icon: Icon(Icons.arrow_back_rounded, color: context.textTertiary),
                    onPressed: () => Navigator.pop(context),
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Center(
                      child: Text(
                        'TowMate',
                        style: GoogleFonts.inter(
                          color: TmColors.yellow,
                          fontSize: 22,
                          letterSpacing: -0.8,
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 40),
                ],
              ),
            ),

            // ── Body ─────────────────────────────────────────────────────
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(24),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'QUOTATION READY',
                      style: GoogleFonts.inter(
                        color: context.textSecondary,
                        fontSize: 11,
                        letterSpacing: 0.8,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      quotation.quotationNumber,
                      style: GoogleFonts.inter(
                        color: context.textSecondary,
                        fontSize: 12,
                        letterSpacing: 0.4,
                      ),
                    ),
                    const SizedBox(height: 12),

                    // ── Price breakdown ───────────────────────────────────
                    _PriceBreakdown(quotation: quotation),
                    const SizedBox(height: 20),

                    Container(height: 1, color: context.divider),
                    const SizedBox(height: 20),

                    // ── Addresses ─────────────────────────────────────────
                    _AddressRow(label: 'Pickup', address: quotation.pickupAddress),
                    const SizedBox(height: 8),
                    _AddressRow(label: 'Dropoff', address: quotation.dropoffAddress),
                    const SizedBox(height: 16),

                    // ── Details ───────────────────────────────────────────
                    Row(
                      children: [
                        Icon(Icons.local_shipping_outlined,
                            size: 16, color: context.textSecondary),
                        const SizedBox(width: 6),
                        Text(
                          '${quotation.truckTypeName}  ·  ${quotation.distanceKm.toStringAsFixed(1)} km',
                          style: GoogleFonts.inter(
                            color: context.textTertiary,
                            fontSize: 13,
                            letterSpacing: 0.1,
                          ),
                        ),
                      ],
                    ),

                    if (remaining != null) ...[
                      const SizedBox(height: 8),
                      Row(
                        children: [
                          Icon(Icons.access_time_rounded,
                              size: 16,
                              color: isUrgent ? TmColors.yellow : context.textSecondary),
                          const SizedBox(width: 6),
                          Text(
                            _formatExpiry(remaining),
                            style: GoogleFonts.inter(
                              color: isUrgent ? TmColors.yellow : context.textSecondary,
                              fontSize: 12,
                              letterSpacing: 0.1,
                            ),
                          ),
                        ],
                      ),
                    ],

                    if (quotation.pickupNotes != null &&
                        quotation.pickupNotes!.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      Text(
                        'Notes: ${quotation.pickupNotes}',
                        style: GoogleFonts.inter(
                          color: context.textSecondary,
                          fontSize: 12,
                          letterSpacing: 0.1,
                          height: 1.5,
                        ),
                      ),
                    ],

                    // ── Price history ─────────────────────────────────────
                    if (quotation.priceChangeLog != null &&
                        quotation.priceChangeLog!.isNotEmpty) ...[
                      const SizedBox(height: 20),
                      Container(height: 1, color: context.divider),
                      const SizedBox(height: 16),
                      _PriceHistorySection(log: quotation.priceChangeLog!),
                    ],

                    const SizedBox(height: 32),

                    // ── Accept button ─────────────────────────────────────
                    ElevatedButton(
                      onPressed: _accepting || _declining ? null : _accept,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: TmColors.yellow,
                        foregroundColor: TmColors.black,
                        disabledBackgroundColor: TmColors.grey300,
                        minimumSize: const Size(double.infinity, 52),
                        shape: const StadiumBorder(),
                        elevation: 0,
                      ),
                      child: _accepting
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(
                                color: TmColors.black,
                                strokeWidth: 2,
                              ),
                            )
                          : Text(
                              'Accept Quotation',
                              style: GoogleFonts.inter(
                                color: TmColors.black,
                                fontSize: 15,
                              ),
                            ),
                    ),

                    if (_quotation.additionalFee > 0) ...[
                      const SizedBox(height: 10),
                      OutlinedButton.icon(
                        onPressed: _accepting || _declining || _sendingInquiry
                            ? null
                            : () => _showInquirySheet(context),
                        icon: Icon(Icons.chat_bubble_outline_rounded,
                            size: 16, color: context.textTertiary),
                        label: Text(
                          'Ask about this price',
                          style: GoogleFonts.inter(
                            color: context.textTertiary,
                            fontSize: 14,
                          ),
                        ),
                        style: OutlinedButton.styleFrom(
                          minimumSize: const Size(double.infinity, 48),
                          side: BorderSide(color: context.divider),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(6),
                          ),
                        ),
                      ),
                    ],

                    const SizedBox(height: 12),

                    // ── Decline button ────────────────────────────────────
                    OutlinedButton(
                      onPressed: _accepting || _declining ? null : _decline,
                      style: OutlinedButton.styleFrom(
                        foregroundColor: context.textTertiary,
                        minimumSize: const Size(double.infinity, 52),
                        side: BorderSide(color: context.divider),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(6),
                        ),
                      ),
                      child: _declining
                          ? SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(
                                color: context.textTertiary,
                                strokeWidth: 2,
                              ),
                            )
                          : Text(
                              'Decline',
                              style: GoogleFonts.inter(
                                color: context.textTertiary,
                                fontSize: 15,
                              ),
                            ),
                    ),

                    const SizedBox(height: 24),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PriceBreakdown extends StatelessWidget {
  const _PriceBreakdown({required this.quotation});
  final QuotationModel quotation;

  static final _fmt = NumberFormat('#,##0.00', 'en_PH');
  String _p(double v) => '₱${_fmt.format(v)}';

  @override
  Widget build(BuildContext context) {
    final hasBase       = quotation.baseRate > 0;
    final hasDist       = quotation.distanceFee > 0;
    final hasVat        = quotation.vatAmount > 0;
    final hasAdditional = quotation.additionalFee != 0;
    final hasBreakdown  = hasBase || hasDist || hasVat;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'QUOTATION TOTAL',
          style: GoogleFonts.inter(
            color: context.textSecondary,
            fontSize: 11,
            letterSpacing: 0.8,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          _p(quotation.estimatedPrice),
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 32,
            letterSpacing: -0.6,
          ),
        ),
        if (hasBreakdown || hasAdditional) ...[
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              border: Border.all(color: context.divider),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Column(
              children: [
                if (hasBase)
                  _PriceLine(label: 'Base Rate', amount: _p(quotation.baseRate)),
                if (hasDist)
                  _PriceLine(
                    label: 'Distance Fee (${quotation.distanceKm.toStringAsFixed(1)} km)',
                    amount: _p(quotation.distanceFee),
                  ),
                if (hasVat)
                  _PriceLine(label: 'VAT (12%)', amount: _p(quotation.vatAmount)),
                if (hasAdditional) ...[
                  if (hasBreakdown) ...[
                    const SizedBox(height: 6),
                    Divider(color: context.divider, height: 1),
                    const SizedBox(height: 6),
                  ],
                  _PriceLine(
                    label: 'Additional Fee',
                    amount: _p(quotation.additionalFee),
                    highlight: true,
                  ),
                  if (quotation.additionalFeeNote != null &&
                      quotation.additionalFeeNote!.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 3, left: 4),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('↳ ',
                              style: GoogleFonts.inter(
                                  color: context.textSecondary, fontSize: 11)),
                          Expanded(
                            child: Text(
                              quotation.additionalFeeNote!,
                              style: GoogleFonts.inter(
                                color: context.textSecondary,
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
      ],
    );
  }
}

class _PriceLine extends StatelessWidget {
  const _PriceLine({
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
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            label,
            style: GoogleFonts.inter(
              color: highlight ? context.textPrimary : context.textSecondary,
              fontSize: 13,
              letterSpacing: 0.1,
            ),
          ),
          Text(
            amount,
            style: GoogleFonts.inter(
              color: highlight ? TmColors.yellow : context.textSecondary,
              fontSize: 13,
              letterSpacing: 0.1,
            ),
          ),
        ],
      ),
    );
  }
}

class _AddressRow extends StatelessWidget {
  const _AddressRow({required this.label, required this.address});
  final String label;
  final String address;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 52,
          child: Text(
            label,
            style: GoogleFonts.inter(
              color: context.textSecondary,
              fontSize: 12,
              letterSpacing: 0.3,
            ),
          ),
        ),
        Expanded(
          child: Text(
            address,
            style: GoogleFonts.inter(
              color: context.textTertiary,
              fontSize: 13,
              letterSpacing: 0.1,
              height: 1.4,
            ),
          ),
        ),
      ],
    );
  }
}

class _PriceHistorySection extends StatefulWidget {
  const _PriceHistorySection({required this.log});
  final List<Map<String, dynamic>> log;

  @override
  State<_PriceHistorySection> createState() => _PriceHistorySectionState();
}

class _PriceHistorySectionState extends State<_PriceHistorySection> {
  bool _expanded = false;
  static final _fmt = NumberFormat('#,##0.00', 'en_PH');

  String _formatDate(String? iso) {
    if (iso == null) return '';
    final dt = DateTime.tryParse(iso);
    if (dt == null) return iso;
    return DateFormat('MMM d, yyyy h:mm a').format(dt.toLocal());
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        GestureDetector(
          onTap: () => setState(() => _expanded = !_expanded),
          child: Row(
            children: [
              Text(
                'Price History',
                style: GoogleFonts.inter(
                  color: context.textTertiary,
                  fontSize: 13,
                  letterSpacing: 0.2,
                ),
              ),
              const SizedBox(width: 6),
              Icon(
                _expanded ? Icons.expand_less : Icons.expand_more,
                color: context.textSecondary,
                size: 18,
              ),
            ],
          ),
        ),
        if (_expanded) ...[
          const SizedBox(height: 12),
          ...widget.log.map((entry) {
            final oldPrice = (entry['old'] as num?)?.toDouble() ?? 0.0;
            final newPrice = (entry['new'] as num?)?.toDouble() ?? 0.0;
            final reason = entry['reason'] as String?;
            final by = entry['by'] as String?;
            final at = entry['at'] as String?;
            return Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 6,
                    height: 6,
                    margin: const EdgeInsets.only(top: 5, right: 10),
                    decoration: const BoxDecoration(
                      color: TmColors.yellow,
                      shape: BoxShape.circle,
                    ),
                  ),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '₱${_fmt.format(oldPrice)}  →  ₱${_fmt.format(newPrice)}',
                          style: GoogleFonts.inter(
                            color: context.textPrimary,
                            fontSize: 13,
                            letterSpacing: 0.1,
                          ),
                        ),
                        if (reason != null && reason.isNotEmpty)
                          Text(
                            reason,
                            style: GoogleFonts.inter(
                              color: context.textSecondary,
                              fontSize: 12,
                              height: 1.4,
                            ),
                          ),
                        Text(
                          [if (by != null) by, if (at != null) _formatDate(at)]
                              .join(' · '),
                          style: GoogleFonts.inter(
                            color: context.textSecondary,
                            fontSize: 11,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            );
          }),
        ],
      ],
    );
  }
}

class _InquirySheet extends StatefulWidget {
  const _InquirySheet({required this.controller});
  final TextEditingController controller;

  @override
  State<_InquirySheet> createState() => _InquirySheetState();
}

class _InquirySheetState extends State<_InquirySheet> {
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 24,
        right: 24,
        top: 24,
        bottom: MediaQuery.of(context).viewInsets.bottom + 24,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Ask about this price',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 16,
              letterSpacing: -0.2,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'Your message will be sent to the dispatcher.',
            style: GoogleFonts.inter(
              color: context.textSecondary,
              fontSize: 13,
              height: 1.4,
            ),
          ),
          const SizedBox(height: 16),
          TextField(
            controller: widget.controller,
            maxLines: 4,
            maxLength: 500,
            autofocus: true,
            style: GoogleFonts.inter(color: context.textPrimary, fontSize: 14),
            decoration: InputDecoration(
              hintText: 'e.g. Why was ₱500 added to the price?',
              hintStyle: GoogleFonts.inter(
                  color: context.textSecondary, fontSize: 13),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: BorderSide(color: context.divider),
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: BorderSide(color: context.divider),
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide:
                    const BorderSide(color: TmColors.yellow, width: 1.5),
              ),
              contentPadding: const EdgeInsets.all(14),
            ),
          ),
          const SizedBox(height: 12),
          ElevatedButton(
            onPressed: () {
              final msg = widget.controller.text.trim();
              if (msg.isEmpty) return;
              Navigator.pop(context, msg);
            },
            style: ElevatedButton.styleFrom(
              backgroundColor: TmColors.yellow,
              foregroundColor: TmColors.black,
              minimumSize: const Size(double.infinity, 48),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(8)),
              elevation: 0,
            ),
            child: Text(
              'Send Message',
              style: GoogleFonts.inter(color: TmColors.black, fontSize: 14),
            ),
          ),
        ],
      ),
    );
  }
}
