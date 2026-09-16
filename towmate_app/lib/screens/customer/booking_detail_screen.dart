import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../core/theme.dart';
import '../../models/booking_model.dart';
import '../../services/api_service.dart';
import '../../widgets/booking_cancel_dialog.dart';
import '../../widgets/quotation_price_cards.dart';
import '../../widgets/skeleton_box.dart';

class BookingDetailScreen extends StatefulWidget {
  const BookingDetailScreen({super.key, required this.bookingCode});
  final String bookingCode;

  @override
  State<BookingDetailScreen> createState() => _BookingDetailScreenState();
}

class _BookingDetailScreenState extends State<BookingDetailScreen> {
  BookingModel? _booking;
  bool _loading = true;
  bool _fetchError = false;
  bool _cancelling = false;
  bool _loadingReceipt = false;

  static final _dateTime = DateFormat('MMM d, yyyy  h:mm a');

  @override
  void initState() {
    super.initState();
    _fetch();
  }

  Future<void> _fetch() async {
    setState(() {
      _loading = true;
      _fetchError = false;
    });
    final result = await ApiService.fetchBookingDetail(widget.bookingCode);
    if (!mounted) return;
    setState(() {
      _booking = result;
      _loading = false;
      _fetchError = result == null;
    });
  }

  Future<void> _viewReceipt() async {
    setState(() => _loadingReceipt = true);
    final url = await ApiService.fetchReceiptUrl(widget.bookingCode);
    if (!mounted) return;
    setState(() => _loadingReceipt = false);
    if (url == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Receipt is not available yet.')),
      );
      return;
    }
    final uri = Uri.parse(url);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  Future<void> _cancelBooking(BookingModel b) async {
    final confirmed = await showCancelBookingDialog(context, b);
    if (confirmed != true || !mounted) return;

    setState(() => _cancelling = true);
    final result = await ApiService.cancelBooking(b.bookingCode);
    if (!mounted) return;
    if (result['success'] == true) {
      await _fetch();
    } else {
      setState(() => _cancelling = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            (result['message'] as String?)?.isNotEmpty == true
                ? result['message'] as String
                : 'Failed to cancel. Please try again.',
          ),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
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
                      'Booking Details',
                      style: GoogleFonts.inter(
                        color: context.textPrimary,
                        fontSize: 17,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -0.2,
                      ),
                    ),
                  ),
                  RichText(
                    text: TextSpan(
                      style: GoogleFonts.inter(
                        fontSize: 16,
                        fontWeight: FontWeight.w600,
                        letterSpacing: -0.4,
                      ),
                      children: [
                        TextSpan(text: 'Tow', style: TextStyle(color: context.textPrimary)),
                        const TextSpan(text: 'Mate', style: TextStyle(color: TmColors.yellow)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            Expanded(
              child: _loading
                  ? const _DetailSkeleton()
                  : _fetchError
                      ? _errorView()
                      : _body(_booking!),
            ),
          ],
        ),
      ),
    );
  }

  Widget _errorView() {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text('Could not load booking.', style: GoogleFonts.inter(color: context.textTertiary, fontSize: 14)),
          const SizedBox(height: 12),
          GestureDetector(
            onTap: _fetch,
            child: Text('Retry', style: GoogleFonts.inter(color: TmColors.yellow, fontSize: 14)),
          ),
        ],
      ),
    );
  }

  Widget _body(BookingModel b) {
    final effectiveTotal = b.finalTotal ?? b.computedTotal;
    final canCancel = b.isCancellableByCustomer;
    final showReceipt = b.status == 'completed';
    final showRebook = b.status == 'completed' || b.status == 'cancelled';
    final hasCrew = b.teamLeaderName != null || b.driverName != null;

    return SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _BookingStatusCard(booking: b),
          const SizedBox(height: 20),

          Text(
            'Total Amount',
            style: GoogleFonts.inter(
              color: secondaryTextColor(context),
              fontSize: 11.5,
              fontWeight: FontWeight.w600,
              letterSpacing: 0.2,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            effectiveTotal != null ? '₱${NumberFormat('#,##0.00', 'en_PH').format(effectiveTotal)}' : '—',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 34,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.6,
            ),
          ),
          const SizedBox(height: 18),

          if (b.baseRate != null) ...[
            if (b.pricingIsProvisional)
              _ProvisionalPriceCard(baseRate: b.baseRate!, finalTotal: effectiveTotal ?? 0)
            else
              PriceBreakdownCard(
                baseRate: b.baseRate!,
                distanceFee: b.distanceFee ?? 0,
                distanceKm: b.distanceKm ?? 0,
                vatAmount: b.vatAmount ?? 0,
                additionalFee: b.additionalFee ?? 0,
              ),
            const SizedBox(height: 20),
          ],

          TripDetailsSection(
            pickupAddress: b.pickupAddress,
            dropoffAddress: b.dropoffAddress,
            truckTypeName: b.displayVehicleName,
            distanceKm: b.distanceKm ?? 0,
          ),
          const SizedBox(height: 20),

          _ServiceDetailsCard(booking: b),

          if (b.groupSiblings != null && b.groupSiblings!.isNotEmpty) ...[
            const SizedBox(height: 20),
            _GroupSiblingsCard(current: b, siblings: b.groupSiblings!),
          ],
          const SizedBox(height: 20),

          _TimelineCard(booking: b),

          if (hasCrew) ...[
            const SizedBox(height: 20),
            _DetailCard(
              title: 'CREW',
              rows: [
                if (b.teamLeaderName != null) _DetailRow('Team Leader', b.teamLeaderName!),
                if (b.driverName != null) _DetailRow('Driver', b.driverName!),
              ],
            ),
          ],

          if (b.paymentMethod != null) ...[
            const SizedBox(height: 20),
            _DetailCard(
              title: 'PAYMENT',
              rows: [_DetailRow('Method', _paymentLabel(b.paymentMethod))],
            ),
          ],

          if (b.pickupNotes != null && b.pickupNotes!.isNotEmpty) ...[
            const SizedBox(height: 20),
            _DetailCard(
              title: 'NOTES',
              rows: [],
              trailing: Text(
                b.pickupNotes!,
                style: GoogleFonts.inter(color: context.textTertiary, fontSize: 13, height: 1.5),
              ),
            ),
          ],

          if (b.priceChangeLog != null && b.priceChangeLog!.isNotEmpty) ...[
            const SizedBox(height: 20),
            Text('PRICE HISTORY', style: sectionEyebrowStyle(context)),
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
                children: [for (final entry in b.priceChangeLog!) _priceHistoryRow(entry)],
              ),
            ),
          ],

          if (b.arrivalPhotoUrl != null || b.dropoffPhotoUrl != null) ...[
            const SizedBox(height: 20),
            Text('PHOTOS', style: sectionEyebrowStyle(context)),
            const SizedBox(height: 8),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  if (b.arrivalPhotoUrl != null) _photoTile('Arrival', b.arrivalPhotoUrl!),
                  if (b.arrivalPhotoUrl != null && b.dropoffPhotoUrl != null) const SizedBox(width: 12),
                  if (b.dropoffPhotoUrl != null) _photoTile('Drop-off', b.dropoffPhotoUrl!),
                ],
              ),
            ),
          ],

          if (canCancel) ...[
            const SizedBox(height: 28),
            ElevatedButton(
              onPressed: _cancelling ? null : () => _cancelBooking(b),
              style: ElevatedButton.styleFrom(
                backgroundColor: TmColors.destructive,
                foregroundColor: TmColors.white,
                disabledBackgroundColor: TmColors.destructive.withValues(alpha: 0.6),
                minimumSize: const Size(double.infinity, 52),
                elevation: 0,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              ),
              child: _cancelling
                  ? const SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(strokeWidth: 2, color: TmColors.white),
                    )
                  : Text(
                      'Cancel Booking',
                      style: GoogleFonts.inter(color: TmColors.white, fontSize: 15, fontWeight: FontWeight.w600),
                    ),
            ),
          ],

          if (showReceipt) ...[
            const SizedBox(height: 12),
            ElevatedButton.icon(
              onPressed: _loadingReceipt ? null : _viewReceipt,
              icon: _loadingReceipt
                  ? const SizedBox(
                      height: 16,
                      width: 16,
                      child: CircularProgressIndicator(strokeWidth: 2, color: TmColors.black),
                    )
                  : const Icon(Icons.receipt_long_rounded, color: TmColors.black, size: 18),
              label: Text('View Receipt', style: GoogleFonts.inter(color: TmColors.black, fontSize: 15)),
              style: ElevatedButton.styleFrom(
                backgroundColor: TmColors.yellow,
                foregroundColor: TmColors.black,
                minimumSize: const Size(double.infinity, 52),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                elevation: 0,
              ),
            ),
          ],

          if (showRebook) ...[
            const SizedBox(height: 12),
            ElevatedButton(
              onPressed: () => Navigator.pushNamed(
                context,
                '/book-now',
                arguments: {
                  'pickupAddress': b.pickupAddress,
                  'pickupLat': b.pickupLat,
                  'pickupLng': b.pickupLng,
                  'dropoffAddress': b.dropoffAddress,
                  'dropoffLat': b.dropoffLat,
                  'dropoffLng': b.dropoffLng,
                  'truckTypeId': b.truckTypeId,
                },
              ),
              style: ElevatedButton.styleFrom(
                backgroundColor: TmColors.black,
                foregroundColor: TmColors.white,
                minimumSize: const Size(double.infinity, 52),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                elevation: 0,
              ),
              child: Text('Book same trip', style: GoogleFonts.inter(color: TmColors.white, fontSize: 15)),
            ),
          ],

          const SizedBox(height: 24),
        ],
      ),
    );
  }

  Widget _photoTile(String label, String url) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 11, letterSpacing: 0.3)),
        const SizedBox(height: 6),
        ClipRRect(
          borderRadius: BorderRadius.circular(8),
          child: Image.network(
            url,
            width: 160,
            height: 110,
            fit: BoxFit.cover,
            errorBuilder: (_, _, _) => Container(
              width: 160,
              height: 110,
              color: context.surface,
              alignment: Alignment.center,
              child: Text('Unavailable', style: GoogleFonts.inter(color: context.textSecondary, fontSize: 11)),
            ),
          ),
        ),
      ],
    );
  }

  Widget _priceHistoryRow(Map<String, dynamic> entry) {
    final oldP = double.tryParse(entry['old']?.toString() ?? '') ?? 0;
    final newP = double.tryParse(entry['new']?.toString() ?? '') ?? 0;
    final delta = newP - oldP;
    final deltaSign = delta >= 0 ? '+' : '-';
    final reason = entry['reason'] as String?;
    DateTime? ts;
    if (entry['at'] != null) ts = DateTime.tryParse(entry['at'] as String);
    final tsStr = ts != null ? _dateTime.format(ts.toLocal()) : '';
    final money = NumberFormat('#,##0.00', 'en_PH');

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                '₱${money.format(oldP)}',
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
                    '$deltaSign₱${money.format(delta.abs())}',
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
                '₱${money.format(newP)}',
                style: GoogleFonts.inter(color: context.textPrimary, fontSize: 13, fontWeight: FontWeight.w700),
              ),
            ],
          ),
          if (reason != null && reason.isNotEmpty) ...[
            const SizedBox(height: 3),
            Text(
              '"$reason"',
              style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 12, fontStyle: FontStyle.italic),
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

  String _paymentLabel(String? method) => switch (method) {
        'cash' => 'Cash',
        'gcash' => 'GCash',
        'bank_transfer' => 'Bank Transfer',
        _ => method ?? '—',
      };
}

