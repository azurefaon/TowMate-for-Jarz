import 'dart:async';
import 'dart:ui' show PathMetric;
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/route_observer.dart';
import '../../core/theme.dart';
import '../../models/booking_model.dart';
import '../../models/quotation_model.dart';
import '../../services/api_service.dart';
import '../../services/push_notification_service.dart';
import '../../widgets/skeleton_box.dart';
import '../../widgets/tm_bottom_nav.dart';
import '../../widgets/vehicle_type_widgets.dart';

const _previewVehicleNames = ['Motorcycle', 'SUV', 'Van / L300', 'Elf / 6-Wheeler'];
const _headerColor = TmColors.black;
const _headerOverlap = 64.0;

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver, RouteAware {
  BookingModel? _booking;
  QuotationModel? _quotation;
  Map<String, dynamic>? _announcement;
  List<String> _vehicleNames = [];
  bool _loading = true;
  bool _secondaryLoading = true;
  String? _name;
  Timer? _pollTimer;

  int? _lastSeenQuotationId;
  String? _lastSeenQuotationStatus;
  int _unreadCount = 0;
  bool _initialLoad = true;
  Timer? _notifTimer;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _loadName();
    _loadData().then((_) {
      if (mounted) _loadSecondaryContent();
    });
    _pollTimer = Timer.periodic(const Duration(seconds: 30), (_) => _loadData());
    _fetchUnreadCount();
    unawaited(PushNotificationService.instance.startForCustomer());
    _notifTimer = Timer.periodic(const Duration(seconds: 60), (_) => _fetchUnreadCount());
  }

  Future<void> _loadName() async {
    final first = await ApiService.getUserFirstName();
    final full = await ApiService.getUserName();
    if (!mounted) return;
    final firstTrimmed = first?.trim() ?? '';
    final fallback = (full ?? '').trim().split(RegExp(r'\s+')).first;
    final resolved = firstTrimmed.isNotEmpty ? firstTrimmed : fallback;
    setState(() => _name = resolved.isEmpty ? null : resolved);
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

    if (!_initialLoad) {
      final newQuotId = newQuotation?.id;
      final newQuotStatus = newQuotation?.status;

      if (newQuotId != null && newQuotId != _lastSeenQuotationId) {
        _notify('New quotation received — tap to review.');
      } else if (newQuotId != null &&
          newQuotStatus != null &&
          newQuotStatus != _lastSeenQuotationStatus &&
          _lastSeenQuotationStatus == 'price_review_requested') {
        _notify('Your quotation has been updated — tap to review.');
      }
    }

    setState(() {
      _booking = newBooking;
      _quotation = newQuotation;
      _announcement = newAnnouncement;
      _loading = false;
      _lastSeenQuotationId = newQuotation?.id ?? _lastSeenQuotationId;
      _lastSeenQuotationStatus = newQuotation?.status ?? _lastSeenQuotationStatus;
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
    final names = [
      for (final list in results)
        for (final v in list) (v['name'] as String?) ?? '',
    ].where((n) => n.isNotEmpty).toList();
    setState(() {
      _vehicleNames = names;
      _secondaryLoading = false;
    });
  }

  List<String> get _previewNames {
    final preferred = _previewVehicleNames.where(_vehicleNames.contains).toList();
    if (preferred.length >= _previewVehicleNames.length) return preferred;
    final rest = _vehicleNames.where((n) => !preferred.contains(n));
    return [...preferred, ...rest].take(_previewVehicleNames.length).toList();
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

  void _openBookingDetails() {
    final booking = _booking;
    if (booking == null) return;
    Navigator.pushNamed(
      context,
      '/booking-detail',
      arguments: booking.isGrouped
          ? {'bookingCode': booking.bookingCode, 'asGroupOverview': true}
          : booking.bookingCode,
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      bottomNavigationBar: TmBottomNav(currentRoute: '/home', unreadCount: _unreadCount),
      body: _loading
          ? const _HomeSkeleton()
          : RefreshIndicator(
              color: TmColors.black,
              edgeOffset: MediaQuery.paddingOf(context).top,
              onRefresh: () => _loadData().then((_) => _loadSecondaryContent()),
              child: SingleChildScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _HomeHeader(greeting: _greeting, name: _name),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 20),
                      child: _quotation != null
                          ? _QuotationReadyCard(quotation: _quotation!, onTap: _openQuotation)
                          : _CurrentBookingCard(
                              booking: _booking,
                              onTrack: _booking == null ? null : _openBookingDetails,
                            ),
                    ),
                    if (_announcement != null)
                      Padding(
                        padding: const EdgeInsets.fromLTRB(20, 16, 20, 0),
                        child: _AnnouncementBanner(announcement: _announcement!),
                      ),
                    const SizedBox(height: 28),
                    const _QuickActions(),
                    const SizedBox(height: 28),
                    const _HowItWorks(),
                    _VehicleTypesPreview(
                      loading: _secondaryLoading,
                      names: _previewNames,
                    ),
                    const SizedBox(height: 28),
                  ],
                ),
              ),
            ),
    );
  }
}

