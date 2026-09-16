import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import '../../core/theme.dart';
import '../../models/booking_model.dart';
import '../../models/quotation_model.dart';
import '../../services/api_service.dart';
import '../../widgets/booking_cancel_dialog.dart';
import '../../widgets/skeleton_box.dart';
import '../../widgets/tm_bottom_nav.dart';

Color _secondaryTextColor(BuildContext context) =>
    context.isDark ? TmColors.grey500 : const Color(0xFF6B6B6B);

String _serviceTypeLabel(String? serviceType) =>
    serviceType == 'schedule' ? 'Scheduled' : 'Book Now';

Color _statusColor(String status, BuildContext context) {
  const positive = {'completed'};
  const negative = {'cancelled', 'rejected', 'not_responding'};
  const attention = {'waiting_verification'};
  if (positive.contains(status)) return const Color(0xFF15803D);
  if (negative.contains(status)) return TmColors.error;
  if (attention.contains(status)) return const Color(0xFFB45309);
  return context.textPrimary;
}

class MyBookingsScreen extends StatefulWidget {
  const MyBookingsScreen({super.key});

  @override
  State<MyBookingsScreen> createState() => _MyBookingsScreenState();
}

class _MyBookingsScreenState extends State<MyBookingsScreen> {
  List<BookingModel> _bookings = [];
  QuotationModel? _pendingQuotation;
  bool _loading = true;
  bool _loadingMore = false;
  bool _hasMore = false;
  bool _fetchFailed = false;
  int _page = 1;
  int _tab = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool refresh = false}) async {
    setState(() {
      _loading = true;
      _fetchFailed = false;
      if (refresh) {
        _page = 1;
        _bookings = [];
      }
    });
    final results = await Future.wait([
      ApiService.fetchBookingHistory(page: _page),
      ApiService.fetchPendingQuotation(),
    ]);
    if (!mounted) return;
    final result = results[0] as Map<String, dynamic>;
    final quotation = results[1] as QuotationModel?;
    setState(() {
      _loading = false;
      _pendingQuotation = quotation;
      if (result['success'] == true) {
        final incoming = result['bookings'] as List<BookingModel>;
        _bookings = refresh ? incoming : [..._bookings, ...incoming];
        _hasMore = result['hasMore'] as bool;
      } else {
        _fetchFailed = _bookings.isEmpty;
      }
    });
  }

  Future<void> _loadMore() async {
    if (_loadingMore || !_hasMore) return;
    setState(() {
      _loadingMore = true;
      _page++;
    });
    final result = await ApiService.fetchBookingHistory(page: _page);
    if (!mounted) return;
    setState(() {
      _loadingMore = false;
      if (result['success'] == true) {
        _bookings = [..._bookings, ...(result['bookings'] as List<BookingModel>)];
        _hasMore = result['hasMore'] as bool;
      }
    });
  }

  List<Object> _buildDisplayList(List<BookingModel> source) {
    final seen = <String>{};
    final result = <Object>[];
    for (final b in source) {
      if (b.groupCode == null) {
        result.add(b);
      } else {
        if (!seen.contains(b.groupCode)) {
          seen.add(b.groupCode!);
          final group = source.where((x) => x.groupCode == b.groupCode).toList();
          group.sort((a, b) {
            if (a.serviceType == 'book_now') return -1;
            if (b.serviceType == 'book_now') return 1;
            return 0;
          });
          result.add(group);
        }
      }
    }
    return result;
  }

  Future<void> _cancelBooking(BookingModel b) async {
    final confirmed = await showCancelBookingDialog(context, b);
    if (confirmed != true || !mounted) return;
    final result = await ApiService.cancelBooking(b.bookingCode);
    if (!mounted) return;
    if (result['success'] == true) {
      _load(refresh: true);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            (result['message'] as String?)?.isNotEmpty == true
                ? result['message'] as String
                : 'Failed to cancel booking.',
          ),
        ),
      );
    }
  }

  void _openQuotation() async {
    await Navigator.pushNamed(context, '/quotation', arguments: _pendingQuotation);
    _load(refresh: true);
  }

  void _openBooking(BookingModel b) {
    Navigator.pushNamed(context, '/booking-detail', arguments: b.bookingCode);
  }

  @override
  Widget build(BuildContext context) {
    final active = _bookings.where((b) => !b.isHistorical).toList();
    final history = _bookings.where((b) => b.isHistorical).toList();
    final showFullScreenError = _fetchFailed && _bookings.isEmpty;

    return Scaffold(
      backgroundColor: context.bg,
      bottomNavigationBar: const TmBottomNav(currentRoute: '/my-bookings'),
      body: SafeArea(
        child: Column(
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
              decoration: BoxDecoration(
                border: Border(bottom: BorderSide(color: context.divider, width: 0.5)),
              ),
              child: Text(
                'My Bookings',
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 18,
                  fontWeight: FontWeight.w600,
                  letterSpacing: -0.4,
                ),
              ),
            ),
            _SegmentedTabs(
              index: _tab,
              onChanged: (i) => setState(() => _tab = i),
            ),
            Expanded(
              child: _loading
                  ? const _BookingListSkeleton()
                  : showFullScreenError
                      ? _ErrorRetryState(onRetry: () => _load(refresh: true))
                      : RefreshIndicator(
                          color: TmColors.black,
                          onRefresh: () => _load(refresh: true),
                          child: _tab == 0
                              ? _ActiveList(
                                  bookings: active,
                                  displayList: _buildDisplayList(active),
                                  quotation: _pendingQuotation,
                                  onOpenQuotation: _openQuotation,
                                  onOpenBooking: _openBooking,
                                  onCancel: _cancelBooking,
                                )
                              : _HistoryList(
                                  displayList: _buildDisplayList(history),
                                  hasMore: _hasMore,
                                  loadingMore: _loadingMore,
                                  onLoadMore: _loadMore,
                                  onOpenBooking: _openBooking,
                                ),
                        ),
            ),
          ],
        ),
      ),
    );
  }
}

