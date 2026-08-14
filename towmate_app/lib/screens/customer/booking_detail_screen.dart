import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../core/theme.dart';
import '../../models/booking_model.dart';
import '../../services/api_service.dart';

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

  static final _money = NumberFormat('#,##0.00', 'en_PH');
  static final _dateTime = DateFormat('MMM d, yyyy  h:mm a');
  static final _dateOnly = DateFormat('MMM d, yyyy');

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

  String _fmt(double? v) => v == null ? '—' : '₱${_money.format(v)}';

  Color _statusBg(String status) => switch (status) {
        'completed'                    => TmColors.black,
        'cancelled' || 'rejected'      => TmColors.grey300,
        'on_the_way' || 'in_progress'  => TmColors.yellow,
        _                              => TmColors.grey100,
      };

  Color _statusFg(String status) => switch (status) {
        'completed'                    => TmColors.white,
        'cancelled' || 'rejected'      => TmColors.grey700,
        'on_the_way' || 'in_progress'  => TmColors.black,
        _                              => TmColors.black,
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      body: SafeArea(
        child: Column(
          children: [
            // ── Top bar ──────────────────────────────────────────────
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

            // ── Body ─────────────────────────────────────────────────
            Expanded(
              child: _loading
                  ? const Center(
                      child: SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                          color: TmColors.yellow,
                          strokeWidth: 2,
                        ),
                      ),
                    )
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
          Text(
            'Could not load booking.',
            style: GoogleFonts.inter(color: context.textTertiary, fontSize: 14),
          ),
          const SizedBox(height: 12),
          GestureDetector(
            onTap: _fetch,
            child: Text(
              'Retry',
              style: GoogleFonts.inter(color: TmColors.yellow, fontSize: 14),
            ),
          ),
        ],
      ),
    );
  }

  Widget _body(BookingModel b) {
    final effectiveTotal = b.finalTotal ?? b.computedTotal;
    final showVat = b.finalTotal != null &&
        b.computedTotal != null &&
        b.finalTotal! > b.computedTotal!;
    final vatAmount = showVat ? (b.finalTotal! - b.computedTotal!) : null;

    // Distance fee derived: total - base - additional
    double? distanceFee;
    if (b.baseRate != null && b.computedTotal != null) {
      final df = b.computedTotal! - b.baseRate! - (b.additionalFee ?? 0);
      if (df >= 0) distanceFee = df;
    }

    final showRebook = b.status == 'completed' || b.status == 'cancelled';

    return SingleChildScrollView(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // ── Header: code + status ───────────────────────────────────
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 24, 24, 20),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        b.bookingCode,
                        style: GoogleFonts.inter(
                          color: context.textPrimary,
                          fontSize: 20,
                          letterSpacing: -0.5,
                        ),
                      ),
                      if (b.formattedDate.isNotEmpty) ...[
                        const SizedBox(height: 2),
                        Text(
                          b.createdAt != null
                              ? _dateTime.format(b.createdAt!.toLocal())
                              : b.formattedDate,
                          style: GoogleFonts.inter(
                            color: context.textSecondary,
                            fontSize: 12,
                            letterSpacing: 0.1,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: _statusBg(b.status),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(
                    b.humanStatus,
                    style: GoogleFonts.inter(
                      color: _statusFg(b.status),
                      fontSize: 12,
                      letterSpacing: 0.2,
                    ),
                  ),
                ),
              ],
            ),
          ),
          _divider(),

          // ── Trip ────────────────────────────────────────────────────
          _section(
            label: 'TRIP',
            rows: [
              _infoRow('Pickup', b.pickupAddress),
              _infoRow('Dropoff', b.dropoffAddress),
              if (b.distanceKm != null)
                _infoRow('Distance', '${b.distanceKm!.toStringAsFixed(1)} km'),
              _infoRow('Vehicle', b.truckTypeName),
              if (b.serviceType != null)
                _infoRow('Type', b.serviceType == 'schedule' ? 'Scheduled' : 'Book Now'),
              if (b.scheduledDate != null)
                _infoRow('Scheduled', _fmtScheduled(b.scheduledDate, b.scheduledTime)),
            ],
          ),
          _divider(),

          // ── Pricing ─────────────────────────────────────────────────
          _section(
            label: 'PRICING',
            rows: [
              if (b.baseRate != null) _infoRow('Base rate', _fmt(b.baseRate)),
              if (distanceFee != null && distanceFee > 0)
                _infoRow('Distance fee', _fmt(distanceFee)),
              if ((b.additionalFee ?? 0) > 0)
                _infoRow('Additional fee', _fmt(b.additionalFee)),
              if (vatAmount != null)
                _infoRow('VAT (12%)', _fmt(vatAmount)),
              _infoRow(
                'Total',
                _fmt(effectiveTotal),
                bold: true,
              ),
            ],
          ),
          _divider(),

          // ── Price History ────────────────────────────────────────────
          if (b.priceChangeLog != null && b.priceChangeLog!.isNotEmpty) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 20, 24, 20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'PRICE HISTORY',
                    style: GoogleFonts.inter(
                      color: context.textSecondary,
                      fontSize: 10,
                      letterSpacing: 0.8,
                    ),
                  ),
                  const SizedBox(height: 12),
                  for (final entry in b.priceChangeLog!)
                    _priceHistoryRow(entry),
                ],
              ),
            ),
          ],

          // ── Crew ────────────────────────────────────────────────────
          if (b.teamLeaderName != null || b.driverName != null) ...[
            _section(
              label: 'CREW',
              rows: [
                if (b.teamLeaderName != null)
                  _infoRow('Team Leader', b.teamLeaderName!),
                if (b.driverName != null)
                  _infoRow('Driver', b.driverName!),
              ],
            ),
            _divider(),
          ],

          // ── Payment ─────────────────────────────────────────────────
          if (b.paymentMethod != null) ...[
            _section(
              label: 'PAYMENT',
              rows: [
                _infoRow('Method', _paymentLabel(b.paymentMethod)),
              ],
            ),
            _divider(),
          ],

          // ── Notes ───────────────────────────────────────────────────
          if (b.pickupNotes != null && b.pickupNotes!.isNotEmpty) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 20, 24, 20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'NOTES',
                    style: GoogleFonts.inter(
                      color: context.textSecondary,
                      fontSize: 10,
                      letterSpacing: 0.8,
                    ),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    b.pickupNotes!,
                    style: GoogleFonts.inter(
                      color: context.textTertiary,
                      fontSize: 13,
                      height: 1.5,
                      letterSpacing: 0.1,
                    ),
                  ),
                ],
              ),
            ),
            _divider(),
          ],

          // ── Photos ──────────────────────────────────────────────────
          if (b.arrivalPhotoUrl != null || b.dropoffPhotoUrl != null) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 20, 24, 20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'PHOTOS',
                    style: GoogleFonts.inter(
                      color: context.textSecondary,
                      fontSize: 10,
                      letterSpacing: 0.8,
                    ),
                  ),
                  const SizedBox(height: 12),
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: [
                        if (b.arrivalPhotoUrl != null)
                          _photoTile('Arrival', b.arrivalPhotoUrl!),
                        if (b.arrivalPhotoUrl != null && b.dropoffPhotoUrl != null)
                          const SizedBox(width: 12),
                        if (b.dropoffPhotoUrl != null)
                          _photoTile('Drop-off', b.dropoffPhotoUrl!),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            _divider(),
          ],

          // ── Timestamps ──────────────────────────────────────────────
          _section(
            label: 'TIMELINE',
            rows: [
              if (b.createdAt != null)
                _infoRow('Booked', _dateTime.format(b.createdAt!.toLocal())),
              if (b.completedAt != null)
                _infoRow('Completed', _dateTime.format(b.completedAt!.toLocal())),
            ],
          ),

          // ── Cancel button (scheduled / scheduled_confirmed) ─────────
          if (b.status == 'scheduled' || b.status == 'scheduled_confirmed') ...[
            _divider(),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 24, 24, 0),
              child: OutlinedButton(
                onPressed: _cancelling
                    ? null
                    : () async {
                        final confirm = await showDialog<bool>(
                          context: context,
                          builder: (_) => AlertDialog(
                            title: Text('Cancel booking?',
                                style: GoogleFonts.inter(fontSize: 16)),
                            content: Text(
                                'Your scheduled booking ${b.bookingCode} will be cancelled.',
                                style: GoogleFonts.inter(fontSize: 14)),
                            actions: [
                              TextButton(
                                  onPressed: () => Navigator.pop(context, false),
                                  child: const Text('No')),
                              TextButton(
                                  onPressed: () => Navigator.pop(context, true),
                                  child: const Text('Yes, cancel',
                                      style: TextStyle(color: Colors.red))),
                            ],
                          ),
                        );
                        if (confirm != true || !mounted) return;
                        setState(() => _cancelling = true);
                        final ok = await ApiService.cancelBooking(b.bookingCode);
                        if (!mounted) return;
                        if (ok) {
                          await _fetch();
                        } else {
                          setState(() => _cancelling = false);
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(content: Text('Failed to cancel. Please try again.')),
                          );
                        }
                      },
                style: OutlinedButton.styleFrom(
                  foregroundColor: Colors.red,
                  side: const BorderSide(color: Colors.red),
                  minimumSize: const Size(double.infinity, 52),
                  shape: const StadiumBorder(),
                ),
                child: _cancelling
                    ? const SizedBox(
                        height: 20, width: 20,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.red))
                    : Text('Cancel Booking',
                        style: GoogleFonts.inter(fontSize: 15)),
              ),
            ),
          ],

          // ── Receipt button ───────────────────────────────────────────
          if (b.status == 'completed') ...[
            _divider(),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 24, 24, 0),
              child: ElevatedButton.icon(
                onPressed: _loadingReceipt ? null : _viewReceipt,
                icon: _loadingReceipt
                    ? const SizedBox(
                        height: 16,
                        width: 16,
                        child: CircularProgressIndicator(strokeWidth: 2, color: TmColors.black),
                      )
                    : const Icon(Icons.receipt_long_rounded, color: TmColors.black, size: 18),
                label: Text(
                  'View Receipt',
                  style: GoogleFonts.inter(color: TmColors.black, fontSize: 15),
                ),
                style: ElevatedButton.styleFrom(
                  backgroundColor: TmColors.yellow,
                  foregroundColor: TmColors.black,
                  minimumSize: const Size(double.infinity, 52),
                  shape: const StadiumBorder(),
                  elevation: 0,
                ),
              ),
            ),
          ],

          // ── Rebook button ────────────────────────────────────────────
          if (showRebook) ...[
            _divider(),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 24, 24, 0),
              child: ElevatedButton(
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
                  shape: const StadiumBorder(),
                  elevation: 0,
                ),
                child: Text(
                  'Book same trip',
                  style: GoogleFonts.inter(color: TmColors.white, fontSize: 15),
                ),
              ),
            ),
          ],

          const SizedBox(height: 48),
        ],
      ),
    );
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  Widget _divider() => Container(height: 0.5, color: context.divider);

  Widget _section({
    required String label,
    required List<Widget> rows,
  }) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 20, 24, 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: GoogleFonts.inter(
              color: context.textSecondary,
              fontSize: 10,
              letterSpacing: 0.8,
            ),
          ),
          const SizedBox(height: 12),
          ...rows,
        ],
      ),
    );
  }

  Widget _infoRow(String label, String value, {bool bold = false}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 100,
            child: Text(
              label,
              style: GoogleFonts.inter(
                color: context.textSecondary,
                fontSize: 13,
                letterSpacing: 0.1,
              ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: GoogleFonts.inter(
                color: context.textPrimary,
                fontSize: 13,
                letterSpacing: 0.1,
                height: 1.4,
                fontWeight: bold ? FontWeight.w600 : FontWeight.normal,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _photoTile(String label, String url) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: GoogleFonts.inter(
            color: context.textSecondary,
            fontSize: 11,
            letterSpacing: 0.3,
          ),
        ),
        const SizedBox(height: 6),
        ClipRRect(
          borderRadius: BorderRadius.circular(8),
          child: Image.network(
            url,
            width: 160,
            height: 110,
            fit: BoxFit.cover,
            errorBuilder: (_, __, ___) => Container(
              width: 160,
              height: 110,
              color: context.surface,
              alignment: Alignment.center,
              child: Text(
                'Unavailable',
                style: GoogleFonts.inter(
                  color: context.textSecondary,
                  fontSize: 11,
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }

  String _fmtScheduled(String? date, String? time) {
    if (date == null) return '—';
    final dt = DateTime.tryParse(date);
    final dateStr = dt != null ? _dateOnly.format(dt) : date;
    if (time == null) return dateStr;
    return '$dateStr  $time';
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

    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                '₱${_money.format(oldP)}',
                style: GoogleFonts.inter(
                  color: context.textTertiary,
                  fontSize: 13,
                  decoration: TextDecoration.lineThrough,
                ),
              ),
              if (delta != 0) ...[
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  child: Text(
                    '$deltaSign₱${_money.format(delta.abs())}',
                    style: GoogleFonts.inter(
                      color: delta > 0 ? TmColors.error : TmColors.success,
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ] else
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  child: Icon(Icons.arrow_forward_rounded, size: 14, color: context.textTertiary),
                ),
              Text(
                '₱${_money.format(newP)}',
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
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
            Text(
              tsStr,
              style: GoogleFonts.inter(
                color: context.textTertiary,
                fontSize: 11,
              ),
            ),
          ],
        ],
      ),
    );
  }

  String _paymentLabel(String? method) => switch (method) {
        'cash'          => 'Cash',
        'gcash'         => 'GCash',
        'bank_transfer' => 'Bank Transfer',
        _               => method ?? '—',
      };
}