class _HomeHeader extends StatelessWidget {
  const _HomeHeader({required this.greeting, required this.name});
  final String greeting;
  final String? name;

  @override
  Widget build(BuildContext context) {
    final topInset = MediaQuery.paddingOf(context).top;
    return Stack(
      clipBehavior: Clip.none,
      children: [
        Positioned(
          top: 0,
          left: 0,
          right: 0,
          bottom: -_headerOverlap,
          child: const DecoratedBox(
            decoration: BoxDecoration(
              color: _headerColor,
              borderRadius: BorderRadius.vertical(bottom: Radius.circular(28)),
            ),
            child: ClipRRect(
              borderRadius: BorderRadius.vertical(bottom: Radius.circular(28)),
              child: CustomPaint(painter: _RoadPainter()),
            ),
          ),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(24, topInset + 20, 24, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              RichText(
                text: TextSpan(
                  style: GoogleFonts.inter(
                    fontSize: 24,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -0.6,
                  ),
                  children: const [
                    TextSpan(text: 'Tow', style: TextStyle(color: TmColors.white)),
                    TextSpan(text: 'Mate', style: TextStyle(color: TmColors.yellow)),
                  ],
                ),
              ),
              const SizedBox(height: 20),
              if (greeting.isNotEmpty)
                Text(
                  '$greeting,',
                  style: GoogleFonts.inter(
                    color: const Color(0xFFD4D4D4),
                    fontSize: 16,
                    letterSpacing: 0.1,
                  ),
                ),
              if (name != null) ...[
                const SizedBox(height: 2),
                Text(
                  name!,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: GoogleFonts.inter(
                    color: TmColors.white,
                    fontSize: 34,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -1,
                    height: 1.1,
                  ),
                ),
              ],
              const SizedBox(height: 24),
            ],
          ),
        ),
      ],
    );
  }
}

class _RoadPainter extends CustomPainter {
  const _RoadPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final w = size.width;
    final h = size.height;
    final road = Path()
      ..moveTo(w * 0.86, h + 40)
      ..cubicTo(w * 0.70, h * 0.78, w * 0.98, h * 0.40, w * 1.04, h * 0.02);

    final edge = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 74
      ..color = TmColors.white.withValues(alpha: 0.07);
    final surface = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 68
      ..color = Color.alphaBlend(TmColors.white.withValues(alpha: 0.04), _headerColor);

    canvas.drawPath(road, edge);
    canvas.drawPath(road, surface);

    final dash = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..strokeCap = StrokeCap.round
      ..color = TmColors.yellow.withValues(alpha: 0.55);
    for (final PathMetric metric in road.computeMetrics()) {
      var distance = 0.0;
      while (distance < metric.length) {
        canvas.drawPath(metric.extractPath(distance, distance + 12), dash);
        distance += 24;
      }
    }
  }

  @override
  bool shouldRepaint(covariant _RoadPainter oldDelegate) => false;
}

BoxDecoration _cardDecoration(BuildContext context) => BoxDecoration(
      color: context.card,
      borderRadius: BorderRadius.circular(22),
      border: context.isDark ? Border.all(color: context.divider) : null,
      boxShadow: context.isDark
          ? null
          : [
              BoxShadow(
                color: TmColors.black.withValues(alpha: 0.12),
                blurRadius: 24,
                offset: const Offset(0, 8),
              ),
            ],
    );