class _SegmentedTabs extends StatelessWidget {
  const _SegmentedTabs({required this.index, required this.onChanged});
  final int index;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 14, 20, 4),
      child: Container(
        padding: const EdgeInsets.all(4),
        decoration: BoxDecoration(
          color: context.surface,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          children: [
            Expanded(child: _SegmentButton(label: 'Active', selected: index == 0, onTap: () => onChanged(0))),
            Expanded(child: _SegmentButton(label: 'History', selected: index == 1, onTap: () => onChanged(1))),
          ],
        ),
      ),
    );
  }
}

class _SegmentButton extends StatelessWidget {
  const _SegmentButton({required this.label, required this.selected, required this.onTap});
  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        height: 38,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: selected ? context.card : Colors.transparent,
          borderRadius: BorderRadius.circular(9),
          boxShadow: selected
              ? [BoxShadow(color: Colors.black.withValues(alpha: 0.06), blurRadius: 4, offset: const Offset(0, 1))]
              : null,
        ),
        child: Text(
          label,
          style: GoogleFonts.inter(
            color: selected ? context.textPrimary : _secondaryTextColor(context),
            fontSize: 13.5,
            fontWeight: selected ? FontWeight.w600 : FontWeight.w500,
            letterSpacing: 0.1,
          ),
        ),
      ),
    );
  }
}

class _ActiveList extends StatelessWidget {
  const _ActiveList({
    required this.bookings,
    required this.displayList,
    required this.quotation,
    required this.onOpenQuotation,
    required this.onOpenBooking,
    required this.onCancel,
  });
  final List<BookingModel> bookings;
  final List<Object> displayList;
  final QuotationModel? quotation;
  final VoidCallback onOpenQuotation;
  final void Function(BookingModel) onOpenBooking;
  final void Function(BookingModel) onCancel;

  @override
  Widget build(BuildContext context) {
    if (quotation == null && displayList.isEmpty) {
      return const _EmptyState(
        title: 'No active bookings',
        message: 'Your current and upcoming towing requests will appear here.',
        showBookNow: true,
      );
    }

    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.only(bottom: 24),
      children: [
        if (quotation != null) ...[
          const SizedBox(height: 12),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: _QuotationBanner(quotation: quotation!, onTap: onOpenQuotation),
          ),
        ],
        const SizedBox(height: 12),
        for (final item in displayList)
          item is BookingModel
              ? _BookingCard(booking: item, onTap: () => onOpenBooking(item), onCancel: () => onCancel(item))
              : _GroupBookingCard(
                  primary: (item as List<BookingModel>).first,
                  siblings: item.skip(1).toList(),
                  onTap: () => onOpenBooking(item.first),
                  onCancel: () => onCancel(item.first),
                ),
      ],
    );
  }
}

