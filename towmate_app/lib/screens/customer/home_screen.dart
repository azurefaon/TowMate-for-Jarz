import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import '../../core/route_observer.dart';
import '../../core/theme.dart';
import '../../models/booking_model.dart';
import '../../models/quotation_model.dart';
import '../../models/service.dart';
import '../../services/api_service.dart';
import '../../widgets/cms_image.dart';
import '../../widgets/skeleton_box.dart';
import '../../widgets/tm_bottom_nav.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver, RouteAware {
  BookingModel? _booking;
  QuotationModel? _quotation;
  Map<String, dynamic>? _announcement;
  List<Service> _services = [];
  List<Map<String, dynamic>> _twoWheelers = [];
  List<Map<String, dynamic>> _fourWheelers = [];
  List<Map<String, dynamic>> _heavyVehicles = [];
  bool _loading = true;
  bool _secondaryLoading = true;
  bool _secondaryError = false;
  String? _name;
  Timer? _pollTimer;

  int? _lastSeenQuotationId;
  String? _lastSeenQuotationStatus;
  String? _lastSeenStatus;
  int _unreadCount = 0;
  bool _initialLoad = true;
  Timer? _notifTimer;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    ApiService.getUserName().then((n) {
      if (mounted) setState(() => _name = n);
    });
    _loadData().then((_) {
      if (mounted) _loadSecondaryContent();
    });
    _pollTimer = Timer.periodic(const Duration(seconds: 30), (_) => _loadData());
    _fetchUnreadCount();
    _notifTimer = Timer.periodic(const Duration(seconds: 60), (_) => _fetchUnreadCount());
  }

  Future<void> _fetchUnreadCount() async {
    final result = await ApiService.fetchNotifications();
    if (!mounted) return;
    final count = (result['unread_count'] as int?) ?? 0;
    if (count != _unreadCount) {
      setState(() => _unreadCount = count);
    }
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    appRouteObserver.subscribe(this, ModalRoute.of(context) as PageRoute);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    appRouteObserver.unsubscribe(this);
    _pollTimer?.cancel();
    _notifTimer?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _loadData();
  }

  @override
  void didPopNext() {
    _loadData();
  }

  Future<void> _loadData() async {
    final results = await Future.wait<Object?>([
      ApiService.fetchCurrentBooking(),
      ApiService.fetchPendingQuotation(),
      ApiService.fetchCustomerContent(),
    ]);
    if (!mounted) return;

    final newBooking = results[0] as BookingModel?;
    final newQuotation = results[1] as QuotationModel?;
    final content = results[2] as Map<String, dynamic>?;
    final newAnnouncement = content?['announcement'] as Map<String, dynamic>?;
    final rawServices = content?['services'] as List<dynamic>?;

    if (!_initialLoad) {
      final newQuotId = newQuotation?.id;
      final newQuotStatus = newQuotation?.status;
      final newStatus = newBooking?.status;

      if (newQuotId != null && newQuotId != _lastSeenQuotationId) {
        _notify('New quotation received — tap to review.');
      } else if (newQuotId != null &&
          newQuotStatus != null &&
          newQuotStatus != _lastSeenQuotationStatus &&
          _lastSeenQuotationStatus == 'price_review_requested') {
        _notify('Your quotation has been updated — tap to review.');
      } else if (newStatus != null && newStatus != _lastSeenStatus) {
        _notify('Booking status updated: ${newBooking!.humanStatus}');
      }
    }

    setState(() {
      _booking = newBooking;
      _quotation = newQuotation;
      _announcement = newAnnouncement;
      if (rawServices != null) {
        _services = rawServices
            .map((s) => Service.fromJson(s as Map<String, dynamic>))
            .toList();
      }
      _loading = false;
      _lastSeenQuotationId = newQuotation?.id ?? _lastSeenQuotationId;
      _lastSeenQuotationStatus = newQuotation?.status ?? _lastSeenQuotationStatus;
      _lastSeenStatus = newBooking?.status ?? _lastSeenStatus;
      _initialLoad = false;
    });
  }

  Future<void> _loadSecondaryContent() async {
    final results = await Future.wait([
      ApiService.fetchVehicleTypesByCategory('2_wheeler'),
      ApiService.fetchVehicleTypesByCategory('4_wheeler'),
      ApiService.fetchVehicleTypesByCategory('heavy_vehicle'),
    ]);
    if (!mounted) return;
    final twoWheelers = results[0];
    final fourWheelers = results[1];
    final heavyVehicles = results[2];
    setState(() {
      _twoWheelers = twoWheelers;
      _fourWheelers = fourWheelers;
      _heavyVehicles = heavyVehicles;
      _secondaryLoading = false;
      _secondaryError = twoWheelers.isEmpty && fourWheelers.isEmpty && heavyVehicles.isEmpty;
    });
  }

  void _notify(String message) {
    ScaffoldMessenger.of(context).showMaterialBanner(
      MaterialBanner(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        content: Text(
          message,
          style: GoogleFonts.inter(color: TmColors.black, fontSize: 13),
        ),
        backgroundColor: TmColors.yellow,
        leading: const Icon(Icons.notifications, color: TmColors.black, size: 20),
        actions: [
          TextButton(
            onPressed: () => ScaffoldMessenger.of(context).hideCurrentMaterialBanner(),
            child: Text(
              'Dismiss',
              style: GoogleFonts.inter(color: TmColors.black, fontSize: 13),
            ),
          ),
        ],
      ),
    );
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
      backgroundColor: context.bg,
      bottomNavigationBar: TmBottomNav(currentRoute: '/home', unreadCount: _unreadCount),
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            const _HomeHeader(),
            Expanded(
              child: _loading
                  ? const _HomeSkeleton()
                  : RefreshIndicator(
                      color: TmColors.black,
                      onRefresh: () => _loadData().then((_) => _loadSecondaryContent()),
                      child: SingleChildScrollView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            _Greeting(greeting: _greeting, name: _name),
                            if (_announcement != null) ...[
                              const SizedBox(height: 16),
                              _AnnouncementBanner(announcement: _announcement!),
                            ],
                            const SizedBox(height: 28),
                            if (_quotation != null)
                              _QuotationReadyCard(
                                quotation: _quotation!,
                                onTap: _openQuotation,
                              )
                            else
                              _CurrentBookingSection(
                                booking: _booking,
                                onViewDetails: _booking == null
                                    ? null
                                    : () => Navigator.pushNamed(
                                          context,
                                          '/booking-detail',
                                          arguments: _booking!.isGrouped
                                              ? {
                                                  'bookingCode': _booking!.bookingCode,
                                                  'asGroupOverview': true,
                                                }
                                              : _booking!.bookingCode,
                                        ),
                              ),
                            const SizedBox(height: 32),
                            _SectionHeading(
                              title: 'Our Services',
                              subtitle: 'Solutions for every situation',
                              onViewAll: _services.isNotEmpty
                                  ? () => Navigator.pushNamed(context, '/customer-services')
                                  : null,
                            ),
                            const SizedBox(height: 14),
                            _ServicesSection(services: _services),
                            if (_secondaryLoading) ...[
                              const SizedBox(height: 28),
                              const _VehicleTypesSkeletonPreview(),
                            ] else if (_twoWheelers.isNotEmpty ||
                                _fourWheelers.isNotEmpty ||
                                _heavyVehicles.isNotEmpty) ...[
                              const SizedBox(height: 28),
                              _SectionHeading(
                                title: 'Vehicle Types',
                                subtitle: 'We tow any type of vehicle',
                                onViewAll: () => Navigator.pushNamed(context, '/vehicle-types'),
                              ),
                              const SizedBox(height: 14),
                              _VehicleTypesSection(
                                twoWheelers: _twoWheelers,
                                fourWheelers: _fourWheelers,
                                heavyVehicles: _heavyVehicles,
                              ),
                            ] else if (_secondaryError) ...[
                              const SizedBox(height: 20),
                              Text(
                                'Vehicle type info is unavailable right now.',
                                style: GoogleFonts.inter(
                                  color: context.textTertiary,
                                  fontSize: 12.5,
                                ),
                              ),
                            ],
                          ],
                        ),
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

class _HomeHeader extends StatelessWidget {
  const _HomeHeader();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: context.divider, width: 0.5)),
      ),
      child: Center(
        child: RichText(
          text: TextSpan(
            style: GoogleFonts.inter(
              fontSize: 20,
              fontWeight: FontWeight.w700,
              letterSpacing: -0.5,
            ),
            children: [
              TextSpan(text: 'Tow', style: TextStyle(color: context.textPrimary)),
              const TextSpan(text: 'Mate', style: TextStyle(color: TmColors.yellow)),
            ],
          ),
        ),
      ),
    );
  }
}

