import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/booking_model.dart';
import '../../services/api_service.dart';
import '../../widgets/tm_drawer.dart';

class MyBookingsScreen extends StatefulWidget {
  const MyBookingsScreen({super.key});

  @override
  State<MyBookingsScreen> createState() => _MyBookingsScreenState();
}

class _MyBookingsScreenState extends State<MyBookingsScreen> {
  final _scaffoldKey = GlobalKey<ScaffoldState>();

  List<BookingModel> _bookings = [];
  bool _loading = true;
  bool _loadingMore = false;
  bool _hasMore = false;
  int _page = 1;
  String? _error;
  String? _name;

  @override
  void initState() {
    super.initState();
    ApiService.getUserName().then((n) {
      if (mounted) setState(() => _name = n);
    });
    _load();
  }

  Future<void> _load({bool refresh = false}) async {
    if (refresh) {
      setState(() {
        _loading = true;
        _page = 1;
        _bookings = [];
        _error = null;
      });
    }
    final result = await ApiService.fetchBookingHistory(page: _page);
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (result['success'] == true) {
        final incoming = result['bookings'] as List<BookingModel>;
        _bookings = refresh ? incoming : [..._bookings, ...incoming];
        _hasMore = result['hasMore'] as bool;
      } else {
        _error = 'Could not load bookings. Pull down to retry.';
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

  // Builds the display list: groups bookings with the same group_code into one entry.
  // Each entry is either a single BookingModel (solo) or a list [primary, ...siblings].
  List<Object> _buildDisplayList() {
    final seen = <String>{};
    final result = <Object>[];
    for (final b in _bookings) {
      if (b.groupCode == null) {
        result.add(b);
      } else {
        if (!seen.contains(b.groupCode)) {
          seen.add(b.groupCode!);
          final group = _bookings
              .where((x) => x.groupCode == b.groupCode)
              .toList();
          // Primary = book_now (service_type), siblings = the rest
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
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text('Cancel Booking',
            style: GoogleFonts.inter(fontSize: 16)),
        content: Text('Cancel booking ${b.bookingCode}?',
            style: GoogleFonts.inter(fontSize: 14)),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text('No', style: GoogleFonts.inter()),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text('Yes, cancel',
                style: GoogleFonts.inter(color: Colors.red)),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    final ok = await ApiService.cancelBooking(b.bookingCode);
    if (!mounted) return;
    if (ok) {
      _load(refresh: true);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Failed to cancel booking.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final displayList = _buildDisplayList();

    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: context.bg,
      drawer: TmDrawer(currentRoute: '/my-bookings', isLoggedIn: true, name: _name),
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
                    icon: Icon(Icons.menu_rounded, color: context.textTertiary),
                    onPressed: () => _scaffoldKey.currentState?.openDrawer(),
                    tooltip: 'Menu',
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(),
                  ),
                  const Expanded(child: SizedBox()),
                  Text(
                    'My Bookings',
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 16,
                      letterSpacing: -0.3,
                    ),
                  ),
                  const Expanded(child: SizedBox()),
                  const SizedBox(width: 40),
                ],
              ),
            ),

            // ── Body ─────────────────────────────────────────────────────
            Expanded(
              child: _loading
                  ? Center(
                      child: Text(
                        'Loading bookings...',
                        style: GoogleFonts.inter(
                          color: context.textSecondary,
                          fontSize: 14,
                          letterSpacing: 0.1,
                        ),
                      ),
                    )
                  : RefreshIndicator(
                      color: context.textPrimary,
                      onRefresh: () => _load(refresh: true),
                      child: _bookings.isEmpty
                          ? _EmptyState()
                          : ListView.builder(
                              physics: const AlwaysScrollableScrollPhysics(),
                              itemCount:
                                  displayList.length +
                                  (_hasMore ? 1 : 0) +
                                  (_error != null ? 1 : 0),
                              itemBuilder: (_, i) {
                                if (_error != null && i == 0) {
                                  return _ErrorBanner(message: _error!);
                                }
                                final offset = _error != null ? 1 : 0;
                                final idx = i - offset;

                                if (idx < displayList.length) {
                                  final item = displayList[idx];
                                  if (item is BookingModel) {
                                    return _BookingCard(
                                      booking: item,
                                      onCancel: () => _cancelBooking(item),
                                      onTap: () => Navigator.pushNamed(
                                        context,
                                        '/booking-detail',
                                        arguments: item.bookingCode,
                                      ),
                                    );
                                  } else {
                                    final group = item as List<BookingModel>;
                                    final primary = group.first;
                                    final siblings = group.skip(1).toList();
                                    return _GroupBookingCard(
                                      primary: primary,
                                      siblings: siblings,
                                      onCancel: () => _cancelBooking(primary),
                                      onTap: () => Navigator.pushNamed(
                                        context,
                                        '/booking-detail',
                                        arguments: primary.bookingCode,
                                      ),
                                    );
                                  }
                                }

                                return Padding(
                                  padding: const EdgeInsets.symmetric(vertical: 20),
                                  child: Center(
                                    child: _loadingMore
                                        ? SizedBox(
                                            width: 20,
                                            height: 20,
                                            child: CircularProgressIndicator(
                                              color: context.textPrimary,
                                              strokeWidth: 2,
                                            ),
                                          )
                                        : GestureDetector(
                                            onTap: _loadMore,
                                            child: Text(
                                              'Load more',
                                              style: GoogleFonts.inter(
                                                color: context.textTertiary,
                                                fontSize: 13,
                                                letterSpacing: 0.1,
                                              ),
                                            ),
                                          ),
                                  ),
                                );
                              },
                            ),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

// ── Cancel button helpers ────────────────────────────────────────────────────

bool _canCancel(BookingModel b) =>
    b.status == 'requested' || b.status == 'scheduled';

bool _showGrayCancel(BookingModel b) => b.status == 'quotation_sent';

bool _showCancel(BookingModel b) => _canCancel(b) || _showGrayCancel(b);

// ── Solo booking card ────────────────────────────────────────────────────────

class _BookingCard extends StatelessWidget {
  const _BookingCard({
    required this.booking,
    required this.onCancel,
    required this.onTap,
  });
  final BookingModel booking;
  final VoidCallback onCancel;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        InkWell(
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(24, 20, 24, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _topRow(context),
                const SizedBox(height: 4),
                _subtitleRow(context),
                const SizedBox(height: 12),
                _AddressRow(label: 'Pickup', address: booking.pickupAddress),
                const SizedBox(height: 4),
                _AddressRow(label: 'Drop-off', address: booking.dropoffAddress),
                if ((booking.finalTotal ?? booking.computedTotal) != null ||
                    booking.distanceKm != null) ...[
                  const SizedBox(height: 12),
                  _priceRow(context),
                ],
                if (_showCancel(booking)) ...[
                  const SizedBox(height: 12),
                  _cancelButton(context),
                ],
                const SizedBox(height: 20),
              ],
            ),
          ),
        ),
        Container(height: 0.5, color: context.divider),
      ],
    );
  }