Color _mutedText(BuildContext context) =>
    context.isDark ? const Color(0xFFA3A3A3) : const Color(0xFF6B6B6B);

class _CardLabel extends StatelessWidget {
  const _CardLabel(this.text);
  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: GoogleFonts.inter(
        color: _mutedText(context),
        fontSize: 12,
        fontWeight: FontWeight.w700,
        letterSpacing: 1.1,
      ),
    );
  }
}

int _progressStage(String status) {
  return switch (status) {
    'assigned' || 'delayed' => 1,
    'on_the_way' || 'arrived_pickup' => 2,
    'in_progress' ||
    'loading_vehicle' ||
    'on_job' ||
    'arrived_dropoff' ||
    'waiting_verification' =>
      3,
    _ => 0,
  };
}

const _progressLabels = ['Requested', 'Assigned', 'On the way', 'Towing'];

class _CurrentBookingCard extends StatelessWidget {
  const _CurrentBookingCard({required this.booking, required this.onTrack});
  final BookingModel? booking;
  final VoidCallback? onTrack;

  @override
  Widget build(BuildContext context) {
    final b = booking;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(20),
      decoration: _cardDecoration(context),
      child: b == null ? _empty(context) : _active(context, b),
    );
  }

  Widget _empty(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 56,
              height: 56,
              decoration: BoxDecoration(
                color: TmColors.yellow,
                borderRadius: BorderRadius.circular(16),
              ),
              child: const Icon(Icons.local_shipping_outlined, color: TmColors.black, size: 28),
            ),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const _CardLabel('CURRENT BOOKING'),
                  const SizedBox(height: 4),
                  Text(
                    'No active booking',
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 22,
                      fontWeight: FontWeight.w700,
                      letterSpacing: -0.4,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    'Once you request a tow, your driver and live status show up here.',
                    style: GoogleFonts.inter(
                      color: _mutedText(context),
                      fontSize: 14,
                      height: 1.4,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 16),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          decoration: BoxDecoration(
            color: TmColors.yellow.withValues(alpha: context.isDark ? 0.14 : 0.18),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Row(
            children: [
              Container(
                width: 26,
                height: 26,
                decoration: const BoxDecoration(color: TmColors.yellow, shape: BoxShape.circle),
                child: const Icon(Icons.add, size: 18, color: TmColors.black),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text.rich(
                  TextSpan(
                    style: GoogleFonts.inter(color: context.textPrimary, fontSize: 14),
                    children: const [
                      TextSpan(text: 'Need a tow? Tap '),
                      TextSpan(text: 'Book Now', style: TextStyle(fontWeight: FontWeight.w700)),
                      TextSpan(text: ' below.'),
                    ],
                  ),
                ),
              ),
              Icon(Icons.arrow_downward, size: 18, color: context.textPrimary),
            ],
          ),
        ),
      ],
    );
  }

  Widget _active(BuildContext context, BookingModel b) {
    final isGrouped = b.isGrouped;
    final vehicleCount = 1 + (b.groupSiblings?.length ?? 0);
    final vehicleLabel = vehicleCount > 1 ? '$vehicleCount Vehicles' : '1 Vehicle';
    final headline = isGrouped ? (b.groupCode ?? 'Group Request') : b.bookingCode;
    final statusText = isGrouped
        ? (b.groupAllCancelled
            ? 'Cancelled'
            : b.groupAllCompleted
                ? 'Completed'
                : 'Active')
        : b.humanStatus;
    final summary = isGrouped
        ? (b.groupAllCancelled || b.groupAllCompleted ? vehicleLabel : b.groupActiveStatusText)
        : (b.displayVehicleName.isNotEmpty ? '$vehicleLabel  ·  ${b.displayVehicleName}' : vehicleLabel);
    final stage = isGrouped ? 0 : _progressStage(b.status);
    final driver = (b.driverName ?? b.teamLeaderName)?.trim();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            const Flexible(child: _CardLabel('CURRENT BOOKING')),
            const SizedBox(width: 8),
            Flexible(child: _StatusPill(text: statusText)),
          ],
        ),
        const SizedBox(height: 14),
        Text(
          headline,
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 24,
            fontWeight: FontWeight.w800,
            letterSpacing: -0.6,
          ),
        ),
        const SizedBox(height: 2),
        Text(
          summary,
          style: GoogleFonts.inter(color: _mutedText(context), fontSize: 14),
        ),
        if (!isGrouped) ...[
          const SizedBox(height: 16),
          _ProgressBar(stage: stage),
        ],
        const SizedBox(height: 16),
        _RouteBox(pickup: b.pickupAddress, dropoff: b.dropoffAddress),
        const SizedBox(height: 16),
        Row(
          children: [
            if (driver != null && driver.isNotEmpty) ...[
              CircleAvatar(
                radius: 22,
                backgroundColor: context.isDark ? TmColors.dark700 : TmColors.black,
                child: Text(
                  _initials(driver),
                  style: GoogleFonts.inter(
                    color: TmColors.yellow,
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      driver,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: GoogleFonts.inter(
                        color: context.textPrimary,
                        fontSize: 16,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    if (b.truckTypeName.isNotEmpty)
                      Text(
                        b.truckTypeName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: GoogleFonts.inter(color: _mutedText(context), fontSize: 13),
                      ),
                  ],
                ),
              ),
            ] else
              const Spacer(),
            const SizedBox(width: 12),
            _TrackButton(onTap: onTrack),
          ],
        ),
      ],
    );
  }

  static String _initials(String name) {
    final parts = name.split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    if (parts.isEmpty) return '?';
    if (parts.length == 1) return parts.first[0].toUpperCase();
    return '${parts.first[0]}${parts.last[0]}'.toUpperCase();
  }
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.text});
  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: TmColors.yellow,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 7,
            height: 7,
            decoration: const BoxDecoration(color: TmColors.black, shape: BoxShape.circle),
          ),
          const SizedBox(width: 6),
          Flexible(
            child: Text(
              text,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: GoogleFonts.inter(
                color: TmColors.black,
                fontSize: 13,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ProgressBar extends StatelessWidget {
  const _ProgressBar({required this.stage});
  final int stage;

  @override
  Widget build(BuildContext context) {
    final inactive = context.isDark ? TmColors.dark600 : TmColors.grey300;
    return Column(
      children: [
        Row(
          children: [
            for (var i = 0; i < _progressLabels.length; i++) ...[
              Expanded(
                child: Container(
                  height: 4,
                  decoration: BoxDecoration(
                    color: i <= stage ? TmColors.yellow : inactive,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
              if (i != _progressLabels.length - 1) const SizedBox(width: 6),
            ],
          ],
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            for (var i = 0; i < _progressLabels.length; i++)
              Expanded(
                child: Text(
                  _progressLabels[i],
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: GoogleFonts.inter(
                    color: i == stage ? context.textPrimary : _mutedText(context),
                    fontSize: 11.5,
                    fontWeight: i == stage ? FontWeight.w700 : FontWeight.w500,
                  ),
                ),
              ),
          ],
        ),
      ],
    );
  }
}

class _RouteBox extends StatelessWidget {
  const _RouteBox({required this.pickup, required this.dropoff});
  final String pickup;
  final String dropoff;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: context.isDark ? TmColors.dark700 : TmColors.grey100,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Column(
              children: [
                Container(
                  width: 12,
                  height: 12,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    border: Border.all(color: context.textPrimary, width: 2),
                  ),
                ),
                Container(width: 1.5, height: 30, color: context.textTertiary.withValues(alpha: 0.6)),
                Container(width: 12, height: 12, color: TmColors.yellow),
              ],
            ),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _routeLine(context, 'Pickup', pickup),
                const SizedBox(height: 12),
                _routeLine(context, 'Drop-off', dropoff),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _routeLine(BuildContext context, String label, String address) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: GoogleFonts.inter(color: _mutedText(context), fontSize: 13)),
        const SizedBox(height: 1),
        Text(
          address,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 15,
            fontWeight: FontWeight.w700,
            height: 1.3,
          ),
        ),
      ],
    );
  }
}