class _Greeting extends StatelessWidget {
  const _Greeting({required this.greeting, required this.name});
  final String greeting;
  final String? name;

  @override
  Widget build(BuildContext context) {
    final firstName = (name != null && name!.trim().isNotEmpty)
        ? name!.trim().split(' ').first
        : null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '$greeting,',
          style: GoogleFonts.inter(
            color: context.textSecondary,
            fontSize: 13.5,
            letterSpacing: 0.2,
          ),
        ),
        if (firstName != null) ...[
          const SizedBox(height: 3),
          Text(
            firstName,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 27,
              fontWeight: FontWeight.w700,
              letterSpacing: -0.7,
            ),
          ),
        ],
      ],
    );
  }
}

Color _secondaryTextColor(BuildContext context) =>
    context.isDark ? TmColors.grey500 : const Color(0xFF6B6B6B);

class _SectionHeading extends StatelessWidget {
  const _SectionHeading({required this.title, required this.subtitle, this.onViewAll});
  final String title;
  final String subtitle;
  final VoidCallback? onViewAll;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 17,
                  fontWeight: FontWeight.w600,
                  letterSpacing: -0.4,
                ),
              ),
            ),
            if (onViewAll != null) _ViewAllAction(onTap: onViewAll!),
          ],
        ),
        const SizedBox(height: 2),
        Text(
          subtitle,
          style: GoogleFonts.inter(
            color: _secondaryTextColor(context),
            fontSize: 12.5,
            letterSpacing: 0.1,
          ),
        ),
      ],
    );
  }
}