  Widget _topRow(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Text(
            booking.bookingCode,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 15,
              letterSpacing: -0.2,
            ),
          ),
        ),
        const SizedBox(width: 12),
        Text(
          booking.humanStatus,
          style: GoogleFonts.inter(
            color: _statusColor(booking.status, context),
            fontSize: 13,
            letterSpacing: 0.1,
          ),
        ),
      ],
    );
  }

  Widget _subtitleRow(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Text(
            booking.truckTypeName,
            style: GoogleFonts.inter(
              color: context.textSecondary,
              fontSize: 12,
              letterSpacing: 0.1,
            ),
          ),
        ),
        if (booking.formattedDate.isNotEmpty)
          Text(
            booking.formattedDate,
            style: GoogleFonts.inter(
              color: context.textSecondary,
              fontSize: 12,
              letterSpacing: 0.1,
            ),
          ),
      ],
    );
  }

  Widget _priceRow(BuildContext context) {
    final price = booking.finalTotal ?? booking.computedTotal;
    return Row(
      children: [
        if (price != null)
          Text(
            '₱${price.toStringAsFixed(2)}',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 14,
              letterSpacing: -0.2,
            ),
          ),
        if (price != null && booking.distanceKm != null)
          Text('   ·   ',
              style: GoogleFonts.inter(color: context.divider, fontSize: 14)),
        if (booking.distanceKm != null)
          Text(
            '${booking.distanceKm!.toStringAsFixed(2)} km',
            style: GoogleFonts.inter(
              color: context.textSecondary,
              fontSize: 13,
              letterSpacing: 0.1,
            ),
          ),
      ],
    );
  }

  Widget _cancelButton(BuildContext context) {
    final active = _canCancel(booking);
    return GestureDetector(
      onTap: active ? onCancel : null,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          border: Border.all(
            color: active ? Colors.red.shade300 : context.divider,
          ),
          borderRadius: BorderRadius.circular(20),
        ),
        child: Text(
          active ? 'Cancel Booking' : 'Quotation Sent — Can\'t Cancel',
          style: GoogleFonts.inter(
            color: active ? Colors.red.shade600 : context.textTertiary,
            fontSize: 11,
            letterSpacing: 0.1,
          ),
        ),
      ),
    );
  }
}