class _HistoryList extends StatelessWidget {
  const _HistoryList({
    required this.displayList,
    required this.hasMore,
    required this.loadingMore,
    required this.onLoadMore,
    required this.onOpenBooking,
  });
  final List<Object> displayList;
  final bool hasMore;
  final bool loadingMore;
  final VoidCallback onLoadMore;
  final void Function(BookingModel) onOpenBooking;

  @override
  Widget build(BuildContext context) {
    if (displayList.isEmpty) {
      return const _EmptyState(
        title: 'No booking history',
        message: 'Completed and cancelled bookings will appear here.',
        showBookNow: false,
      );
    }

    return ListView.builder(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.only(top: 12, bottom: 24),
      itemCount: displayList.length + (hasMore ? 1 : 0),
      itemBuilder: (_, i) {
        if (i >= displayList.length) {
          return Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
            child: Center(
              child: loadingMore
                  ? const SkeletonBox(width: double.infinity, height: 176, borderRadius: BorderRadius.all(Radius.circular(14)))
                  : GestureDetector(
                      onTap: onLoadMore,
                      child: Text(
                        'Load more',
                        style: GoogleFonts.inter(color: context.textPrimary, fontSize: 13, fontWeight: FontWeight.w600),
                      ),
                    ),
            ),
          );
        }
        final item = displayList[i];
        return item is BookingModel
            ? _BookingCard(booking: item, onTap: () => onOpenBooking(item), onCancel: () {})
            : _GroupBookingCard(
                primary: (item as List<BookingModel>).first,
                siblings: item.skip(1).toList(),
                onTap: () => onOpenBooking(item.first),
                onCancel: () {},
              );
      },
    );
  }
}

class _QuotationBanner extends StatelessWidget {
  const _QuotationBanner({required this.quotation, required this.onTap});
  final QuotationModel quotation;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final reviewing = quotation.isPriceReviewRequested;
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          border: Border.all(color: TmColors.yellow, width: 1.5),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    reviewing ? 'Price Review Requested' : 'Quotation Ready',
                    style: GoogleFonts.inter(color: context.textPrimary, fontSize: 15, fontWeight: FontWeight.w600, letterSpacing: -0.2),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    reviewing
                        ? 'We are reviewing your requested price change.'
                        : 'Review the quotation for your towing request.',
                    style: GoogleFonts.inter(color: _secondaryTextColor(context), fontSize: 12.5, height: 1.4),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Icon(Icons.chevron_right, color: context.textPrimary, size: 22),
          ],
        ),
      ),
    );
  }
}

