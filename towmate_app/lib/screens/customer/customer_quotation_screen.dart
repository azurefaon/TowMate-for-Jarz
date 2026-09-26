import 'dart:async';

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import '../../core/theme.dart';
import '../../models/quotation_model.dart';
import '../../services/api_service.dart';
import '../../widgets/quotation_price_cards.dart';

String _peso(double v) => formatPeso(v);

Widget _actionButtonContent({
  required bool loading,
  required Color loadingColor,
  required IconData icon,
  required String label,
  required Color textColor,
}) {
  if (loading) {
    return SizedBox(
      width: 20,
      height: 20,
      child: CircularProgressIndicator(color: loadingColor, strokeWidth: 2),
    );
  }
  return Row(
    mainAxisAlignment: MainAxisAlignment.center,
    children: [
      Icon(icon, size: 18, color: textColor),
      const SizedBox(width: 8),
      Flexible(
        child: Text(
          label,
          textAlign: TextAlign.center,
          overflow: TextOverflow.ellipsis,
          maxLines: 1,
          style: GoogleFonts.inter(
            color: textColor,
            fontSize: 15,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
    ],
  );
}

class CustomerQuotationScreen extends StatefulWidget {
  const CustomerQuotationScreen({super.key});

  @override
  State<CustomerQuotationScreen> createState() => _CustomerQuotationScreenState();
}

class _CustomerQuotationScreenState extends State<CustomerQuotationScreen> {
  bool _accepting = false;
  bool _declining = false;
  bool _requestingReview = false;

  Timer? _tickTimer;
  bool _timerStarted = false;

  QuotationModel? get _quotationOrNull =>
      ModalRoute.of(context)?.settings.arguments as QuotationModel?;

  QuotationModel get _quotation => _quotationOrNull!;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_timerStarted) return;
    _timerStarted = true;
    final remaining = _quotationOrNull?.timeRemaining;
    if (remaining != null && remaining != Duration.zero) {
      _tickTimer = Timer.periodic(const Duration(minutes: 1), (_) {
        if (mounted) setState(() {});
      });
    }
  }

  @override
  void dispose() {
    _tickTimer?.cancel();
    super.dispose();
  }

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

  Future<void> _requestPriceReview() async {
    final controller = TextEditingController();
    bool showReasonError = false;
    final reason = await showDialog<String>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) {
          return AlertDialog(
            backgroundColor: ctx.card,
            title: Text(
              'Request price review',
              style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 16, letterSpacing: -0.2),
            ),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'You may ask the dispatcher to review your quotation. A review request does not guarantee that the price will be changed.',
                  style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 13, height: 1.5),
                ),
                const SizedBox(height: 14),
                RichText(
                  text: TextSpan(
                    style: GoogleFonts.inter(color: ctx.textSecondary, fontSize: 12, letterSpacing: 0.2),
                    children: [
                      const TextSpan(text: 'Reason for review'),
                      TextSpan(text: ' *', style: GoogleFonts.inter(color: TmColors.error, fontSize: 12)),
                    ],
                  ),
                ),
                const SizedBox(height: 6),
                TextField(
                  controller: controller,
                  maxLines: 3,
                  onChanged: (value) {
                    if (showReasonError && value.trim().isNotEmpty) {
                      setDialogState(() => showReasonError = false);
                    }
                  },
                  style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14),
                  decoration: InputDecoration(
                    hintText: 'Tell us why you would like the quotation reviewed.',
                    hintStyle: GoogleFonts.inter(color: ctx.textSecondary, fontSize: 13),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(8),
                      borderSide: BorderSide(color: ctx.divider),
                    ),
                    contentPadding: const EdgeInsets.all(12),
                  ),
                ),
                if (showReasonError) ...[
                  const SizedBox(height: 6),
                  Text(
                    'A reason is required to request a price review.',
                    style: GoogleFonts.inter(color: TmColors.error, fontSize: 12),
                  ),
                ],
              ],
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: Text('Cancel', style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 14)),
              ),
              TextButton(
                onPressed: () {
                  final trimmed = controller.text.trim();
                  if (trimmed.isEmpty) {
                    setDialogState(() => showReasonError = true);
                    return;
                  }
                  Navigator.pop(ctx, trimmed);
                },
                child: Text('Submit', style: GoogleFonts.inter(color: TmColors.yellow, fontSize: 14)),
              ),
            ],
          );
        },
      ),
    );
    if (reason == null || reason.isEmpty) return;

    setState(() => _requestingReview = true);
    final result = await ApiService.requestPriceReview(_quotation.id, reason);
    if (!mounted) return;
    setState(() => _requestingReview = false);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          result['success'] == true
              ? 'Your request has been sent. We will review the price and follow up shortly.'
              : (result['message'] as String? ?? 'Failed to send your request. Please try again.'),
          style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
        ),
        backgroundColor: TmColors.black,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        margin: const EdgeInsets.all(16),
      ),
    );
    if (result['success'] == true) {
      Navigator.pop(context);
    }
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
                    child: Text(
                      'Quotation Details',
                      style: GoogleFonts.inter(
                        color: context.textPrimary,
                        fontSize: 17,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -0.2,
                      ),
                    ),
                  ),
                  Text(
                    'TowMate',
                    style: GoogleFonts.inter(
                      color: TmColors.yellow,
                      fontSize: 16,
                      fontWeight: FontWeight.w600,
                      letterSpacing: -0.4,
                    ),
                  ),
                ],
              ),
            ),

            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(24),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _StatusBanner(
                      status: quotation.status,
                      quotationNumber: quotation.quotationNumber,
                      countdownText: remaining != null ? _formatExpiry(remaining) : null,
                      isUrgent: isUrgent,
                      isExpired: quotation.status == 'expired' || quotation.isExpired,
                    ),
                    const SizedBox(height: 20),

                    Text(
                      'Total Amount',
                      style: GoogleFonts.inter(
                        color: context.textSecondary,
                        fontSize: 11.5,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 0.2,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      _peso(quotation.estimatedPrice),
                      style: GoogleFonts.inter(
                        color: context.textPrimary,
                        fontSize: 34,
                        fontWeight: FontWeight.w800,
                        letterSpacing: -0.6,
                      ),
                    ),
                    const SizedBox(height: 18),

                    if (quotation.isGrouped)
                      GroupedPriceBreakdownCard(
                        vehicles: quotation.groupVehicles,
                        vatRate: quotation.vatRate,
                        discount: quotation.discount,
                        finalTotal: quotation.estimatedPrice,
                        priceAdjustments: quotation.priceAdjustments,
                      )
                    else
                      PriceBreakdownCard(
                        baseRate: quotation.baseRate,
                        distanceFee: quotation.distanceFee,
                        distanceKm: quotation.distanceKm,
                        subtotal: quotation.subtotal,
                        vatAmount: quotation.vatAmount,
                        vatRate: quotation.vatRate,
                        finalTotal: quotation.estimatedPrice,
                        discount: quotation.discount,
                        additionalFeeNote: quotation.additionalFeeNote,
                        priceAdjustments: quotation.priceAdjustments,
                        showFullBreakdown: true,
                      ),
                    const SizedBox(height: 20),

                    TripDetailsSection(
                      pickupAddress: quotation.pickupAddress,
                      dropoffAddress: quotation.dropoffAddress,
                      truckTypeName: quotation.truckTypeName,
                      distanceKm: quotation.distanceKm,
                    ),

                    if (quotation.pickupNotes != null &&
                        quotation.pickupNotes!.isNotEmpty) ...[
                      const SizedBox(height: 12),
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

                    if (quotation.priceChangeLog != null &&
                        quotation.priceChangeLog!.isNotEmpty) ...[
                      const SizedBox(height: 20),
                      _PriceHistorySection(log: quotation.priceChangeLog!),
                    ],

                    const SizedBox(height: 28),

                    if (quotation.isPriceReviewRequested) ...[
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(16),
                        decoration: BoxDecoration(
                          color: context.card,
                          border: Border.all(color: context.divider),
                          borderRadius: BorderRadius.circular(14),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                const Icon(Icons.hourglass_top_rounded,
                                    size: 18, color: TmColors.yellow),
                                const SizedBox(width: 8),
                                Text(
                                  'Price Review Requested',
                                  style: GoogleFonts.inter(
                                    color: context.textPrimary,
                                    fontSize: 14,
                                    fontWeight: FontWeight.w600,
                                    letterSpacing: -0.1,
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 8),
                            Text(
                              'We\'re reviewing your request. You\'ll be notified once it\'s resolved.',
                              style: GoogleFonts.inter(
                                color: context.textSecondary,
                                fontSize: 13,
                                height: 1.5,
                              ),
                            ),
                            if (quotation.responseNote != null &&
                                quotation.responseNote!.isNotEmpty) ...[
                              const SizedBox(height: 10),
                              Text(
                                'Your reason: ${quotation.responseNote}',
                                style: GoogleFonts.inter(
                                  color: context.textTertiary,
                                  fontSize: 12,
                                  height: 1.4,
                                ),
                              ),
                            ],
                          ],
                        ),
                      ),
                    ] else ...[
                      ElevatedButton(
                        onPressed: _accepting || _declining || _requestingReview
                            ? null
                            : _accept,
                        style: ElevatedButton.styleFrom(
                          backgroundColor: TmColors.yellow,
                          foregroundColor: TmColors.black,
                          disabledBackgroundColor: TmColors.grey300,
                          minimumSize: const Size(double.infinity, 52),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(14),
                          ),
                          elevation: 0,
                        ),
                        child: _actionButtonContent(
                          loading: _accepting,
                          loadingColor: TmColors.black,
                          icon: Icons.check_circle_outline_rounded,
                          label: 'Accept & Confirm',
                          textColor: TmColors.black,
                        ),
                      ),

                      const SizedBox(height: 12),

                      OutlinedButton(
                        onPressed: _accepting || _declining || _requestingReview
                            ? null
                            : _requestPriceReview,
                        style: OutlinedButton.styleFrom(
                          foregroundColor: context.textPrimary,
                          minimumSize: const Size(double.infinity, 52),
                          side: BorderSide(color: context.divider, width: 1.5),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(14),
                          ),
                        ),
                        child: _actionButtonContent(
                          loading: _requestingReview,
                          loadingColor: context.textPrimary,
                          icon: Icons.chat_bubble_outline_rounded,
                          label: 'Request Price Review',
                          textColor: context.textPrimary,
                        ),
                      ),

                      const SizedBox(height: 12),

                      OutlinedButton(
                        onPressed: _accepting || _declining || _requestingReview
                            ? null
                            : _decline,
                        style: OutlinedButton.styleFrom(
                          foregroundColor: TmColors.error,
                          minimumSize: const Size(double.infinity, 52),
                          side: const BorderSide(color: TmColors.error, width: 1.5),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(14),
                          ),
                        ),
                        child: _actionButtonContent(
                          loading: _declining,
                          loadingColor: TmColors.error,
                          icon: Icons.cancel_outlined,
                          label: 'Decline',
                          textColor: TmColors.error,
                        ),
                      ),
                    ],

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