// ── Group booking card (primary + expandable siblings) ───────────────────────

class _GroupBookingCard extends StatefulWidget {
  const _GroupBookingCard({
    required this.primary,
    required this.siblings,
    required this.onCancel,
    required this.onTap,
  });
  final BookingModel primary;
  final List<BookingModel> siblings;
  final VoidCallback onCancel;
  final VoidCallback onTap;

  @override
  State<_GroupBookingCard> createState() => _GroupBookingCardState();
}

class _GroupBookingCardState extends State<_GroupBookingCard> {
  bool _expanded = false;

  @override
  Widget build(BuildContext context) {
    final b = widget.primary;
    final price = b.finalTotal ?? b.computedTotal;

    return Column(
      children: [
        InkWell(
          onTap: widget.onTap,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(24, 20, 24, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Booking code + status
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Text(
                        b.bookingCode,
                        style: GoogleFonts.inter(
                          color: context.textPrimary,
                          fontSize: 15,
                          letterSpacing: -0.2,
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Text(
                      b.humanStatus,
                      style: GoogleFonts.inter(
                        color: _statusColor(b.status, context),
                        fontSize: 13,
                        letterSpacing: 0.1,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 4),
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        '${b.truckTypeName}  ·  Book Now',
                        style: GoogleFonts.inter(
                          color: context.textSecondary,
                          fontSize: 12,
                        ),
                      ),
                    ),
                    if (b.formattedDate.isNotEmpty)
                      Text(
                        b.formattedDate,
                        style: GoogleFonts.inter(
                          color: context.textSecondary,
                          fontSize: 12,
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 12),
                _AddressRow(label: 'Pickup', address: b.pickupAddress),
                const SizedBox(height: 4),
                _AddressRow(label: 'Drop-off', address: b.dropoffAddress),
                if (price != null || b.distanceKm != null) ...[
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      if (price != null)
                        Text(
                          '₱${price.toStringAsFixed(2)}',
                          style: GoogleFonts.inter(
                            color: context.textPrimary,
                            fontSize: 14,
                            letterSpacing: -0.2,
                          ),
                        ),
                      if (price != null && b.distanceKm != null)
                        Text('   ·   ',
                            style: GoogleFonts.inter(
                                color: context.divider, fontSize: 14)),
                      if (b.distanceKm != null)
                        Text(
                          '${b.distanceKm!.toStringAsFixed(2)} km',
                          style: GoogleFonts.inter(
                            color: context.textSecondary,
                            fontSize: 13,
                          ),
                        ),
                    ],
                  ),
                ],

                // Cancel button for primary
                if (_showCancel(b)) ...[
                  const SizedBox(height: 12),
                  GestureDetector(
                    onTap: _canCancel(b) ? widget.onCancel : null,
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 12, vertical: 6),
                      decoration: BoxDecoration(
                        border: Border.all(
                          color: _canCancel(b)
                              ? Colors.red.shade300
                              : context.divider,
                        ),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text(
                        _canCancel(b)
                            ? 'Cancel Booking'
                            : 'Quotation Sent — Can\'t Cancel',
                        style: GoogleFonts.inter(
                          color: _canCancel(b)
                              ? Colors.red.shade600
                              : context.textTertiary,
                          fontSize: 11,
                        ),
                      ),
                    ),
                  ),
                ],

                // Expand toggle for siblings
                const SizedBox(height: 12),
                GestureDetector(
                  onTap: () => setState(() => _expanded = !_expanded),
                  child: Row(
                    children: [
                      Text(
                        _expanded
                            ? '▲ Hide scheduled vehicle${widget.siblings.length > 1 ? 's' : ''}'
                            : '+${widget.siblings.length} scheduled vehicle${widget.siblings.length > 1 ? 's' : ''} ▼',
                        style: GoogleFonts.inter(
                          color: context.textSecondary,
                          fontSize: 12,
                          letterSpacing: 0.1,
                        ),
                      ),
                    ],
                  ),
                ),

                // Sibling list (expanded)
                if (_expanded) ...[
                  const SizedBox(height: 10),
                  ...widget.siblings.map((s) => _SiblingRow(sibling: s)),
                ],

                const SizedBox(height: 20),
              ],
            ),
          ),
        ),
        Container(height: 0.5, color: context.divider),
      ],
    );
  }
}