class _BookingCard extends StatelessWidget {
  const _BookingCard({required this.booking, required this.onTap, required this.onCancel});
  final BookingModel booking;
  final VoidCallback onTap;
  final VoidCallback onCancel;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
      child: GestureDetector(
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            border: Border.all(color: context.divider),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  Expanded(child: _BookingCardBody(booking: booking)),
                  const SizedBox(width: 8),
                  Icon(Icons.chevron_right, color: context.textPrimary, size: 22),
                ],
              ),
              if (booking.isCancellableByCustomer || _showQuotationSentHint(booking)) ...[
                const SizedBox(height: 12),
                _CancelButton(booking: booking, onCancel: onCancel),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _GroupBookingCard extends StatefulWidget {
  const _GroupBookingCard({
    required this.primary,
    required this.siblings,
    required this.onTap,
    required this.onCancel,
  });
  final BookingModel primary;
  final List<BookingModel> siblings;
  final VoidCallback onTap;
  final VoidCallback onCancel;

  @override
  State<_GroupBookingCard> createState() => _GroupBookingCardState();
}

class _GroupBookingCardState extends State<_GroupBookingCard> {
  bool _expanded = false;

  @override
  Widget build(BuildContext context) {
    final b = widget.primary;
    final totalVehicles = widget.siblings.length + 1;
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
      child: GestureDetector(
        onTap: widget.onTap,
        child: Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            border: Border.all(color: context.divider),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '$totalVehicles vehicle${totalVehicles > 1 ? 's' : ''} in this request',
                style: GoogleFonts.inter(
                  color: _secondaryTextColor(context),
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 0.6,
                ),
              ),
              const SizedBox(height: 10),
              Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  Expanded(child: _BookingCardBody(booking: b)),
                  const SizedBox(width: 8),
                  Icon(Icons.chevron_right, color: context.textPrimary, size: 22),
                ],
              ),
              if (b.isCancellableByCustomer || _showQuotationSentHint(b)) ...[
                const SizedBox(height: 12),
                _CancelButton(booking: b, onCancel: widget.onCancel),
              ],
              if (widget.siblings.isNotEmpty) ...[
                const SizedBox(height: 10),
                GestureDetector(
                  onTap: () => setState(() => _expanded = !_expanded),
                  child: Text(
                    _expanded
                        ? 'Hide ${widget.siblings.length} other vehicle${widget.siblings.length > 1 ? 's' : ''}'
                        : 'Show ${widget.siblings.length} other vehicle${widget.siblings.length > 1 ? 's' : ''}',
                    style: GoogleFonts.inter(color: context.textPrimary, fontSize: 12.5, fontWeight: FontWeight.w500),
                  ),
                ),
                if (_expanded) ...[
                  const SizedBox(height: 10),
                  ...widget.siblings.map((s) => _SiblingRow(sibling: s)),
                ],
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _SiblingRow extends StatelessWidget {
  const _SiblingRow({required this.sibling});
  final BookingModel sibling;

  @override
  Widget build(BuildContext context) {
    final timeLabel = sibling.scheduledLabel;
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: context.surface,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  sibling.displayVehicleName,
                  style: GoogleFonts.inter(color: context.textPrimary, fontSize: 13, letterSpacing: -0.1),
                ),
                if (timeLabel.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(timeLabel, style: GoogleFonts.inter(color: _secondaryTextColor(context), fontSize: 11.5)),
                ],
              ],
            ),
          ),
          Text(
            sibling.humanStatus,
            style: GoogleFonts.inter(color: _statusColor(sibling.status, context), fontSize: 11.5, fontWeight: FontWeight.w500),
          ),
        ],
      ),
    );
  }
}

bool _showQuotationSentHint(BookingModel b) => b.status == 'quotation_sent';

class _CancelButton extends StatelessWidget {
  const _CancelButton({required this.booking, required this.onCancel});
  final BookingModel booking;
  final VoidCallback onCancel;

  @override
  Widget build(BuildContext context) {
    final active = booking.isCancellableByCustomer;
    return SizedBox(
      width: double.infinity,
      height: 40,
      child: active
          ? ElevatedButton(
              onPressed: onCancel,
              style: ElevatedButton.styleFrom(
                backgroundColor: TmColors.destructive,
                foregroundColor: TmColors.white,
                elevation: 0,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              ),
              child: Text(
                'Cancel Booking',
                style: GoogleFonts.inter(fontSize: 12.5, fontWeight: FontWeight.w600),
              ),
            )
          : OutlinedButton(
              onPressed: null,
              style: OutlinedButton.styleFrom(
                foregroundColor: context.textTertiary,
                side: BorderSide(color: context.divider, width: 1.2),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                disabledForegroundColor: context.textTertiary,
              ),
              child: Text(
                'Quotation sent — can\'t cancel here',
                style: GoogleFonts.inter(fontSize: 12.5, fontWeight: FontWeight.w600),
              ),
            ),
    );
  }
}

class _BookingCardBody extends StatelessWidget {
  const _BookingCardBody({required this.booking});
  final BookingModel booking;

