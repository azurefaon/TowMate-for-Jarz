import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/booking_model.dart';
import '../../models/quotation_model.dart';
import '../../services/api_service.dart';
import '../../widgets/tm_drawer.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver {
  BookingModel? _booking;
  QuotationModel? _quotation;
  bool _loading = true;
  String? _name;
  final _scaffoldKey = GlobalKey<ScaffoldState>();
  Timer? _pollTimer;

  int? _lastSeenQuotationId;
  String? _lastSeenStatus;
  bool _hasUnread = false;
  bool _initialLoad = true;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    ApiService.getUserName().then((n) {
      if (mounted) setState(() => _name = n);
    });
    _loadData();
    _pollTimer = Timer.periodic(const Duration(seconds: 30), (_) => _loadData());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _pollTimer?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _loadData();
  }

  Future<void> _loadData() async {
    final results = await Future.wait<Object?>([
      ApiService.fetchCurrentBooking(),
      ApiService.fetchPendingQuotation(),
    ]);
    if (!mounted) return;

    final newBooking = results[0] as BookingModel?;
    final newQuotation = results[1] as QuotationModel?;

    if (!_initialLoad) {
      final newQuotId = newQuotation?.id;
      final newStatus = newBooking?.status;

      if (newQuotId != null && newQuotId != _lastSeenQuotationId) {
        _notify('New quotation received — tap to review.');
      } else if (newStatus != null && newStatus != _lastSeenStatus) {
        _notify('Booking status updated: ${newBooking!.humanStatus}');
      }
    }

    setState(() {
      _booking = newBooking;
      _quotation = newQuotation;
      _loading = false;
      _lastSeenQuotationId = newQuotation?.id ?? _lastSeenQuotationId;
      _lastSeenStatus = newBooking?.status ?? _lastSeenStatus;
      _initialLoad = false;
    });
  }

  void _notify(String message) {
    setState(() => _hasUnread = true);
    ScaffoldMessenger.of(context).showMaterialBanner(
      MaterialBanner(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        content: Text(
          message,
          style: GoogleFonts.inter(color: TmColors.black, fontSize: 13),
        ),
        backgroundColor: TmColors.yellow,
        leading: const Icon(Icons.notifications_rounded, color: TmColors.black, size: 20),
        actions: [
          TextButton(
            onPressed: () {
              ScaffoldMessenger.of(context).hideCurrentMaterialBanner();
              setState(() => _hasUnread = false);
            },
            child: Text(
              'Dismiss',
              style: GoogleFonts.inter(color: TmColors.black, fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }

  void _onBellTap() {
    ScaffoldMessenger.of(context).hideCurrentMaterialBanner();
    setState(() => _hasUnread = false);
  }

  String get _greeting {
    final h = DateTime.now().hour;
    if (h < 12) return 'Good morning';
    if (h < 17) return 'Good afternoon';
    return 'Good evening';
  }

  Future<void> _openQuotation() async {
    await Navigator.pushNamed(context, '/quotation', arguments: _quotation);
    _loadData();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: context.bg,
      drawer: TmDrawer(
        currentRoute: '/home',
        isLoggedIn: true,
        name: _name,
      ),
      body: SafeArea(
        child: Column(
          children: [
            _TopBar(
              onMenuTap: () => _scaffoldKey.currentState?.openDrawer(),
              hasUnread: _hasUnread,
              onBellTap: _onBellTap,
            ),
            Expanded(
              child: _loading
                  ? _LoadingState()
                  : SingleChildScrollView(
                      padding: const EdgeInsets.all(24),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          RichText(
                            text: TextSpan(
                              children: [
                                TextSpan(
                                  text: _greeting,
                                  style: GoogleFonts.inter(
                                    color: context.textSecondary,
                                    fontSize: 14,
                                  ),
                                ),
                                if (_name != null && _name!.isNotEmpty)
                                  TextSpan(
                                    text: ', ${_name!.split(' ').first}',
                                    style: GoogleFonts.inter(
                                      color: context.textPrimary,
                                      fontSize: 14,
                                    ),
                                  ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 20),
                          ElevatedButton.icon(
                            onPressed: () =>
                                Navigator.pushNamed(context, '/book-now'),
                            icon: const Icon(Icons.local_shipping_rounded),
                            label: const Text('Book a Tow'),
                            style: ElevatedButton.styleFrom(
                              backgroundColor: TmColors.yellow,
                              foregroundColor: TmColors.black,
                              minimumSize: const Size(double.infinity, 52),
                              shape: const StadiumBorder(),
                              elevation: 0,
                              textStyle: GoogleFonts.inter(fontSize: 15),
                            ),
                          ),
                          const SizedBox(height: 24),
                          if (_quotation != null)
                            _QuotationReadyCard(
                              quotation: _quotation!,
                              onTap: _openQuotation,
                            )
                          else if (_booking != null)
                            _ActiveBookingCard(
                              booking: _booking!,
                              onRefresh: _loadData,
                            )
                          else
                            Text(
                              'No active bookings',
                              style: GoogleFonts.inter(
                                color: context.textSecondary,
                                fontSize: 13,
                                letterSpacing: 0.1,
                              ),
                            ),

                          // ── Services section ──────────────────────────
                          const SizedBox(height: 32),
                          Container(height: 0.5, color: context.divider),
                          const SizedBox(height: 28),
                          Text(
                            'Our Services',
                            style: GoogleFonts.inter(
                              color: context.textPrimary,
                              fontSize: 20,
                              letterSpacing: -0.6,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'Solutions for every situation',
                            style: GoogleFonts.inter(
                              color: context.textSecondary,
                              fontSize: 12,
                              letterSpacing: 0.1,
                            ),
                          ),
                          const SizedBox(height: 16),
                          Row(
                            children: [
                              Expanded(child: _HomeServiceChip(icon: Icons.local_shipping_rounded, label: 'Towing')),
                              const SizedBox(width: 10),
                              Expanded(child: _HomeServiceChip(icon: Icons.build_rounded, label: 'Roadside Help')),
                              const SizedBox(width: 10),
                              Expanded(child: _HomeServiceChip(icon: Icons.car_repair_rounded, label: 'Recovery')),
                            ],
                          ),

                          // ── Vehicle types section ─────────────────────
                          const SizedBox(height: 28),
                          Text(
                            'Vehicle Types',
                            style: GoogleFonts.inter(
                              color: context.textPrimary,
                              fontSize: 20,
                              letterSpacing: -0.6,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'We tow any type of vehicle',
                            style: GoogleFonts.inter(
                              color: context.textSecondary,
                              fontSize: 12,
                              letterSpacing: 0.1,
                            ),
                          ),
                          const SizedBox(height: 16),
                          Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: const [
                              _HomeVehicleChip(icon: Icons.directions_car_rounded, label: 'Sedan / Hatchback'),
                              _HomeVehicleChip(icon: Icons.directions_car_filled_rounded, label: 'SUV / Crossover'),
                              _HomeVehicleChip(icon: Icons.local_shipping_outlined, label: 'Pickup Truck'),
                              _HomeVehicleChip(icon: Icons.airport_shuttle_rounded, label: 'Van / MPV'),
                              _HomeVehicleChip(icon: Icons.two_wheeler_rounded, label: 'Motorcycle'),
                              _HomeVehicleChip(icon: Icons.directions_bus_rounded, label: 'Bus'),
                              _HomeVehicleChip(icon: Icons.local_shipping_rounded, label: 'Cargo Truck'),
                              _HomeVehicleChip(icon: Icons.directions_bus_filled_rounded, label: 'Jeepney'),
                            ],
                          ),
                          const SizedBox(height: 32),
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

// ─── Top bar ───────────────────────────────────────────────────────────────

class _TopBar extends StatelessWidget {
  const _TopBar({
    required this.onMenuTap,
    required this.hasUnread,
    required this.onBellTap,
  });
  final VoidCallback onMenuTap;
  final bool hasUnread;
  final VoidCallback onBellTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: context.divider, width: 0.5)),
      ),
      child: Row(
        children: [
          IconButton(
            icon: Icon(Icons.menu_rounded, color: context.textTertiary),
            onPressed: onMenuTap,
            tooltip: 'Menu',
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
          GestureDetector(
            onTap: onBellTap,
            child: Padding(
              padding: const EdgeInsets.all(8),
              child: Stack(
                clipBehavior: Clip.none,
                children: [
                  Icon(
                    hasUnread
                        ? Icons.notifications_rounded
                        : Icons.notifications_outlined,
                    color: context.textTertiary,
                    size: 22,
                  ),
                  if (hasUnread)
                    Positioned(
                      right: -2,
                      top: -2,
                      child: Container(
                        width: 8,
                        height: 8,
                        decoration: const BoxDecoration(
                          color: TmColors.error,
                          shape: BoxShape.circle,
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ─── Loading ───────────────────────────────────────────────────────────────

class _LoadingState extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Text(
        'Loading...',
        style: GoogleFonts.inter(
          color: context.textSecondary,
          fontSize: 14,
          letterSpacing: 0.1,
        ),
      ),
    );
  }
}

// ─── Quotation ready card ──────────────────────────────────────────────────

class _QuotationReadyCard extends StatelessWidget {
  const _QuotationReadyCard({required this.quotation, required this.onTap});
  final QuotationModel quotation;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Quotation Ready',
          style: GoogleFonts.inter(
            color: context.textSecondary,
            fontSize: 12,
            letterSpacing: 0.6,
          ),
        ),
        const SizedBox(height: 20),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            border: Border.all(color: context.divider),
            borderRadius: BorderRadius.circular(8),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    quotation.quotationNumber,
                    style: GoogleFonts.inter(
                      color: context.textSecondary,
                      fontSize: 12,
                      letterSpacing: 0.4,
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: TmColors.yellow.withValues(alpha: 0.15),
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(
                      'Awaiting Response',
                      style: GoogleFonts.inter(
                        color: const Color(0xFF9A7D00),
                        fontSize: 11,
                        letterSpacing: 0.3,
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              Text(
                '₱${quotation.estimatedPrice.toStringAsFixed(0)}',
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 28,
                  letterSpacing: -0.6,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                '${quotation.truckTypeName}  ·  ${quotation.distanceKm.toStringAsFixed(1)} km',
                style: GoogleFonts.inter(
                  color: context.textSecondary,
                  fontSize: 12,
                  letterSpacing: 0.1,
                ),
              ),
              const SizedBox(height: 16),
              _AddressLine(label: 'Pickup', address: quotation.pickupAddress),
              const SizedBox(height: 8),
              _AddressLine(label: 'Dropoff', address: quotation.dropoffAddress),
              const SizedBox(height: 16),
              GestureDetector(
                onTap: onTap,
                child: Text(
                  'Review & Accept →',
                  style: GoogleFonts.inter(
                    color: TmColors.yellow,
                    fontSize: 14,
                    letterSpacing: 0.2,
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

// ─── Active booking ────────────────────────────────────────────────────────

class _ActiveBookingCard extends StatelessWidget {
  const _ActiveBookingCard({required this.booking, required this.onRefresh});
  final BookingModel booking;
  final VoidCallback onRefresh;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Current Booking',
          style: GoogleFonts.inter(
            color: context.textSecondary,
            fontSize: 12,
            letterSpacing: 0.6,
          ),
        ),
        const SizedBox(height: 20),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            border: Border.all(color: context.divider),
            borderRadius: BorderRadius.circular(8),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text(
                    booking.bookingCode,
                    style: GoogleFonts.inter(
                      color: context.textSecondary,
                      fontSize: 12,
                      letterSpacing: 0.4,
                    ),
                  ),
                  Text(
                    booking.humanStatus,
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 12,
                      letterSpacing: 0.3,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              _AddressLine(label: 'Pickup', address: booking.pickupAddress),
              const SizedBox(height: 8),
              _AddressLine(label: 'Dropoff', address: booking.dropoffAddress),
              const SizedBox(height: 16),
              if (booking.distanceKm != null && (booking.finalTotal ?? booking.computedTotal) != null)
                Text(
                  '${booking.distanceKm!.toStringAsFixed(1)} km  —  ₱${(booking.finalTotal ?? booking.computedTotal)!.toStringAsFixed(0)}',
                  style: GoogleFonts.inter(
                    color: context.textTertiary,
                    fontSize: 13,
                    letterSpacing: 0.1,
                  ),
                ),
              const SizedBox(height: 16),
              GestureDetector(
                onTap: () => ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(
                    content: Text(
                      'Tracking coming soon.',
                      style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
                    ),
                    backgroundColor: TmColors.black,
                    behavior: SnackBarBehavior.floating,
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(8)),
                    margin: const EdgeInsets.all(16),
                  ),
                ),
                child: Text(
                  'Track →',
                  style: GoogleFonts.inter(
                    color: TmColors.yellow,
                    fontSize: 14,
                    letterSpacing: 0.2,
                  ),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        GestureDetector(
          onTap: onRefresh,
          child: Text(
            'Refresh',
            style: GoogleFonts.inter(
              color: context.textSecondary,
              fontSize: 13,
              letterSpacing: 0.2,
            ),
          ),
        ),
      ],
    );
  }
}

// ─── Home service chip ─────────────────────────────────────────────────────

class _HomeServiceChip extends StatelessWidget {
  const _HomeServiceChip({required this.icon, required this.label});
  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 16),
      decoration: BoxDecoration(
        color: context.surface,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        children: [
          Icon(icon, color: TmColors.yellow, size: 26),
          const SizedBox(height: 6),
          Text(
            label,
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 11,
              letterSpacing: 0.1,
            ),
          ),
        ],
      ),
    );
  }
}

// ─── Home vehicle chip ─────────────────────────────────────────────────────

class _HomeVehicleChip extends StatelessWidget {
  const _HomeVehicleChip({required this.icon, required this.label});
  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
      decoration: BoxDecoration(
        color: context.bg,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: context.divider),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: context.textTertiary, size: 14),
          const SizedBox(width: 5),
          Text(
            label,
            style: GoogleFonts.inter(
              color: context.textTertiary,
              fontSize: 11,
              letterSpacing: 0.1,
            ),
          ),
        ],
      ),
    );
  }
}

// ─── Address line ──────────────────────────────────────────────────────────

class _AddressLine extends StatelessWidget {
  const _AddressLine({required this.label, required this.address});
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