class _TrackButton extends StatelessWidget {
  const _TrackButton({required this.onTap});
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: TmColors.yellow,
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 14),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                'Track',
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(width: 8),
              const Icon(Icons.chevron_right, size: 20, color: TmColors.black),
            ],
          ),
        ),
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
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(20),
      decoration: _cardDecoration(context),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Flexible(
                child: _CardLabel(
                  quotation.isPriceReviewRequested ? 'PRICE REVIEW REQUESTED' : 'QUOTATION READY',
                ),
              ),
              const SizedBox(width: 8),
              Flexible(
                child: _StatusPill(
                  text: quotation.isPriceReviewRequested ? 'Under Review' : 'Awaiting Response',
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Text(
            '₱${quotation.estimatedPrice.toStringAsFixed(0)}',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 28,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.6,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            '${quotation.quotationNumber}  ·  ${quotation.truckTypeName}  ·  ${quotation.distanceKm.toStringAsFixed(1)} km',
            style: GoogleFonts.inter(color: _mutedText(context), fontSize: 13),
          ),
          const SizedBox(height: 16),
          _RouteBox(pickup: quotation.pickupAddress, dropoff: quotation.dropoffAddress),
          const SizedBox(height: 16),
          Align(
            alignment: Alignment.centerRight,
            child: Material(
              color: TmColors.yellow,
              borderRadius: BorderRadius.circular(14),
              child: InkWell(
                borderRadius: BorderRadius.circular(14),
                onTap: onTap,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
                  child: Text(
                    'Review & Accept',
                    style: GoogleFonts.inter(
                      color: TmColors.black,
                      fontSize: 15,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
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

class _QuickActions extends StatelessWidget {
  const _QuickActions();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Quick actions',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 20,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.4,
            ),
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: _QuickAction(
                  icon: Icons.history,
                  label: 'Booking history',
                  onTap: () => Navigator.pushNamed(context, '/my-bookings', arguments: 'history'),
                ),
              ),
              Expanded(
                child: _QuickAction(
                  icon: Icons.directions_car_outlined,
                  label: 'Vehicle types',
                  onTap: () => Navigator.pushNamed(context, '/vehicle-types'),
                ),
              ),
              Expanded(
                child: _QuickAction(
                  icon: Icons.menu_book_outlined,
                  label: 'Towing guide',
                  onTap: () => Navigator.pushNamed(context, '/towing-guide'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _QuickAction extends StatelessWidget {
  const _QuickAction({required this.icon, required this.label, required this.onTap});
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Column(
          children: [
            Container(
              width: 60,
              height: 60,
              decoration: BoxDecoration(
                color: context.surface,
                borderRadius: BorderRadius.circular(18),
              ),
              child: Icon(icon, size: 26, color: context.textPrimary),
            ),
            const SizedBox(height: 8),
            Text(
              label,
              textAlign: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: GoogleFonts.inter(
                color: context.textPrimary,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _HowItWorks extends StatelessWidget {
  const _HowItWorks();

  static const _steps = [
    ('Choose your vehicle', 'Pick the vehicle type so we send the right truck.'),
    ('Set pickup and drop-off', 'Pin where you are and where it needs to go.'),
    ('Track your tow', 'Follow your driver live until drop-off.'),
  ];

  @override
  Widget build(BuildContext context) {
    final band = context.isDark ? const Color(0xFF181818) : const Color(0xFFF6F7F9);
    return Container(
      width: double.infinity,
      color: band,
      padding: const EdgeInsets.fromLTRB(20, 28, 20, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'How TowMate works',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 20,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.4,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            'From roadside to drop-off in three steps.',
            style: GoogleFonts.inter(color: _mutedText(context), fontSize: 14),
          ),
          const SizedBox(height: 20),
          for (var i = 0; i < _steps.length; i++)
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Column(
                    children: [
                      Container(
                        width: 40,
                        height: 40,
                        alignment: Alignment.center,
                        decoration: const BoxDecoration(color: TmColors.yellow, shape: BoxShape.circle),
                        child: Text(
                          '${i + 1}',
                          style: GoogleFonts.inter(
                            color: TmColors.black,
                            fontSize: 17,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ),
                      if (i != _steps.length - 1)
                        Expanded(
                          child: Container(
                            width: 1.5,
                            margin: const EdgeInsets.symmetric(vertical: 4),
                            color: context.divider,
                          ),
                        ),
                    ],
                  ),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Padding(
                      padding: EdgeInsets.only(bottom: i == _steps.length - 1 ? 0 : 22),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            _steps[i].$1,
                            style: GoogleFonts.inter(
                              color: context.textPrimary,
                              fontSize: 17,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          const SizedBox(height: 3),
                          Text(
                            _steps[i].$2,
                            style: GoogleFonts.inter(
                              color: _mutedText(context),
                              fontSize: 14.5,
                              height: 1.4,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          const SizedBox(height: 22),
          Divider(color: context.divider, height: 1),
          InkWell(
            onTap: () => Navigator.pushNamed(context, '/towing-guide'),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 18),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Not sure if you need light, medium or heavy duty?',
                          style: GoogleFonts.inter(color: _mutedText(context), fontSize: 14.5),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          'Read the towing guide',
                          style: GoogleFonts.inter(
                            color: context.textPrimary,
                            fontSize: 15,
                            fontWeight: FontWeight.w700,
                            decoration: TextDecoration.underline,
                            decorationColor: TmColors.yellow,
                            decorationThickness: 2,
                          ),
                        ),
                      ],
                    ),
                  ),
                  Icon(Icons.chevron_right, color: context.textPrimary),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _VehicleTypesPreview extends StatelessWidget {
  const _VehicleTypesPreview({required this.loading, required this.names});
  final bool loading;
  final List<String> names;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 28, 20, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Vehicle types',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 20,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -0.4,
                  ),
                ),
              ),
              InkWell(
                onTap: () => Navigator.pushNamed(context, '/vehicle-types'),
                borderRadius: BorderRadius.circular(8),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 8),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        'View all',
                        style: GoogleFonts.inter(
                          color: context.textPrimary,
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      Icon(Icons.chevron_right, size: 18, color: context.textPrimary),
                    ],
                  ),
                ),
              ),
            ],
          ),
          Text(
            'We tow any type of vehicle.',
            style: GoogleFonts.inter(color: _mutedText(context), fontSize: 14),
          ),
          const SizedBox(height: 16),
          if (loading)
            Row(
              children: [
                for (var i = 0; i < 4; i++)
                  Expanded(
                    child: Padding(
                      padding: EdgeInsets.only(right: i == 3 ? 0 : 8),
                      child: SkeletonBox(
                        width: double.infinity,
                        height: 72,
                        borderRadius: BorderRadius.circular(16),
                      ),
                    ),
                  ),
              ],
            )
          else if (names.isEmpty)
            Text(
              'Vehicle type info is unavailable right now.',
              style: GoogleFonts.inter(color: context.textTertiary, fontSize: 12.5),
            )
          else
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (var i = 0; i < names.length; i++)
                  Expanded(
                    child: Padding(
                      padding: EdgeInsets.only(right: i == names.length - 1 ? 0 : 8),
                      child: _PreviewTile(name: names[i]),
                    ),
                  ),
              ],
            ),
        ],
      ),
    );
  }
}

class _PreviewTile extends StatelessWidget {
  const _PreviewTile({required this.name});
  final String name;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Container(
          width: double.infinity,
          height: 72,
          decoration: BoxDecoration(
            color: context.surface,
            borderRadius: BorderRadius.circular(16),
          ),
          child: Icon(vehicleIconFor(name), size: 28, color: context.textPrimary),
        ),
        const SizedBox(height: 8),
        VehicleTypeLabel(name: name, color: context.textPrimary, fontSize: 12.5),
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
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const _HomeHeader(greeting: '', name: null),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: SkeletonBox(
              width: double.infinity,
              height: 190,
              borderRadius: BorderRadius.circular(22),
            ),
          ),
          const SizedBox(height: 28),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Row(
              children: [
                for (var i = 0; i < 3; i++)
                  Expanded(
                    child: Padding(
                      padding: EdgeInsets.only(right: i == 2 ? 0 : 10),
                      child: SkeletonBox(
                        width: double.infinity,
                        height: 84,
                        borderRadius: BorderRadius.circular(16),
                      ),
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