  @override
  Widget build(BuildContext context) {
    final b = booking;
    final price = b.finalTotal ?? b.computedTotal;
    final scheduled = b.scheduledLabel;
    final showScheduled = b.serviceType == 'schedule' && scheduled.isNotEmpty;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Text(
                b.bookingCode,
                style: GoogleFonts.inter(color: context.textPrimary, fontSize: 15, fontWeight: FontWeight.w500, letterSpacing: -0.2),
              ),
            ),
            const SizedBox(width: 10),
            Text(
              b.humanStatus,
              style: GoogleFonts.inter(color: _statusColor(b.status, context), fontSize: 12.5, fontWeight: FontWeight.w500),
            ),
          ],
        ),
        const SizedBox(height: 3),
        Text(
          '${b.displayVehicleName}  ·  ${_serviceTypeLabel(b.serviceType)}',
          style: GoogleFonts.inter(color: _secondaryTextColor(context), fontSize: 12),
        ),
        const SizedBox(height: 10),
        _AddressRow(label: 'Pickup', address: b.pickupAddress),
        const SizedBox(height: 6),
        _AddressRow(label: 'Drop-off', address: b.dropoffAddress),
        if (showScheduled) ...[
          const SizedBox(height: 10),
          Wrap(
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text('Scheduled: ', style: GoogleFonts.inter(color: _secondaryTextColor(context), fontSize: 12.5)),
              Text(
                scheduled,
                style: GoogleFonts.inter(color: context.textPrimary, fontSize: 12.5, fontWeight: FontWeight.w500),
              ),
            ],
          ),
        ],
        if (price != null || b.distanceKm != null) ...[
          const SizedBox(height: 10),
          Wrap(
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              if (price != null)
                Text(
                  '${_isCommitted(b.status) ? '' : 'Est. '}₱${NumberFormat('#,##0.00', 'en_PH').format(price)}',
                  style: GoogleFonts.inter(color: context.textPrimary, fontSize: 14, fontWeight: FontWeight.w500, letterSpacing: -0.2),
                ),
              if (price != null && b.distanceKm != null)
                Text('   ·   ', style: GoogleFonts.inter(color: context.divider, fontSize: 14)),
              if (b.distanceKm != null)
                Text(
                  '${b.distanceKm!.toStringAsFixed(2)} km',
                  style: GoogleFonts.inter(color: _secondaryTextColor(context), fontSize: 13),
                ),
            ],
          ),
        ],
      ],
    );
  }
}

bool _isCommitted(String status) {
  const committed = {
    'confirmed', 'scheduled_confirmed', 'accepted', 'assigned',
    'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle',
    'on_job', 'arrived_dropoff', 'waiting_verification', 'completed',
  };
  return committed.contains(status);
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
          width: 56,
          child: Text(label, style: GoogleFonts.inter(color: _secondaryTextColor(context), fontSize: 12)),
        ),
        Expanded(
          child: Text(
            address,
            style: GoogleFonts.inter(color: context.textPrimary, fontSize: 12.5, height: 1.4),
          ),
        ),
      ],
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({required this.title, required this.message, required this.showBookNow});
  final String title;
  final String message;
  final bool showBookNow;

  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        const SizedBox(height: 90),
        Center(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 32),
            child: Column(
              children: [
                Text(
                  title,
                  textAlign: TextAlign.center,
                  style: GoogleFonts.inter(color: context.textPrimary, fontSize: 16, fontWeight: FontWeight.w600, letterSpacing: -0.2),
                ),
                const SizedBox(height: 6),
                Text(
                  message,
                  textAlign: TextAlign.center,
                  style: GoogleFonts.inter(color: _secondaryTextColor(context), fontSize: 13, height: 1.5),
                ),
                if (showBookNow) ...[
                  const SizedBox(height: 20),
                  SizedBox(
                    width: 160,
                    height: 44,
                    child: ElevatedButton(
                      onPressed: () => Navigator.pushReplacementNamed(context, '/book-now'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: TmColors.yellow,
                        foregroundColor: TmColors.black,
                        elevation: 0,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      child: Text('Book Now', style: GoogleFonts.inter(fontSize: 14, fontWeight: FontWeight.w600)),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _ErrorRetryState extends StatelessWidget {
  const _ErrorRetryState({required this.onRetry});
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'Unable to load bookings',
              style: GoogleFonts.inter(color: context.textPrimary, fontSize: 15, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 6),
            Text(
              'Please try again.',
              style: GoogleFonts.inter(color: _secondaryTextColor(context), fontSize: 13),
            ),
            const SizedBox(height: 16),
            TextButton(
              onPressed: onRetry,
              child: Text(
                'Try again',
                style: GoogleFonts.inter(color: context.textPrimary, fontSize: 13.5, fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _BookingListSkeleton extends StatelessWidget {
  const _BookingListSkeleton();

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (var i = 0; i < 3; i++) ...[
            SkeletonBox(width: double.infinity, height: 176, borderRadius: BorderRadius.circular(14)),
            const SizedBox(height: 12),
          ],
        ],
      ),
    );
  }
}
