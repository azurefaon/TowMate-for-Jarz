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

  Color _statusColor(String status) {
    const completed = {'completed'};
    const muted = {'cancelled', 'rejected'};
    if (completed.contains(status)) return TmColors.grey500;
    if (muted.contains(status)) return TmColors.grey300;
    return TmColors.black;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: TmColors.white,
      drawer: TmDrawer(currentRoute: '/my-bookings', isLoggedIn: true, name: _name),
      body: SafeArea(
        child: Column(
          children: [
            // ── Top bar ──────────────────────────────────────────────────
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
              decoration: const BoxDecoration(
                border: Border(bottom: BorderSide(color: TmColors.grey300, width: 0.5)),
              ),
              child: Row(
                children: [
                  IconButton(
                    icon: const Icon(Icons.menu_rounded, color: TmColors.grey700),
                    onPressed: () => _scaffoldKey.currentState?.openDrawer(),
                    tooltip: 'Menu',
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(),
                  ),
                  const Expanded(child: SizedBox()),
                  Text(
                    'My Bookings',
                    style: GoogleFonts.inter(
                      color: TmColors.black,
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
                          color: TmColors.grey500,
                          fontSize: 14,
                          letterSpacing: 0.1,
                        ),
                      ),
                    )
                  : RefreshIndicator(
                      color: TmColors.black,
                      onRefresh: () => _load(refresh: true),
                      child: _bookings.isEmpty
                          ? _EmptyState()
                          : ListView.builder(
                              physics: const AlwaysScrollableScrollPhysics(),
                              itemCount: _bookings.length + (_hasMore ? 1 : 0) + (_error != null ? 1 : 0),
                              itemBuilder: (_, i) {
                                if (_error != null && i == 0) {
                                  return _ErrorBanner(message: _error!);
                                }
                                final offset = _error != null ? 1 : 0;
                                if (i - offset < _bookings.length) {
                                  final b = _bookings[i - offset];
                                  return InkWell(
                                    onTap: () => Navigator.pushNamed(
                                      context,
                                      '/booking-detail',
                                      arguments: b.bookingCode,
                                    ),
                                    child: _BookingRow(
                                      booking: b,
                                      statusColor: _statusColor(b.status),
                                    ),
                                  );
                                }
                                // Load more button
                                return Padding(
                                  padding: const EdgeInsets.symmetric(vertical: 20),
                                  child: Center(
                                    child: _loadingMore
                                        ? const SizedBox(
                                            width: 20,
                                            height: 20,
                                            child: CircularProgressIndicator(
                                              color: TmColors.black,
                                              strokeWidth: 2,
                                            ),
                                          )
                                        : GestureDetector(
                                            onTap: _loadMore,
                                            child: Text(
                                              'Load more',
                                              style: GoogleFonts.inter(
                                                color: TmColors.grey700,
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

// ── Booking row ─────────────────────────────────────────────────────────────

class _BookingRow extends StatelessWidget {
  const _BookingRow({required this.booking, required this.statusColor});
  final BookingModel booking;
  final Color statusColor;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 20, 24, 20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Booking code + status
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Text(
                      booking.bookingCode,
                      style: GoogleFonts.inter(
                        color: TmColors.black,
                        fontSize: 15,
                        letterSpacing: -0.2,
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Text(
                    booking.humanStatus,
                    style: GoogleFonts.inter(
                      color: statusColor,
                      fontSize: 13,
                      letterSpacing: 0.1,
                    ),
                  ),
                ],
              ),

              // Truck type + date
              const SizedBox(height: 4),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      booking.truckTypeName,
                      style: GoogleFonts.inter(
                        color: TmColors.grey500,
                        fontSize: 12,
                        letterSpacing: 0.1,
                      ),
                    ),
                  ),
                  if (booking.formattedDate.isNotEmpty)
                    Text(
                      booking.formattedDate,
                      style: GoogleFonts.inter(
                        color: TmColors.grey500,
                        fontSize: 12,
                        letterSpacing: 0.1,
                      ),
                    ),
                ],
              ),

              // Pickup
              const SizedBox(height: 12),
              _AddressRow(label: 'Pickup', address: booking.pickupAddress),
              const SizedBox(height: 4),
              _AddressRow(label: 'Drop-off', address: booking.dropoffAddress),

              // Price + distance
              if (booking.computedTotal != null || booking.distanceKm != null) ...[
                const SizedBox(height: 12),
                Row(
                  children: [
                    if (booking.computedTotal != null)
                      Text(
                        '₱${booking.computedTotal!.toStringAsFixed(2)}',
                        style: GoogleFonts.inter(
                          color: TmColors.black,
                          fontSize: 14,
                          letterSpacing: -0.2,
                        ),
                      ),
                    if (booking.computedTotal != null && booking.distanceKm != null)
                      Text(
                        '   ·   ',
                        style: GoogleFonts.inter(
                          color: TmColors.grey300,
                          fontSize: 14,
                        ),
                      ),
                    if (booking.distanceKm != null)
                      Text(
                        '${booking.distanceKm!.toStringAsFixed(2)} km',
                        style: GoogleFonts.inter(
                          color: TmColors.grey500,
                          fontSize: 13,
                          letterSpacing: 0.1,
                        ),
                      ),
                  ],
                ),
              ],
            ],
          ),
        ),
        Container(height: 0.5, color: TmColors.grey300),
      ],
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
          width: 56,
          child: Text(
            label,
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 12,
              letterSpacing: 0.1,
            ),
          ),
        ),
        Expanded(
          child: Text(
            address,
            style: GoogleFonts.inter(
              color: TmColors.grey700,
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

// ── Supporting widgets ───────────────────────────────────────────────────────

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
                  color: TmColors.black,
                  fontSize: 16,
                  letterSpacing: -0.2,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'Tap Book Now to request a tow.',
                style: GoogleFonts.inter(
                  color: TmColors.grey500,
                  fontSize: 13,
                  letterSpacing: 0.1,
                ),
              ),
              const SizedBox(height: 24),
              GestureDetector(
                onTap: () => Navigator.pushReplacementNamed(context, '/book-now'),
                child: Text(
                  'Book Now →',
                  style: GoogleFonts.inter(
                    color: TmColors.black,
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
          color: TmColors.grey500,
          fontSize: 13,
          letterSpacing: 0.1,
        ),
      ),
    );
  }
}