class _StatusBanner extends StatelessWidget {
  const _StatusBanner({
    required this.status,
    required this.quotationNumber,
    required this.countdownText,
    required this.isUrgent,
    required this.isExpired,
  });

  final String status;
  final String quotationNumber;
  final String? countdownText;
  final bool isUrgent;
  final bool isExpired;

  String _humanizeStatus(String s) => s
      .split('_')
      .map((w) => w.isEmpty ? w : '${w[0].toUpperCase()}${w.substring(1)}')
      .join(' ');

  @override
  Widget build(BuildContext context) {
    final isSent = status == 'sent' && !isExpired;
    final isReview = status == 'price_review_requested';

    final Color bannerBg;
    final Color iconBg;
    final Color iconColor;
    final IconData icon;
    final Color labelColor;
    final Color secondaryColor;
    final Color urgentColor;
    final String label;

    if (isExpired) {
      final expiredFg = context.isDark ? TmColors.black : TmColors.white;
      bannerBg = context.isDark ? TmColors.grey300 : TmColors.black;
      iconBg = expiredFg.withValues(alpha: 0.15);
      iconColor = expiredFg;
      icon = Icons.history_rounded;
      labelColor = expiredFg;
      secondaryColor = expiredFg.withValues(alpha: 0.75);
      urgentColor = expiredFg;
      label = 'Expired';
    } else if (isSent) {
      bannerBg = TmColors.success;
      iconBg = TmColors.white;
      iconColor = TmColors.success;
      icon = Icons.check_circle_rounded;
      labelColor = TmColors.black;
      secondaryColor = TmColors.black.withValues(alpha: 0.75);
      urgentColor = TmColors.black;
      label = 'Quotation Ready';
    } else if (isReview) {
      bannerBg = TmColors.yellow.withValues(alpha: 0.12);
      iconBg = TmColors.yellow;
      iconColor = TmColors.black;
      icon = Icons.hourglass_top_rounded;
      labelColor = context.textPrimary;
      secondaryColor = context.textSecondary;
      urgentColor = TmColors.yellow;
      label = 'Price Review Requested';
    } else {
      bannerBg = context.surface;
      iconBg = context.divider;
      iconColor = context.textSecondary;
      icon = Icons.info_outline_rounded;
      labelColor = context.textPrimary;
      secondaryColor = context.textSecondary;
      urgentColor = TmColors.yellow;
      label = _humanizeStatus(status);
    }

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: bannerBg,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        children: [
          Container(
            width: 32,
            height: 32,
            decoration: BoxDecoration(color: iconBg, shape: BoxShape.circle),
            child: Icon(icon, size: 17, color: iconColor),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: GoogleFonts.inter(
                    color: labelColor,
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    letterSpacing: -0.1,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  quotationNumber,
                  style: GoogleFonts.inter(color: secondaryColor, fontSize: 11.5),
                ),
              ],
            ),
          ),
          if (countdownText != null && !isExpired) ...[
            const SizedBox(width: 8),
            Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  Icons.access_time_rounded,
                  size: 14,
                  color: isUrgent ? urgentColor : secondaryColor,
                ),
                const SizedBox(width: 4),
                Text(
                  countdownText!,
                  style: GoogleFonts.inter(
                    color: isUrgent ? urgentColor : secondaryColor,
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
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

  String _formatDate(String? iso) {
    if (iso == null) return '';
    final dt = DateTime.tryParse(iso);
    if (dt == null) return iso;
    return DateFormat('MMM d, yyyy h:mm a').format(dt.toLocal());
  }

  Widget _priceHistoryRow(BuildContext context, Map<String, dynamic> entry) {
    final oldPrice = (entry['old'] as num?)?.toDouble() ?? 0.0;
    final newPrice = (entry['new'] as num?)?.toDouble() ?? 0.0;
    final delta = newPrice - oldPrice;
    final deltaSign = delta >= 0 ? '+' : '-';
    final reason = entry['reason'] as String?;
    final tsStr = _formatDate(entry['at'] as String?);

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                _peso(oldPrice),
                style: GoogleFonts.inter(
                  color: context.textTertiary,
                  fontSize: 12.5,
                  decoration: TextDecoration.lineThrough,
                ),
              ),
              if (delta != 0)
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 7),
                  child: Text(
                    '$deltaSign${_peso(delta.abs())}',
                    style: GoogleFonts.inter(
                      color: delta > 0 ? TmColors.success : TmColors.error,
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                )
              else
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 7),
                  child: Icon(Icons.arrow_forward_rounded, size: 13, color: context.textTertiary),
                ),
              Text(
                _peso(newPrice),
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
          if (reason != null && reason.isNotEmpty) ...[
            const SizedBox(height: 3),
            Text(
              '"$reason"',
              style: GoogleFonts.inter(
                color: context.textSecondary,
                fontSize: 12,
                fontStyle: FontStyle.italic,
              ),
            ),
          ],
          if (tsStr.isNotEmpty) ...[
            const SizedBox(height: 2),
            Text(tsStr, style: GoogleFonts.inter(color: context.textTertiary, fontSize: 11)),
          ],
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        border: Border.all(color: context.divider),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          GestureDetector(
            onTap: () => setState(() => _expanded = !_expanded),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  'Price History',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    letterSpacing: 0.2,
                  ),
                ),
                Icon(
                  _expanded ? Icons.expand_less : Icons.expand_more,
                  color: context.textSecondary,
                  size: 18,
                ),
              ],
            ),
          ),
          if (_expanded) ...[
            const SizedBox(height: 10),
            Divider(color: context.divider, height: 1),
            const SizedBox(height: 10),
            ...widget.log.map((entry) => _priceHistoryRow(context, entry)),
          ],
        ],
      ),
    );
  }
}