// ── Sibling row (inside expanded group card) ─────────────────────────────────

class _SiblingRow extends StatelessWidget {
  const _SiblingRow({required this.sibling});
  final BookingModel sibling;

  @override
  Widget build(BuildContext context) {
    final timeLabel = _formatSchedTime(sibling.scheduledDate, sibling.scheduledTime);
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: context.divider.withValues(alpha: 0.3),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  sibling.truckTypeName,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 13,
                    letterSpacing: -0.1,
                  ),
                ),
                if (timeLabel.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    timeLabel,
                    style: GoogleFonts.inter(
                      color: context.textSecondary,
                      fontSize: 11,
                    ),
                  ),
                ],
              ],
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
            decoration: BoxDecoration(
              color: context.divider,
              borderRadius: BorderRadius.circular(12),
            ),
            child: Text(
              sibling.humanStatus,
              style: GoogleFonts.inter(
                color: context.textSecondary,
                fontSize: 11,
              ),
            ),
          ),
        ],
      ),
    );
  }

  String _formatSchedTime(String? date, String? time) {
    if (date == null) return '';
    try {
      final d = DateTime.parse(date);
      const months = [
        'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'
      ];
      final datePart = '${months[d.month - 1]} ${d.day}, ${d.year}';
      if (time == null) return datePart;
      final parts = time.split(':');
      if (parts.length < 2) return datePart;
      final h = int.tryParse(parts[0]) ?? 0;
      final m = int.tryParse(parts[1]) ?? 0;
      final ampm = h >= 12 ? 'PM' : 'AM';
      final h12 = h % 12 == 0 ? 12 : h % 12;
      return '$datePart · $h12:${m.toString().padLeft(2, '0')} $ampm';
    } catch (_) {
      return date;
    }
  }
}

// ── Shared helpers ────────────────────────────────────────────────────────────

Color _statusColor(String status, BuildContext context) {
  const completed = {'completed'};
  const muted = {'cancelled', 'rejected'};
  if (completed.contains(status)) return context.textSecondary;
  if (muted.contains(status)) return context.divider;
  return context.textPrimary;
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
          child: Text(
            label,
            style: GoogleFonts.inter(
              color: context.textSecondary,
              fontSize: 12,
              letterSpacing: 0.1,
            ),
          ),
        ),
        Expanded(
          child: Text(
            address,
            style: GoogleFonts.inter(
              color: context.textTertiary,
              fontSize: 12,
              letterSpacing: 0.1,
              height: 1.4,
            ),
          ),
        ),
      ],
    );
  }
}

// ── Supporting widgets ────────────────────────────────────────────────────────

class _EmptyState extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return ListView(
      children: [
        const SizedBox(height: 80),
        Center(
          child: Column(
            children: [
              Text(
                'No bookings yet.',
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 16,
                  letterSpacing: -0.2,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'Tap Book Now to request a tow.',
                style: GoogleFonts.inter(
                  color: context.textSecondary,
                  fontSize: 13,
                  letterSpacing: 0.1,
                ),
              ),
              const SizedBox(height: 24),
              GestureDetector(
                onTap: () =>
                    Navigator.pushReplacementNamed(context, '/book-now'),
                child: Text(
                  'Book Now →',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 14,
                    letterSpacing: 0.1,
                  ),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 0),
      child: Text(
        message,
        style: GoogleFonts.inter(
          color: context.textSecondary,
          fontSize: 13,
          letterSpacing: 0.1,
        ),
      ),
    );
  }
}