class _BookingStatusCard extends StatelessWidget {
  const _BookingStatusCard({required this.booking});
  final BookingModel booking;

  static final _scheduledFmt = DateFormat('MMM d, yyyy · h:mm a');

  @override
  Widget build(BuildContext context) {
    final bucket = booking.schedulingBucket;
    final isBucketed = bucket != null && booking.status == 'scheduled_confirmed';

    final Color bannerBg;
    final Color iconBg;
    final Color iconColor;
    final IconData icon;
    final Color labelColor;
    final String label;

    if (isBucketed) {
      switch (bucket) {
        case 'overdue':
          bannerBg = TmColors.error.withValues(alpha: 0.08);
          iconBg = TmColors.error;
          iconColor = TmColors.white;
          icon = Icons.error_outline_rounded;
          labelColor = TmColors.error;
          label = 'Overdue';
          break;
        case 'ready':
          bannerBg = TmColors.yellow.withValues(alpha: 0.12);
          iconBg = TmColors.yellow;
          iconColor = TmColors.black;
          icon = Icons.local_shipping_outlined;
          labelColor = context.textPrimary;
          label = 'Ready';
          break;
        case 'upcoming':
          bannerBg = TmColors.success.withValues(alpha: 0.08);
          iconBg = TmColors.success;
          iconColor = TmColors.white;
          icon = Icons.schedule_rounded;
          labelColor = TmColors.success;
          label = 'Upcoming';
          break;
        default:
          bannerBg = TmColors.success.withValues(alpha: 0.08);
          iconBg = TmColors.success;
          iconColor = TmColors.white;
          icon = Icons.check_circle_rounded;
          labelColor = TmColors.success;
          label = 'Confirmed';
      }
    } else {
      bannerBg = context.surface;
      iconBg = context.textPrimary;
      iconColor = TmColors.white;
      icon = Icons.info_outline_rounded;
      labelColor = context.textPrimary;
      label = booking.humanStatus;
    }

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: bannerBg, borderRadius: BorderRadius.circular(14)),
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
                  booking.bookingCode,
                  style: GoogleFonts.inter(color: context.textPrimary, fontSize: 11.5, fontWeight: FontWeight.w500),
                ),
                if (isBucketed && booking.scheduledFor != null) ...[
                  const SizedBox(height: 1),
                  Text(
                    _scheduledFmt.format(booking.scheduledFor!.toLocal()),
                    style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 11.5),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _ServiceDetailsCard extends StatelessWidget {
  const _ServiceDetailsCard({required this.booking});
  final BookingModel booking;

  static final _scheduledFmt = DateFormat('MMM d, yyyy · h:mm a');

  @override
  Widget build(BuildContext context) {
    final isScheduled = booking.serviceType == 'schedule';
    final rows = <_DetailRow>[
      _DetailRow('Service Type', isScheduled ? 'Scheduled' : 'Book Now'),
      if (isScheduled && booking.scheduledFor != null)
        _DetailRow('Scheduled For', _scheduledFmt.format(booking.scheduledFor!.toLocal())),
    ];
    return _DetailCard(title: 'SERVICE DETAILS', rows: rows);
  }
}

class _TimelineCard extends StatelessWidget {
  const _TimelineCard({required this.booking});
  final BookingModel booking;

  static final _dateTimeFmt = DateFormat('MMM d, yyyy · h:mm a');

  @override
  Widget build(BuildContext context) {
    final rows = <Widget>[
      if (booking.createdAt != null)
        _TimelineRow('Booking submitted', _dateTimeFmt.format(booking.createdAt!.toLocal())),
      if (booking.completedAt != null)
        _TimelineRow('Completed', _dateTimeFmt.format(booking.completedAt!.toLocal())),
      if (booking.cancelledAt != null)
        _TimelineRow('Booking cancelled', _dateTimeFmt.format(booking.cancelledAt!.toLocal())),
    ];
    if (rows.isEmpty) return const SizedBox.shrink();
    return _DetailCard(title: 'TIMELINE', rows: rows);
  }
}

class _TimelineRow extends StatelessWidget {
  const _TimelineRow(this.title, this.timestamp);
  final String title;
  final String timestamp;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title,
            style: GoogleFonts.inter(color: context.textPrimary, fontSize: 13, fontWeight: FontWeight.w500, letterSpacing: 0.1),
          ),
          const SizedBox(width: 12),
          Flexible(
            child: Text(
              timestamp,
              textAlign: TextAlign.right,
              style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 12.5, letterSpacing: 0.1, height: 1.4),
            ),
          ),
        ],
      ),
    );
  }
}