class _ViewAllAction extends StatelessWidget {
  const _ViewAllAction({required this.onTap});
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(8),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 10),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'View all',
              style: GoogleFonts.inter(
                color: context.textPrimary,
                fontSize: 12.5,
                fontWeight: FontWeight.w500,
                letterSpacing: 0.1,
              ),
            ),
            Icon(Icons.chevron_right, size: 16, color: context.textPrimary),
          ],
        ),
      ),
    );
  }
}

class _AnnouncementBanner extends StatelessWidget {
  const _AnnouncementBanner({required this.announcement});
  final Map<String, dynamic> announcement;

  @override
  Widget build(BuildContext context) {
    final title = (announcement['title'] as String?) ?? '';
    final message = (announcement['message'] as String?) ?? '';

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: TmColors.black,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.campaign, color: TmColors.yellow, size: 20),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (title.isNotEmpty)
                  Text(
                    title,
                    style: GoogleFonts.inter(
                      color: TmColors.white,
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      letterSpacing: 0.1,
                    ),
                  ),
                if (title.isNotEmpty) const SizedBox(height: 4),
                Text(
                  message,
                  style: GoogleFonts.inter(
                    color: TmColors.grey500,
                    fontSize: 12.5,
                    letterSpacing: 0.1,
                    height: 1.5,
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
          quotation.isPriceReviewRequested ? 'Price Review Requested' : 'Quotation Ready',
          style: GoogleFonts.inter(
            color: context.textSecondary,
            fontSize: 12,
            fontWeight: FontWeight.w600,
            letterSpacing: 0.5,
          ),
        ),
        const SizedBox(height: 12),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            border: Border.all(color: context.divider),
            borderRadius: BorderRadius.circular(14),
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
                      letterSpacing: 0.3,
                    ),
                  ),
                  Text(
                    quotation.isPriceReviewRequested ? 'Under Review' : 'Awaiting Response',
                    style: GoogleFonts.inter(
                      color: context.textTertiary,
                      fontSize: 12,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              Text(
                '₱${quotation.estimatedPrice.toStringAsFixed(0)}',
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 26,
                  fontWeight: FontWeight.w600,
                  letterSpacing: -0.6,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                '${quotation.truckTypeName}  ·  ${quotation.distanceKm.toStringAsFixed(1)} km',
                style: GoogleFonts.inter(
                  color: context.textTertiary,
                  fontSize: 12,
                  letterSpacing: 0.1,
                ),
              ),
              const SizedBox(height: 14),
              _AddressLine(label: 'Pickup', address: quotation.pickupAddress),
              const SizedBox(height: 8),
              _AddressLine(label: 'Dropoff', address: quotation.dropoffAddress),
              const SizedBox(height: 14),
              GestureDetector(
                onTap: onTap,
                child: Text(
                  'Review & Accept',
                  style: GoogleFonts.inter(
                    color: TmColors.black,
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
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

class _CurrentBookingSection extends StatelessWidget {
  const _CurrentBookingSection({required this.booking, required this.onViewDetails});
  final BookingModel? booking;
  final VoidCallback? onViewDetails;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Current Booking',
          style: GoogleFonts.inter(
            color: _secondaryTextColor(context),
            fontSize: 12,
            fontWeight: FontWeight.w600,
            letterSpacing: 0.5,
          ),
        ),
        const SizedBox(height: 12),
        booking == null ? _emptyState(context) : _activeCard(context, booking!),
      ],
    );
  }

  Widget _emptyState(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 20),
      decoration: BoxDecoration(
        color: context.card,
        border: Border.all(color: context.divider),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'No active booking',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 14.5,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'Your active towing request will appear here.',
            style: GoogleFonts.inter(
              color: context.textTertiary,
              fontSize: 12.5,
              letterSpacing: 0.1,
              height: 1.4,
            ),
          ),
        ],
      ),
    );
  }

  Widget _activeCard(BuildContext context, BookingModel booking) {
    final isGrouped = booking.isGrouped;
    final vehicleCount = 1 + (booking.groupSiblings?.length ?? 0);
    final vehicleLabel = vehicleCount > 1 ? '$vehicleCount Vehicles' : '1 Vehicle';
    final priceValue = booking.groupTotals?.finalTotal ?? booking.finalTotal ?? booking.computedTotal;
    final priceLabel = booking.groupTotals != null ? booking.groupTotalLabel : 'Estimated Price';
    final headingText = isGrouped ? (booking.groupCode ?? 'Group Request') : booking.bookingCode;
    final statusText = isGrouped
        ? (booking.groupAllCancelled
            ? 'Cancelled'
            : booking.groupAllCompleted
                ? 'Completed'
                : 'Active')
        : booking.humanStatus;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: context.card,
        border: Border.all(color: context.divider),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                headingText,
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 12,
                  fontWeight: FontWeight.w500,
                  letterSpacing: 0.3,
                ),
              ),
              statusText == 'Active'
                  ? Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: TmColors.success,
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text(
                        statusText,
                        style: GoogleFonts.inter(
                          color: TmColors.black,
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    )
                  : Text(
                      statusText,
                      style: GoogleFonts.inter(
                        color: context.textPrimary,
                        fontSize: 12,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            isGrouped
                ? (booking.groupAllCancelled || booking.groupAllCompleted
                    ? vehicleLabel
                    : booking.groupActiveStatusText)
                : (booking.displayVehicleName.isNotEmpty
                    ? '$vehicleLabel  ·  ${booking.displayVehicleName}'
                    : vehicleLabel),
            style: GoogleFonts.inter(
              color: _secondaryTextColor(context),
              fontSize: 12,
              letterSpacing: 0.1,
            ),
          ),
          const SizedBox(height: 14),
          _AddressLine(label: 'Pickup', address: booking.pickupAddress),
          const SizedBox(height: 8),
          _AddressLine(label: 'Dropoff', address: booking.dropoffAddress),
          if (priceValue != null) ...[
            const SizedBox(height: 14),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  priceLabel,
                  style: GoogleFonts.inter(
                    color: _secondaryTextColor(context),
                    fontSize: 12,
                    letterSpacing: 0.1,
                  ),
                ),
                Text(
                  '₱${NumberFormat('#,##0.00', 'en_PH').format(priceValue)}',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    letterSpacing: 0.1,
                  ),
                ),
              ],
            ),
          ],
          if (onViewDetails != null) ...[
            const SizedBox(height: 14),
            Divider(color: context.divider, height: 1),
            const SizedBox(height: 12),
            GestureDetector(
              onTap: onViewDetails,
              child: Text(
                'View Booking Details',
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  letterSpacing: 0.1,
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _ServicesSection extends StatelessWidget {
  const _ServicesSection({required this.services});
  final List<Service> services;

  @override
  Widget build(BuildContext context) {
    if (services.isEmpty) {
      return Text(
        'Services info is unavailable right now.',
        style: GoogleFonts.inter(color: context.textTertiary, fontSize: 12.5),
      );
    }

    final shown = services.take(3).toList();
    return Row(
      children: [
        for (var i = 0; i < shown.length; i++)
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(right: i == shown.length - 1 ? 0 : 10),
              child: _ServiceChip(service: shown[i]),
            ),
          ),
      ],
    );
  }
}

class _ServiceChip extends StatelessWidget {
  const _ServiceChip({required this.service});
  final Service service;

  bool get _hasImage => service.imageUrl != null && service.imageUrl!.isNotEmpty;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
      decoration: BoxDecoration(
        color: context.card,
        border: Border.all(color: context.divider),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (_hasImage) ...[
            SizedBox(
              width: 26,
              height: 26,
              child: ClipRRect(
                borderRadius: BorderRadius.circular(7),
                child: CmsImage(imageUrl: service.imageUrl),
              ),
            ),
            const SizedBox(height: 8),
          ],
          Text(
            service.title,
            textAlign: TextAlign.center,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 11,
              fontWeight: FontWeight.w500,
              letterSpacing: 0.1,
            ),
          ),
        ],
      ),
    );
  }
}