class _DetailCard extends StatelessWidget {
  const _DetailCard({required this.title, required this.rows, this.trailing});
  final String title;
  final List<Widget> rows;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: sectionEyebrowStyle(context)),
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
              for (final row in rows) row,
              ?trailing,
            ],
          ),
        ),
      ],
    );
  }
}

class _DetailRow extends StatelessWidget {
  const _DetailRow(this.label, this.value);
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 13, letterSpacing: 0.1),
          ),
          const SizedBox(width: 12),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.right,
              style: GoogleFonts.inter(color: context.textPrimary, fontSize: 13, letterSpacing: 0.1, height: 1.4),
            ),
          ),
        ],
      ),
    );
  }
}

class _ProvisionalPriceCard extends StatelessWidget {
  const _ProvisionalPriceCard({required this.baseRate, required this.finalTotal});
  final double baseRate;
  final double finalTotal;

  @override
  Widget build(BuildContext context) {
    final vat = finalTotal - baseRate;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('ESTIMATED PRICE BREAKDOWN', style: sectionEyebrowStyle(context)),
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
              PriceLine(label: 'Estimated Base Rate', amount: formatPeso(baseRate)),
              PriceLine(label: 'Estimated VAT', amount: formatPeso(vat)),
              const SizedBox(height: 6),
              Divider(color: context.divider, height: 1),
              const SizedBox(height: 6),
              PriceLine(label: 'Estimated Total', amount: formatPeso(finalTotal), highlight: true),
              const SizedBox(height: 8),
              Text(
                'Scheduled vehicle pricing may change after quotation review.',
                style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 11.5, height: 1.4),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _GroupSiblingsCard extends StatelessWidget {
  const _GroupSiblingsCard({required this.current, required this.siblings});
  final BookingModel current;
  final List<BookingGroupSibling> siblings;

  @override
  Widget build(BuildContext context) {
    final entries = <({String bookingCode, String vehicleName, String serviceType, String status, String scheduledLabel, bool isCurrent})>[
      (
        bookingCode: current.bookingCode,
        vehicleName: current.displayVehicleName,
        serviceType: current.serviceType ?? 'book_now',
        status: current.humanStatus,
        scheduledLabel: current.scheduledLabel,
        isCurrent: true,
      ),
      for (final s in siblings)
        (
          bookingCode: s.bookingCode,
          vehicleName: s.displayVehicleName,
          serviceType: s.serviceType,
          status: s.humanStatus,
          scheduledLabel: s.scheduledLabel,
          isCurrent: false,
        ),
    ]..sort((a, b) => a.bookingCode.compareTo(b.bookingCode));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('THIS REQUEST', style: sectionEyebrowStyle(context)),
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
              for (int i = 0; i < entries.length; i++) ...[
                if (i > 0) ...[
                  const SizedBox(height: 10),
                  Divider(color: context.divider, height: 1),
                  const SizedBox(height: 10),
                ],
                _GroupSiblingRow(
                  index: i + 1,
                  bookingCode: entries[i].bookingCode,
                  vehicleName: entries[i].vehicleName,
                  serviceType: entries[i].serviceType,
                  status: entries[i].status,
                  scheduledLabel: entries[i].scheduledLabel,
                  isCurrent: entries[i].isCurrent,
                  onTap: entries[i].isCurrent
                      ? null
                      : () => Navigator.pushNamed(context, '/booking-detail', arguments: entries[i].bookingCode),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _GroupSiblingRow extends StatelessWidget {
  const _GroupSiblingRow({
    required this.index,
    required this.bookingCode,
    required this.vehicleName,
    required this.serviceType,
    required this.status,
    required this.scheduledLabel,
    required this.isCurrent,
    required this.onTap,
  });
  final int index;
  final String bookingCode;
  final String vehicleName;
  final String serviceType;
  final String status;
  final String scheduledLabel;
  final bool isCurrent;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final isScheduled = serviceType == 'schedule';
    final subtitle = isScheduled && scheduledLabel.isNotEmpty
        ? 'Scheduled · $scheduledLabel'
        : '${isScheduled ? 'Scheduled' : 'Book Now'} · $status';

    final row = Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Text(
                    'Vehicle $index',
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 12.5,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 0.1,
                    ),
                  ),
                  if (isCurrent) ...[
                    const SizedBox(width: 6),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(
                        color: TmColors.yellow,
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: Text(
                        'You are here',
                        style: GoogleFonts.inter(color: TmColors.black, fontSize: 9.5, fontWeight: FontWeight.w700),
                      ),
                    ),
                  ],
                ],
              ),
              const SizedBox(height: 2),
              Text(
                '$bookingCode · $vehicleName',
                style: GoogleFonts.inter(color: context.textPrimary, fontSize: 13, letterSpacing: 0.1),
              ),
              const SizedBox(height: 1),
              Text(
                subtitle,
                style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 12, letterSpacing: 0.1),
              ),
            ],
          ),
        ),
        if (onTap != null) Icon(Icons.chevron_right, color: context.textTertiary, size: 20),
      ],
    );

    if (onTap == null) return row;
    return GestureDetector(onTap: onTap, behavior: HitTestBehavior.opaque, child: row);
  }
}

class _DetailSkeleton extends StatelessWidget {
  const _DetailSkeleton();

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SkeletonBox(width: double.infinity, height: 64, borderRadius: BorderRadius.circular(14)),
          const SizedBox(height: 20),
          const SkeletonBox(width: 90, height: 12),
          const SizedBox(height: 6),
          const SkeletonBox(width: 160, height: 32),
          const SizedBox(height: 20),
          SkeletonBox(width: double.infinity, height: 130, borderRadius: BorderRadius.circular(14)),
          const SizedBox(height: 20),
          SkeletonBox(width: double.infinity, height: 110, borderRadius: BorderRadius.circular(14)),
          const SizedBox(height: 20),
          SkeletonBox(width: double.infinity, height: 70, borderRadius: BorderRadius.circular(14)),
        ],
      ),
    );
  }
}