class _VehicleTypesSection extends StatelessWidget {
  const _VehicleTypesSection({
    required this.twoWheelers,
    required this.fourWheelers,
    required this.heavyVehicles,
  });
  final List<Map<String, dynamic>> twoWheelers;
  final List<Map<String, dynamic>> fourWheelers;
  final List<Map<String, dynamic>> heavyVehicles;

  @override
  Widget build(BuildContext context) {
    final entries = [
      for (final v in twoWheelers) (v['name'] as String?) ?? '',
      for (final v in fourWheelers) (v['name'] as String?) ?? '',
      for (final v in heavyVehicles) (v['name'] as String?) ?? '',
    ].where((name) => name.isNotEmpty).take(8).toList();

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final name in entries)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
            decoration: BoxDecoration(
              color: context.bg,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: context.divider),
            ),
            child: Text(
              name,
              style: GoogleFonts.inter(
                color: context.textPrimary,
                fontSize: 11.5,
                letterSpacing: 0.1,
              ),
            ),
          ),
      ],
    );
  }
}

class _VehicleTypesSkeletonPreview extends StatelessWidget {
  const _VehicleTypesSkeletonPreview();

  @override
  Widget build(BuildContext context) {
    const widths = [86.0, 104.0, 72.0, 96.0, 80.0];
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final w in widths)
          SkeletonBox(width: w, height: 32, borderRadius: BorderRadius.circular(10)),
      ],
    );
  }
}

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
              color: _secondaryTextColor(context),
              fontSize: 12,
              letterSpacing: 0.3,
            ),
          ),
        ),
        Expanded(
          child: Text(
            address,
            style: GoogleFonts.inter(
              color: context.textPrimary,
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

class _HomeSkeleton extends StatelessWidget {
  const _HomeSkeleton();

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SkeletonBox(width: 110, height: 15),
          const SizedBox(height: 8),
          const SkeletonBox(width: 160, height: 26),
          const SizedBox(height: 24),
          SkeletonBox(
            width: double.infinity,
            height: 56,
            borderRadius: BorderRadius.circular(14),
          ),
          const SizedBox(height: 28),
          const SkeletonBox(width: 130, height: 12),
          const SizedBox(height: 12),
          SkeletonBox(
            width: double.infinity,
            height: 150,
            borderRadius: BorderRadius.circular(14),
          ),
          const SizedBox(height: 32),
          const SkeletonBox(width: 100, height: 17),
          const SizedBox(height: 4),
          const SkeletonBox(width: 160, height: 12),
          const SizedBox(height: 14),
          Row(
            children: [
              for (var i = 0; i < 3; i++)
                Expanded(
                  child: Padding(
                    padding: EdgeInsets.only(right: i == 2 ? 0 : 10),
                    child: SkeletonBox(
                      width: double.infinity,
                      height: 84,
                      borderRadius: BorderRadius.circular(12),
                    ),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}
