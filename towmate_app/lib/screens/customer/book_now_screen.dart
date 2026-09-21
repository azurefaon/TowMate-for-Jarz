import 'dart:async';
import 'dart:io';
import 'dart:math' show sin, cos, sqrt, atan2, pi;
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:geolocator/geolocator.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart';
import '../../core/theme.dart';
import '../../models/booking_model.dart';
import '../../models/vehicle_category_model.dart';
import '../../models/vehicle_type_model.dart';
import '../../services/api_service.dart';
import '../../widgets/quotation_price_cards.dart';
import '../../widgets/skeleton_box.dart';

class _ExtraVehicleData {
  VehicleTypeModel? vehicle;
  final List<XFile> images = [];
  bool imageError = false;
}

class BookNowScreen extends StatefulWidget {
  const BookNowScreen({super.key});

  @override
  State<BookNowScreen> createState() => _BookNowScreenState();
}

class _BookNowScreenState extends State<BookNowScreen> {
  String _serviceType = 'book_now';
  DateTime? _scheduledDate;
  TimeOfDay? _scheduledTime;

  List<VehicleTypeModel> _vehicleTypes = [];
  List<VehicleCategoryModel> _vehicleCategories = [];
  VehicleTypeModel? _selectedVehicleType;
  bool _loadingTypes = true;

  bool _bookNowEnabled = true;
  String? _availabilityMessage;
  Set<int> _readyTruckTypeIds = {};

  LatLng? _pickupLatLng;
  LatLng? _dropoffLatLng;
  String _pickupAddress = '';
  String _dropoffAddress = '';

  List<LatLng> _routePoints = [];
  double? _distanceKm;
  double? _durationMin;
  bool _loadingRoute = false;
  bool _routeFallback = false;

  final List<XFile> _vehicleImages = [];
  bool _imageError = false;
  final _picker = ImagePicker();

  final List<_ExtraVehicleData> _extraVehicles = [];
  static final _priceFmt = NumberFormat('#,##0.00', 'en_PH');

  Map<String, dynamic>? _pricingPreview;
  bool _loadingPricing = false;
  String? _pricingError;

  final _notesCtrl = TextEditingController();

  bool _submitting = false;
  String? _bookingError;

  bool _prefillApplied = false;
  String? _prefillPickupAddress;
  String? _prefillDropoffAddress;

  int _step = 0;
  final ScrollController _scrollController = ScrollController();
  bool _checkingDuplicateRoute = false;

  Timer? _availabilityPollTimer;

  @override
  void initState() {
    super.initState();
    _loadData();
    _availabilityPollTimer = Timer.periodic(const Duration(seconds: 15), (_) {
      if (_step == 1) _refreshAvailability();
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_prefillApplied) return;
    _prefillApplied = true;
    final args = ModalRoute.of(context)?.settings.arguments;
    if (args is! Map) return;
    final pickupAddr = args['pickupAddress'] as String?;
    final pickupLat = (args['pickupLat'] as num?)?.toDouble();
    final pickupLng = (args['pickupLng'] as num?)?.toDouble();
    final dropoffAddr = args['dropoffAddress'] as String?;
    final dropoffLat = (args['dropoffLat'] as num?)?.toDouble();
    final dropoffLng = (args['dropoffLng'] as num?)?.toDouble();
    if (pickupAddr != null && pickupLat != null && pickupLng != null) {
      _prefillPickupAddress = pickupAddr;
      _pickupLatLng = LatLng(pickupLat, pickupLng);
      _pickupAddress = pickupAddr;
    }
    if (dropoffAddr != null && dropoffLat != null && dropoffLng != null) {
      _prefillDropoffAddress = dropoffAddr;
      _dropoffLatLng = LatLng(dropoffLat, dropoffLng);
      _dropoffAddress = dropoffAddr;
    }
    if (_pickupLatLng != null && _dropoffLatLng != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _calculateRoute());
    }
  }

  @override
  void dispose() {
    _availabilityPollTimer?.cancel();
    _notesCtrl.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _jumpToTop() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scrollController.hasClients) _scrollController.jumpTo(0);
    });
  }

  Future<void> _loadData() async {
    if (mounted) setState(() => _loadingTypes = true);

    final typeFuture = ApiService.fetchVehicleTypes();
    final categoryFuture = ApiService.fetchVehicleCategories();
    final availFuture = _refreshAvailability();

    final types = await typeFuture;
    final categories = await categoryFuture;
    if (!mounted) return;
    setState(() {
      _vehicleTypes = types;
      _vehicleCategories = categories;
      _loadingTypes = false;
    });

    await availFuture;
  }

  Future<void> _refreshAvailability() async {
    final avail = await ApiService.fetchAvailability();
    if (!mounted || avail == null) return;
    setState(() {
      _bookNowEnabled = avail['book_now_enabled'] as bool? ?? true;
      _availabilityMessage = _bookNowEnabled
          ? null
          : (avail['message'] as String? ??
                'No tow trucks are currently available for immediate dispatch.');
      final readyIds = avail['ready_truck_type_ids'];
      _readyTruckTypeIds = readyIds is List
          ? readyIds.map((e) => (e as num).toInt()).toSet()
          : <int>{};
    });
  }

  bool _isExactlyAvailable(VehicleTypeModel vehicle) {
    if (_readyTruckTypeIds.isEmpty) return _bookNowEnabled;
    return _readyTruckTypeIds.contains(vehicle.requiredTruckTypeId);
  }

  void _selectVehicle(VehicleTypeModel vehicle) {
    setState(() {
      _selectedVehicleType = vehicle;
    });

    if (_serviceType == 'book_now' && !_isExactlyAvailable(vehicle)) {
      _showNoUnitsModal();
    }
  }

  void _onPickupSelected(LatLng latlng, String address) {
    setState(() {
      _pickupLatLng = latlng;
      _pickupAddress = address;
      _routePoints = [];
      _distanceKm = null;
    });
    if (_dropoffLatLng != null) _calculateRoute();
  }

  void _onDropoffSelected(LatLng latlng, String address) {
    setState(() {
      _dropoffLatLng = latlng;
      _dropoffAddress = address;
      _routePoints = [];
      _distanceKm = null;
    });
    if (_pickupLatLng != null) _calculateRoute();
  }

  void _onPickupCleared() {
    setState(() {
      _pickupLatLng = null;
      _pickupAddress = '';
      _routePoints = [];
      _distanceKm = null;
      _routeFallback = false;
    });
  }

  void _onDropoffCleared() {
    setState(() {
      _dropoffLatLng = null;
      _dropoffAddress = '';
      _routePoints = [];
      _distanceKm = null;
      _routeFallback = false;
    });
  }

  void _resetLocations() {
    setState(() {
      _pickupLatLng = null;
      _dropoffLatLng = null;
      _pickupAddress = '';
      _dropoffAddress = '';
      _routePoints = [];
      _distanceKm = null;
      _routeFallback = false;
    });
  }

  static double _haversineKm(LatLng a, LatLng b) {
    const r = 6371.0;
    final dLat = (b.latitude - a.latitude) * pi / 180;
    final dLng = (b.longitude - a.longitude) * pi / 180;
    final h =
        sin(dLat / 2) * sin(dLat / 2) +
        cos(a.latitude * pi / 180) *
            cos(b.latitude * pi / 180) *
            sin(dLng / 2) *
            sin(dLng / 2);
    return r * 2 * atan2(sqrt(h), sqrt(1 - h));
  }

  Future<void> _calculateRoute() async {
    if (_pickupLatLng == null || _dropoffLatLng == null) return;
    setState(() {
      _loadingRoute = true;
      _routeFallback = false;
    });

    final result = await ApiService.calculateRoute(
      _pickupLatLng!.latitude,
      _pickupLatLng!.longitude,
      _dropoffLatLng!.latitude,
      _dropoffLatLng!.longitude,
    );
    if (!mounted) return;

    if (result['success'] == true) {
      final coords = result['coordinates'] as List;
      final pts = coords
          .map(
            (c) => LatLng((c[0] as num).toDouble(), (c[1] as num).toDouble()),
          )
          .toList();
      setState(() {
        _routePoints = pts;
        _distanceKm = (result['distance_km'] as num).toDouble();
        _durationMin = result['duration_min'] != null
            ? (result['duration_min'] as num).toDouble()
            : null;
        _loadingRoute = false;
        _routeFallback = false;
      });
    } else {
      final fallback = _haversineKm(_pickupLatLng!, _dropoffLatLng!);
      setState(() {
        _routePoints = [_pickupLatLng!, _dropoffLatLng!];
        _distanceKm = fallback;
        _durationMin = null;
        _loadingRoute = false;
        _routeFallback = true;
      });
    }
  }

  Future<void> _fetchPricingPreview() async {
    if (_selectedVehicleType == null ||
        _pickupLatLng == null ||
        _dropoffLatLng == null) {
      return;
    }
    setState(() {
      _loadingPricing = true;
      _pricingError = null;
    });

    final extraPayload = _extraVehicles
        .where((v) => v.vehicle != null)
        .map((v) => {'vehicle_type_id': v.vehicle!.id})
        .toList();

    final result = await ApiService.fetchPricingPreview(
      vehicleTypeId: _selectedVehicleType!.id,
      pickupLat: _pickupLatLng!.latitude,
      pickupLng: _pickupLatLng!.longitude,
      dropoffLat: _dropoffLatLng!.latitude,
      dropoffLng: _dropoffLatLng!.longitude,
      serviceType: _serviceType,
      extraVehicles: extraPayload,
    );
    if (!mounted) return;

    setState(() {
      _loadingPricing = false;
      if (result != null && result['pricing'] != null) {
        _pricingPreview = result;
      } else {
        _pricingPreview = null;
        _pricingError = 'Unable to load pricing. Please try again.';
      }
    });
  }

  static const _allowedExts = {'jpg', 'jpeg', 'png'};

  Future<void> _pickImage(ImageSource source) async {
    if (_vehicleImages.length >= 5) return;
    final picked = await _picker.pickImage(
      source: source,
      imageQuality: 70,
      maxWidth: 1280,
      maxHeight: 1280,
    );
    if (picked == null || !mounted) return;

    final ext = picked.name.split('.').last.toLowerCase();
    if (!_allowedExts.contains(ext)) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Only JPG and PNG images are accepted.',
            style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
          ),
          backgroundColor: TmColors.error,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          margin: const EdgeInsets.all(16),
        ),
      );
      return;
    }

    setState(() {
      _vehicleImages.add(picked);
      _imageError = false;
    });
  }

  void _removeImage(int index) {
    setState(() => _vehicleImages.removeAt(index));
  }

  Future<void> _pickExtraImage(int index, ImageSource source) async {
    final data = _extraVehicles[index];
    if (data.images.length >= 5) return;
    final picked = await _picker.pickImage(
      source: source,
      imageQuality: 70,
      maxWidth: 1280,
      maxHeight: 1280,
    );
    if (picked == null || !mounted) return;

    final ext = picked.name.split('.').last.toLowerCase();
    if (!_allowedExts.contains(ext)) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Only JPG and PNG images are accepted.',
            style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
          ),
          backgroundColor: TmColors.error,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          margin: const EdgeInsets.all(16),
        ),
      );
      return;
    }

    setState(() {
      data.images.add(picked);
      data.imageError = false;
    });
  }

  void _removeExtraImage(int index, int photoIndex) {
    setState(() => _extraVehicles[index].images.removeAt(photoIndex));
  }

  void _addExtraVehicle() {
    if (_extraVehicles.length >= 5) return;
    setState(() => _extraVehicles.add(_ExtraVehicleData()));
  }

  void _removeExtraVehicle(int index) {
    setState(() => _extraVehicles.removeAt(index));
  }

  void _setExtraVehicle(int index, VehicleTypeModel vehicle) {
    setState(() => _extraVehicles[index].vehicle = vehicle);
  }

  void _scheduleEntireRequest() {
    setState(() {
      _serviceType = 'schedule';
      _step = 0;
    });
    _jumpToTop();
  }

  Future<void> _showNoUnitsModal() async {
    final tomorrow = DateTime.now().add(const Duration(days: 1));
    final suggested = tomorrow.weekday == DateTime.sunday
        ? tomorrow.add(const Duration(days: 1))
        : tomorrow;
    final weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    final months = [
      'Jan',
      'Feb',
      'Mar',
      'Apr',
      'May',
      'Jun',
      'Jul',
      'Aug',
      'Sep',
      'Oct',
      'Nov',
      'Dec',
    ];
    final hour = suggested.hour % 12 == 0 ? 12 : suggested.hour % 12;
    final ampm = suggested.hour < 12 ? 'AM' : 'PM';
    final suggestedLabel =
        '${weekdays[suggested.weekday - 1]}, ${months[suggested.month - 1]} ${suggested.day}'
        ' at $hour:${suggested.minute.toString().padLeft(2, '0')} $ampm';

    await showDialog<void>(
      context: context,
      barrierDismissible: true,
      builder: (ctx) => AlertDialog(
        backgroundColor: ctx.card,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text(
          'No Units Available',
          style: GoogleFonts.inter(
            color: ctx.textPrimary,
            fontSize: 17,
            letterSpacing: -0.3,
          ),
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              _availabilityMessage ??
                  'No tow trucks are currently available for immediate dispatch.',
              style: GoogleFonts.inter(
                color: ctx.textTertiary,
                fontSize: 14,
                height: 1.5,
              ),
            ),
            const SizedBox(height: 12),
            Text(
              'Suggested schedule: $suggestedLabel',
              style: GoogleFonts.inter(
                color: ctx.textSecondary,
                fontSize: 13,
                height: 1.4,
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text(
              'Cancel',
              style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 14),
            ),
          ),
          TextButton(
            onPressed: () {
              Navigator.pop(ctx);
              _scheduleEntireRequest();
            },
            child: Text(
              'Schedule entire request',
              style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _submitBooking() async {
    if (_submitting) return;

    if (!_bookNowEnabled && _serviceType == 'book_now') {
      await _showNoUnitsModal();
      return;
    }

    setState(() {
      _submitting = true;
      _bookingError = null;
    });

    String? scheduledDateStr;
    String? scheduledTimeStr;
    if (_serviceType == 'schedule' &&
        _scheduledDate != null &&
        _scheduledTime != null) {
      scheduledDateStr =
          '${_scheduledDate!.year.toString().padLeft(4, '0')}-'
          '${_scheduledDate!.month.toString().padLeft(2, '0')}-'
          '${_scheduledDate!.day.toString().padLeft(2, '0')}';
      scheduledTimeStr =
          '${_scheduledTime!.hour.toString().padLeft(2, '0')}:'
          '${_scheduledTime!.minute.toString().padLeft(2, '0')}';
    }

    final validExtras = _extraVehicles.where((v) => v.vehicle != null).toList();
    final extraList = <Map<String, dynamic>>[];
    final extraVehicleImagePaths = <int, List<String>>{};
    for (int i = 0; i < validExtras.length; i++) {
      final v = validExtras[i];
      extraList.add({'vehicle_type_id': v.vehicle!.id});
      extraVehicleImagePaths[i] = v.images.map((x) => x.path).toList();
    }

    final result = await ApiService.createBooking(
      truckTypeId: _selectedVehicleType!.requiredTruckTypeId ?? 0,
      vehicleTypeId: _selectedVehicleType!.id,
      pickupAddress: _pickupAddress,
      pickupLat: _pickupLatLng!.latitude,
      pickupLng: _pickupLatLng!.longitude,
      dropoffAddress: _dropoffAddress,
      dropoffLat: _dropoffLatLng!.latitude,
      dropoffLng: _dropoffLatLng!.longitude,
      distanceKm: _distanceKm!,
      serviceType: _serviceType,
      notes: _notesCtrl.text.trim().isEmpty ? null : _notesCtrl.text.trim(),
      scheduledDate: scheduledDateStr,
      scheduledTime: scheduledTimeStr,
      vehicleImagePaths: _vehicleImages.map((x) => x.path).toList(),
      extraVehicles: extraList,
      extraVehicleImagePaths: extraVehicleImagePaths,
    );

    if (!mounted) return;

    if (result['success'] == true) {
      final bookings =
          result['bookings'] as List<BookingGroupSibling>? ?? const [];
      Navigator.pushNamedAndRemoveUntil(
        context,
        '/booking-success',
        (_) => false,
        arguments: bookings,
      );
    } else {
      setState(() {
        _submitting = false;
        _bookingError =
            result['message'] as String? ?? 'Booking failed. Please try again.';
      });
    }
  }

  bool get _canProceedStep1 =>
      _pickupLatLng != null &&
      _dropoffLatLng != null &&
      _distanceKm != null &&
      !_loadingRoute &&
      (_serviceType != 'schedule' ||
          (_scheduledDate != null && _scheduledTime != null));

  bool get _canProceedStep2 {
    if (_selectedVehicleType == null) return false;
    if (_vehicleImages.isEmpty) return false;
    if (_serviceType == 'book_now' &&
        !_isExactlyAvailable(_selectedVehicleType!)) {
      return false;
    }
    if (_extraVehicles.any((v) {
      if (v.vehicle == null) return true;
      if (v.images.isEmpty) return true;
      if (_serviceType == 'book_now' && !_isExactlyAvailable(v.vehicle!))
        return true;
      return false;
    })) {
      return false;
    }
    return true;
  }

  Widget _buildTopBar(BuildContext ctx) {
    const titles = ['Where to?', 'Your Vehicle', 'Review'];
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: ctx.divider, width: 0.5)),
      ),
      child: Row(
        children: [
          if (_step == 0)
            IconButton(
              icon: Icon(
                Icons.arrow_back_ios_new_rounded,
                color: ctx.textPrimary,
                size: 20,
              ),
              onPressed: () => Navigator.pop(ctx),
              padding: EdgeInsets.zero,
              constraints: const BoxConstraints(),
            )
          else
            IconButton(
              icon: Icon(
                Icons.arrow_back_ios_new_rounded,
                color: ctx.textPrimary,
                size: 20,
              ),
              onPressed: () => setState(() => _step--),
              padding: EdgeInsets.zero,
              constraints: const BoxConstraints(),
            ),
          Expanded(
            child: Center(
              child: Text(
                titles[_step],
                style: GoogleFonts.inter(
                  color: ctx.textPrimary,
                  fontSize: 16,
                  fontWeight: FontWeight.w700,
                  letterSpacing: -0.4,
                ),
              ),
            ),
          ),
          const SizedBox(width: 40),
        ],
      ),
    );
  }

  Widget _buildStepBody() {
    return switch (_step) {
      0 => _buildStep0(),
      1 => _buildStep1(),
      _ => _buildStep2(),
    };
  }

  Widget _buildStep0() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _BookingModeSection(
          serviceType: _serviceType,
          scheduledDate: _scheduledDate,
          scheduledTime: _scheduledTime,
          onServiceTypeChanged: (v) => setState(() => _serviceType = v),
          onDateChanged: (d) => setState(() => _scheduledDate = d),
          onTimeChanged: (t) => setState(() => _scheduledTime = t),
        ),
        _LocationSection(
          pickupLatLng: _pickupLatLng,
          dropoffLatLng: _dropoffLatLng,
          routePoints: _routePoints,
          loadingRoute: _loadingRoute,
          onPickupSelected: _onPickupSelected,
          onDropoffSelected: _onDropoffSelected,
          onPickupCleared: _onPickupCleared,
          onDropoffCleared: _onDropoffCleared,
          onReset: _resetLocations,
          initialPickupAddress: _prefillPickupAddress,
          initialDropoffAddress: _prefillDropoffAddress,
        ),
        const SizedBox(height: 24),
      ],
    );
  }

  Widget _buildStep1() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (!_bookNowEnabled && _serviceType == 'book_now')
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 20, 24, 0),
            child: Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: context.card,
                border: Border.all(color: TmColors.yellow),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    _availabilityMessage ??
                        'No tow trucks are currently available for immediate dispatch.',
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 13,
                      height: 1.4,
                    ),
                  ),
                  const SizedBox(height: 10),
                  GestureDetector(
                    onTap: _scheduleEntireRequest,
                    child: Text(
                      'Schedule for later instead →',
                      style: GoogleFonts.inter(
                        color: TmColors.yellow,
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 0.1,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        _VehicleTypeSection(
          vehicleTypes: _vehicleTypes,
          vehicleCategories: _vehicleCategories,
          loading: _loadingTypes,
          selectedVehicle: _selectedVehicleType,
          readyTruckTypeIds: _readyTruckTypeIds,
          bookNowEnabled: _bookNowEnabled,
          serviceType: _serviceType,
          onSelect: _selectVehicle,
          onRetry: _loadData,
          onScheduleEntireRequest: _scheduleEntireRequest,
        ),
        const SizedBox(height: 20),
        _VehicleImageSection(
          images: _vehicleImages,
          hasError: _imageError,
          onAddTap: () => _showImageSourceSheet(onPick: _pickImage),
          onRemove: _removeImage,
        ),
        if (_vehicleTypes.isNotEmpty) ...[
          const SizedBox(height: 20),
          _ExtraVehiclesSection(
            extraVehicles: _extraVehicles,
            vehicleTypes: _vehicleTypes,
            vehicleCategories: _vehicleCategories,
            canAdd: _extraVehicles.length < 5,
            vehicleCount: _extraVehicles.length + 1,
            readyTruckTypeIds: _readyTruckTypeIds,
            requestServiceType: _serviceType,
            onAdd: _addExtraVehicle,
            onRemove: _removeExtraVehicle,
            onVehicleSet: _setExtraVehicle,
            onScheduleEntireRequest: _scheduleEntireRequest,
            onAddPhotoTap: (index) => _showImageSourceSheet(
              onPick: (src) => _pickExtraImage(index, src),
            ),
            onRemovePhoto: _removeExtraImage,
          ),
        ],
        const SizedBox(height: 24),
      ],
    );
  }

  Widget _sectionHeaderWithEdit(String label, VoidCallback onEdit) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: sectionEyebrowStyle(context)),
        GestureDetector(
          onTap: onEdit,
          behavior: HitTestBehavior.opaque,
          child: Text(
            'Edit',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 12.5,
              fontWeight: FontWeight.w600,
              decoration: TextDecoration.underline,
              decorationColor: context.divider,
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildStep2() {
    final bool hasSchedule =
        _serviceType == 'schedule' && _scheduledDate != null;
    String? dateStr;
    String? timeStr;
    if (hasSchedule) {
      dateStr =
          '${_scheduledDate!.month.toString().padLeft(2, '0')}/'
          '${_scheduledDate!.day.toString().padLeft(2, '0')}/'
          '${_scheduledDate!.year}';
    }
    if (_scheduledTime != null) {
      final h = _scheduledTime!.hourOfPeriod == 0
          ? 12
          : _scheduledTime!.hourOfPeriod;
      final m = _scheduledTime!.minute.toString().padLeft(2, '0');
      final period = _scheduledTime!.period == DayPeriod.am ? 'AM' : 'PM';
      timeStr = '$h:$m $period';
    }

    final pricingMap = _pricingPreview?['pricing'] as Map<String, dynamic>?;
    final canonicalDistanceKm = pricingMap != null
        ? (pricingMap['distance_km'] as num?)?.toDouble()
        : null;
    final bool distanceDiffers =
        _distanceKm != null &&
        canonicalDistanceKm != null &&
        (_distanceKm! - canonicalDistanceKm).abs() > 0.05;

    final List<_ReviewVehicle> allVehicles = [
      (
        label: 'Vehicle 1',
        vehicleName: _selectedVehicleType?.name ?? '—',
        serviceType: _serviceType,
        scheduledDate: _scheduledDate,
        scheduledTime: _scheduledTime,
        images: _vehicleImages,
      ),
      for (int i = 0; i < _extraVehicles.length; i++)
        (
          label: 'Vehicle ${i + 2}',
          vehicleName: _extraVehicles[i].vehicle?.name ?? '—',
          serviceType: _serviceType,
          scheduledDate: _scheduledDate,
          scheduledTime: _scheduledTime,
          images: _extraVehicles[i].images,
        ),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 24, 24, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _sectionHeaderWithEdit('TRIP', () => setState(() => _step = 0)),
              const SizedBox(height: 14),
              _ReviewRow(label: 'Pickup', value: _pickupAddress),
              const SizedBox(height: 14),
              Container(height: 1, color: context.divider),
              const SizedBox(height: 14),
              _ReviewRow(label: 'Drop-off', value: _dropoffAddress),
              if (_distanceKm != null) ...[
                const SizedBox(height: 14),
                Container(height: 1, color: context.divider),
                const SizedBox(height: 14),
                _ReviewRow(
                  label: 'Distance',
                  value:
                      '${_distanceKm!.toStringAsFixed(2)} km'
                      '${_durationMin != null ? ' · ${_durationMin!.toInt()} min' : ''}',
                  caption: distanceDiffers
                      ? 'Driving distance for map & ETA. Billed distance is '
                            '${canonicalDistanceKm.toStringAsFixed(2)} km — see Price Summary.'
                      : (_routeFallback
                            ? 'Estimated distance (route unavailable)'
                            : null),
                ),
              ],
              if (hasSchedule && dateStr != null) ...[
                const SizedBox(height: 14),
                Container(height: 1, color: context.divider),
                const SizedBox(height: 14),
                _ReviewRow(
                  label: 'Scheduled',
                  value: '$dateStr${timeStr != null ? ' at $timeStr' : ''}',
                ),
              ],
              const SizedBox(height: 28),
              _sectionHeaderWithEdit(
                'VEHICLES',
                () => setState(() => _step = 1),
              ),
              const SizedBox(height: 14),
              for (int i = 0; i < allVehicles.length; i++) ...[
                if (i > 0) ...[
                  const SizedBox(height: 16),
                  Container(height: 1, color: context.divider),
                  const SizedBox(height: 16),
                ],
                _VehicleReviewEntry(vehicle: allVehicles[i]),
              ],
              const SizedBox(height: 28),
              Text('PRICE SUMMARY', style: sectionEyebrowStyle(context)),
              const SizedBox(height: 14),
              _buildPricingBody(),
              if (allVehicles.length > 1) ...[
                const SizedBox(height: 20),
                Text(
                  'You\'re confirming one request for ${allVehicles.length} vehicle${allVehicles.length == 1 ? '' : 's'}. '
                  'Each vehicle is priced separately and included in one quotation.',
                  style: GoogleFonts.inter(
                    color: secondaryTextColor(context),
                    fontSize: 12,
                    letterSpacing: 0.1,
                    height: 1.5,
                  ),
                ),
              ],
            ],
          ),
        ),
        _NotesSection(controller: _notesCtrl),
        if (_bookingError != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 16, 24, 0),
            child: Text(
              _bookingError!,
              style: GoogleFonts.inter(
                color: TmColors.destructive,
                fontSize: 13,
                fontWeight: FontWeight.w600,
                letterSpacing: 0.1,
              ),
            ),
          ),
        const SizedBox(height: 24),
      ],
    );
  }

  Widget _buildPricingBody() {
    if (_loadingPricing) {
      return const Column(
        children: [
          SkeletonBox(
            width: double.infinity,
            height: 22,
            borderRadius: BorderRadius.all(Radius.circular(6)),
          ),
          SizedBox(height: 12),
          SkeletonBox(
            width: double.infinity,
            height: 22,
            borderRadius: BorderRadius.all(Radius.circular(6)),
          ),
          SizedBox(height: 12),
          SkeletonBox(
            width: double.infinity,
            height: 22,
            borderRadius: BorderRadius.all(Radius.circular(6)),
          ),
          SizedBox(height: 16),
          SkeletonBox(
            width: double.infinity,
            height: 30,
            borderRadius: BorderRadius.all(Radius.circular(6)),
          ),
        ],
      );
    }

    final preview = _pricingPreview;
    if (preview == null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            _pricingError ?? 'Unable to load pricing.',
            style: GoogleFonts.inter(
              color: TmColors.destructive,
              fontSize: 14,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 10),
          GestureDetector(
            onTap: _fetchPricingPreview,
            child: Text(
              'Retry',
              style: GoogleFonts.inter(
                color: context.textPrimary,
                fontSize: 13,
                fontWeight: FontWeight.w700,
                decoration: TextDecoration.underline,
                decorationColor: context.textPrimary,
              ),
            ),
          ),
        ],
      );
    }

    final pricing = preview['pricing'] as Map<String, dynamic>? ?? {};
    final bookNowVehiclePreviews =
        (preview['book_now_vehicle_previews'] as List?)
            ?.cast<Map<String, dynamic>>() ??
        [];
    final scheduledExtraPreviews =
        (preview['scheduled_extra_previews'] as List?)
            ?.cast<Map<String, dynamic>>() ??
        [];
    final bookNowVehicleCount = _serviceType == 'book_now'
        ? 1 + _extraVehicles.where((v) => v.vehicle != null).length
        : 1;

    return _PriceBreakdown(
      pricing: pricing,
      bookNowVehiclePreviews: bookNowVehiclePreviews,
      scheduledExtraPreviews: scheduledExtraPreviews,
      vehicleTypes: _vehicleTypes,
      bookNowVehicleCount: bookNowVehicleCount,
      priceFmt: _priceFmt,
      serviceType: _serviceType,
      primaryVehicleName: _selectedVehicleType?.name ?? 'Vehicle',
    );
  }

  Future<void> _proceedFromLocationStep() async {
    setState(() => _checkingDuplicateRoute = true);
    final duplicateMessage = await ApiService.checkDuplicateActiveRoute(
      pickupLat: _pickupLatLng!.latitude,
      pickupLng: _pickupLatLng!.longitude,
      dropoffLat: _dropoffLatLng!.latitude,
      dropoffLng: _dropoffLatLng!.longitude,
      serviceType: _serviceType,
    );
    if (!mounted) return;
    setState(() => _checkingDuplicateRoute = false);

    if (duplicateMessage != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            duplicateMessage,
            style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
          ),
          backgroundColor: TmColors.grey700,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          margin: const EdgeInsets.all(16),
        ),
      );
      return;
    }

    setState(() => _step = 1);
  }

  Widget _buildBottomBar() {
    final bool loading = _step == 0 && (_loadingRoute || _checkingDuplicateRoute);
    final bool isConfirm = _step == 2;
    final String label = isConfirm ? 'Confirm Booking' : 'Continue';

    String? hint;
    VoidCallback? onTap;

    if (_step == 0) {
      if (_pickupLatLng == null) {
        hint = 'Set a pickup location to continue';
      } else if (_dropoffLatLng == null) {
        hint = 'Set a drop-off location to continue';
      } else if (_loadingRoute) {
        hint = 'Calculating route...';
      } else if (_serviceType == 'schedule' && _scheduledDate == null) {
        hint = 'Select a preferred date to continue';
      } else if (_serviceType == 'schedule' && _scheduledTime == null) {
        hint = 'Select a preferred time to continue';
      } else if (_checkingDuplicateRoute) {
        hint = 'Checking your existing bookings...';
      } else if (_routeFallback) {
        hint = 'Using estimated distance (route unavailable)';
      }
      onTap = (_canProceedStep1 && !_checkingDuplicateRoute)
          ? _proceedFromLocationStep
          : null;
    } else if (_step == 1) {
      if (_selectedVehicleType == null) {
        hint = 'Select a vehicle type for Vehicle 1 to continue';
      } else if (_vehicleImages.isEmpty) {
        hint = 'Add at least 1 photo for Vehicle 1 to continue';
      } else if (_serviceType == 'book_now' &&
          !_isExactlyAvailable(_selectedVehicleType!)) {
        hint = 'Vehicle 1 is not available for Book Now';
      } else {
        for (int i = 0; i < _extraVehicles.length; i++) {
          final v = _extraVehicles[i];
          final label = 'Vehicle ${i + 2}';
          if (v.vehicle == null) {
            hint = 'Select a vehicle type for $label to continue';
          } else if (v.images.isEmpty) {
            hint = 'Add at least 1 photo for $label to continue';
          } else if (_serviceType == 'book_now' &&
              !_isExactlyAvailable(v.vehicle!)) {
            hint = '$label is not available for Book Now';
          }
          if (hint != null) break;
        }
      }
      onTap = () {
        if (_vehicleImages.isEmpty) setState(() => _imageError = true);
        if (!_canProceedStep2) return;
        setState(() => _step = 2);
        _fetchPricingPreview();
      };
    } else {
      final bool pricingReady = !_loadingPricing && _pricingPreview != null;
      onTap = (_submitting || !pricingReady) ? null : _submitBooking;
    }

    final bool busy = loading || (_step == 2 && _submitting);

    return Container(
      padding: const EdgeInsets.fromLTRB(24, 12, 24, 24),
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: context.divider, width: 0.5)),
        color: context.bg,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (hint != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Text(
                hint,
                style: GoogleFonts.inter(
                  color: _step == 1
                      ? TmColors.destructive
                      : context.textPrimary,
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  letterSpacing: 0.1,
                ),
                textAlign: TextAlign.center,
              ),
            ),
          SizedBox(
            width: double.infinity,
            height: 52,
            child: ElevatedButton(
              onPressed: busy ? null : onTap,
              style: ElevatedButton.styleFrom(
                backgroundColor: TmColors.yellow,
                foregroundColor: TmColors.black,
                disabledBackgroundColor: TmColors.grey300,
                disabledForegroundColor: TmColors.grey700,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(8),
                ),
                elevation: 0,
              ),
              child: busy
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                        color: TmColors.white,
                        strokeWidth: 2,
                      ),
                    )
                  : Text(
                      label,
                      style: GoogleFonts.inter(
                        color: TmColors.black,
                        fontSize: 15,
                        letterSpacing: 0.2,
                      ),
                    ),
            ),
          ),
        ],
      ),
    );
  }

  void _showImageSourceSheet({required void Function(ImageSource) onPick}) {
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: context.card,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(12)),
      ),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.fromLTRB(24, 20, 24, 40),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Center(
              child: Container(
                width: 36,
                height: 4,
                decoration: BoxDecoration(
                  color: ctx.divider,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
            const SizedBox(height: 20),
            Text(
              'Add Vehicle Photo',
              style: GoogleFonts.inter(
                color: ctx.textPrimary,
                fontSize: 15,
                letterSpacing: -0.2,
              ),
            ),
            const SizedBox(height: 16),
            GestureDetector(
              onTap: () {
                Navigator.pop(ctx);
                onPick(ImageSource.camera);
              },
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 12),
                child: Text(
                  'Take Photo',
                  style: GoogleFonts.inter(
                    color: ctx.textTertiary,
                    fontSize: 15,
                    letterSpacing: 0.1,
                  ),
                ),
              ),
            ),
            Container(height: 0.5, color: ctx.divider),
            GestureDetector(
              onTap: () {
                Navigator.pop(ctx);
                onPick(ImageSource.gallery);
              },
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 12),
                child: Text(
                  'Choose from Gallery',
                  style: GoogleFonts.inter(
                    color: ctx.textTertiary,
                    fontSize: 15,
                    letterSpacing: 0.1,
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: _step == 0,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop && _step > 0) setState(() => _step--);
      },
      child: Scaffold(
        backgroundColor: context.bg,
        body: Builder(
          builder: (ctx) => SafeArea(
            child: Column(
              children: [
                _buildTopBar(ctx),
                _StepIndicator(step: _step),
                Expanded(
                  child: SingleChildScrollView(
                    controller: _scrollController,
                    child: _buildStepBody(),
                  ),
                ),
                _buildBottomBar(),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _BookingModeSection extends StatelessWidget {
  const _BookingModeSection({
    required this.serviceType,
    required this.scheduledDate,
    required this.scheduledTime,
    required this.onServiceTypeChanged,
    required this.onDateChanged,
    required this.onTimeChanged,
  });

  final String serviceType;
  final DateTime? scheduledDate;
  final TimeOfDay? scheduledTime;
  final void Function(String) onServiceTypeChanged;
  final void Function(DateTime) onDateChanged;
  final void Function(TimeOfDay) onTimeChanged;

  String _formatDate(DateTime d) =>
      '${d.month.toString().padLeft(2, '0')}/${d.day.toString().padLeft(2, '0')}/${d.year}';

  String _formatTime(TimeOfDay t) {
    final h = t.hourOfPeriod == 0 ? 12 : t.hourOfPeriod;
    final m = t.minute.toString().padLeft(2, '0');
    final period = t.period == DayPeriod.am ? 'AM' : 'PM';
    return '$h:$m $period';
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Book Your Towing Service',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 22,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.6,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'Choose a booking mode, set your locations, and confirm.',
            style: GoogleFonts.inter(
              color: secondaryTextColor(context),
              fontSize: 13,
              letterSpacing: 0.1,
              height: 1.5,
            ),
          ),
          const SizedBox(height: 22),
          Text('BOOKING MODE', style: sectionEyebrowStyle(context)),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: _ModeChip(
                  label: 'Book Now',
                  subtitle: 'Get a unit as soon as possible',
                  selected: serviceType == 'book_now',
                  onTap: () => onServiceTypeChanged('book_now'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _ModeChip(
                  label: 'Schedule Later',
                  subtitle: 'Choose a date and time',
                  selected: serviceType == 'schedule',
                  onTap: () => onServiceTypeChanged('schedule'),
                ),
              ),
            ],
          ),
          AnimatedSize(
            duration: const Duration(milliseconds: 220),
            curve: Curves.easeOut,
            alignment: Alignment.topCenter,
            child: serviceType == 'schedule'
                ? Padding(
                    padding: const EdgeInsets.only(top: 16),
                    child: Row(
                      children: [
                        Expanded(
                          child: _DateTimeField(
                            label: 'Preferred Date',
                            value: scheduledDate != null
                                ? _formatDate(scheduledDate!)
                                : null,
                            placeholder: 'Select date',
                            onTap: () async {
                              final picked = await showDatePicker(
                                context: context,
                                initialDate: DateTime.now().add(
                                  const Duration(days: 1),
                                ),
                                firstDate: DateTime.now(),
                                lastDate: DateTime.now().add(
                                  const Duration(days: 90),
                                ),
                                builder: (ctx, child) => Theme(
                                  data: Theme.of(ctx).copyWith(
                                    colorScheme: const ColorScheme.light(
                                      primary: TmColors.black,
                                      onPrimary: TmColors.white,
                                    ),
                                  ),
                                  child: child!,
                                ),
                              );
                              if (picked != null) onDateChanged(picked);
                            },
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: _DateTimeField(
                            label: 'Preferred Time',
                            value: scheduledTime != null
                                ? _formatTime(scheduledTime!)
                                : null,
                            placeholder: 'Select time',
                            onTap: () async {
                              final picked = await showTimePicker(
                                context: context,
                                initialTime: scheduledTime ?? TimeOfDay.now(),
                                builder: (ctx, child) => Theme(
                                  data: Theme.of(ctx).copyWith(
                                    colorScheme: const ColorScheme.light(
                                      primary: TmColors.black,
                                      onPrimary: TmColors.white,
                                    ),
                                  ),
                                  child: child!,
                                ),
                              );
                              if (picked != null) onTimeChanged(picked);
                            },
                          ),
                        ),
                      ],
                    ),
                  )
                : const SizedBox(width: double.infinity),
          ),
        ],
      ),
    );
  }
}

class _ModeChip extends StatelessWidget {
  const _ModeChip({
    required this.label,
    required this.subtitle,
    required this.selected,
    required this.onTap,
  });
  final String label;
  final String subtitle;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color: selected ? TmColors.yellow : context.surface,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              label,
              style: GoogleFonts.inter(
                color: selected ? TmColors.black : context.textPrimary,
                fontSize: 13.5,
                fontWeight: FontWeight.w700,
                letterSpacing: 0.1,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              subtitle,
              style: GoogleFonts.inter(
                color: selected ? TmColors.black : secondaryTextColor(context),
                fontSize: 11,
                letterSpacing: 0.1,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _DateTimeField extends StatelessWidget {
  const _DateTimeField({
    required this.label,
    required this.value,
    required this.placeholder,
    required this.onTap,
    this.icon = Icons.calendar_today_rounded,
  });
  final String label;
  final String? value;
  final String placeholder;
  final VoidCallback onTap;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 12.5,
            fontWeight: FontWeight.w500,
            letterSpacing: 0.1,
          ),
        ),
        const SizedBox(height: 6),
        GestureDetector(
          onTap: onTap,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
            decoration: BoxDecoration(
              color: context.surface,
              border: Border.all(color: context.divider),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    value ?? placeholder,
                    style: GoogleFonts.inter(
                      color: value != null
                          ? context.textPrimary
                          : secondaryTextColor(context),
                      fontSize: 13,
                      fontWeight: value != null
                          ? FontWeight.w600
                          : FontWeight.w400,
                      letterSpacing: 0.1,
                    ),
                  ),
                ),
                Icon(icon, size: 14, color: secondaryTextColor(context)),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _LocationSection extends StatefulWidget {
  const _LocationSection({
    required this.pickupLatLng,
    required this.dropoffLatLng,
    required this.routePoints,
    required this.loadingRoute,
    required this.onPickupSelected,
    required this.onDropoffSelected,
    required this.onPickupCleared,
    required this.onDropoffCleared,
    required this.onReset,
    this.initialPickupAddress,
    this.initialDropoffAddress,
  });

  final LatLng? pickupLatLng;
  final LatLng? dropoffLatLng;
  final List<LatLng> routePoints;
  final bool loadingRoute;
  final void Function(LatLng, String) onPickupSelected;
  final void Function(LatLng, String) onDropoffSelected;
  final VoidCallback onPickupCleared;
  final VoidCallback onDropoffCleared;
  final VoidCallback onReset;
  final String? initialPickupAddress;
  final String? initialDropoffAddress;

  @override
  State<_LocationSection> createState() => _LocationSectionState();
}

class _LocationSectionState extends State<_LocationSection> {
  late final TextEditingController _pickupCtrl;
  late final TextEditingController _dropoffCtrl;
  final _pickupFocus = FocusNode();
  final _dropoffFocus = FocusNode();
  final _pickupLink = LayerLink();
  final _dropoffLink = LayerLink();
  OverlayEntry? _pickupOverlay;
  OverlayEntry? _dropoffOverlay;
  GoogleMapController? _mapController;

  List<Map<String, dynamic>> _pickupSuggestions = [];
  List<Map<String, dynamic>> _dropoffSuggestions = [];
  String? _pickupPlaceId;
  String? _dropoffPlaceId;
  bool _pickupSearching = false;
  bool _dropoffSearching = false;
  bool _locating = false;
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _pickupCtrl = TextEditingController(
      text: widget.initialPickupAddress ?? '',
    );
    _dropoffCtrl = TextEditingController(
      text: widget.initialDropoffAddress ?? '',
    );
  }

  @override
  void dispose() {
    _pickupOverlay?.remove();
    _dropoffOverlay?.remove();
    _pickupCtrl.dispose();
    _dropoffCtrl.dispose();
    _pickupFocus.dispose();
    _dropoffFocus.dispose();
    _debounce?.cancel();
    _mapController?.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(_LocationSection old) {
    super.didUpdateWidget(old);

    if (widget.routePoints.isNotEmpty && old.routePoints.isEmpty) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        _fitRoute(widget.routePoints);
      });
    } else if (widget.pickupLatLng != null &&
        widget.dropoffLatLng != null &&
        widget.routePoints.isEmpty &&
        (old.pickupLatLng == null || old.dropoffLatLng == null)) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        _fitRoute([widget.pickupLatLng!, widget.dropoffLatLng!]);
      });
    }

    if (widget.pickupLatLng == null && old.pickupLatLng != null) {
      _pickupCtrl.clear();
      _dropoffCtrl.clear();
      _pickupPlaceId = null;
      _dropoffPlaceId = null;
      setState(() {
        _pickupSuggestions = [];
        _dropoffSuggestions = [];
      });
      _syncOverlay(isPickup: true);
      _syncOverlay(isPickup: false);
    }
  }

  void _syncOverlay({required bool isPickup}) {
    final suggestions = isPickup ? _pickupSuggestions : _dropoffSuggestions;
    final entry = isPickup ? _pickupOverlay : _dropoffOverlay;

    if (suggestions.isEmpty) {
      entry?.remove();
      if (isPickup) {
        _pickupOverlay = null;
      } else {
        _dropoffOverlay = null;
      }
      return;
    }

    if (entry != null) {
      entry.markNeedsBuild();
      return;
    }

    final newEntry = OverlayEntry(
      builder: (_) => _buildSuggestionOverlay(isPickup),
    );
    Overlay.of(context).insert(newEntry);
    if (isPickup) {
      _pickupOverlay = newEntry;
    } else {
      _dropoffOverlay = newEntry;
    }
  }

  Widget _buildSuggestionOverlay(bool isPickup) {
    final link = isPickup ? _pickupLink : _dropoffLink;
    final suggestions = isPickup ? _pickupSuggestions : _dropoffSuggestions;
    final onSelect = isPickup ? _selectPickup : _selectDropoff;
    final width = MediaQuery.of(context).size.width - 48;

    return Positioned(
      left: 0,
      top: 0,
      width: width,
      child: CompositedTransformFollower(
        link: link,
        showWhenUnlinked: false,
        targetAnchor: Alignment.bottomLeft,
        followerAnchor: Alignment.topLeft,
        offset: const Offset(0, 6),
        child: Material(
          color: Colors.transparent,
          child: TweenAnimationBuilder<double>(
            tween: Tween(begin: 0, end: 1),
            duration: const Duration(milliseconds: 160),
            curve: Curves.easeOut,
            builder: (context, t, child) => Opacity(
              opacity: t,
              child: Transform.translate(
                offset: Offset(0, (1 - t) * -6),
                child: child,
              ),
            ),
            child: _SuggestionList(
              suggestions: suggestions,
              onSelect: onSelect,
            ),
          ),
        ),
      ),
    );
  }

  void _fitRoute(List<LatLng> points) {
    if (points.isEmpty || _mapController == null) return;
    try {
      var minLat = points.first.latitude, maxLat = points.first.latitude;
      var minLng = points.first.longitude, maxLng = points.first.longitude;
      for (final p in points) {
        if (p.latitude < minLat) minLat = p.latitude;
        if (p.latitude > maxLat) maxLat = p.latitude;
        if (p.longitude < minLng) minLng = p.longitude;
        if (p.longitude > maxLng) maxLng = p.longitude;
      }
      final bounds = LatLngBounds(
        southwest: LatLng(minLat, minLng),
        northeast: LatLng(maxLat, maxLng),
      );
      _mapController!.animateCamera(CameraUpdate.newLatLngBounds(bounds, 56));
    } catch (_) {}
  }

  void _search(String query, bool isPickup) {
    _debounce?.cancel();
    if (query.length < 2) {
      setState(
        () => isPickup ? _pickupSuggestions = [] : _dropoffSuggestions = [],
      );
      _syncOverlay(isPickup: isPickup);
      return;
    }
    setState(
      () => isPickup ? _pickupSearching = true : _dropoffSearching = true,
    );
    _debounce = Timer(const Duration(milliseconds: 420), () async {
      final results = await ApiService.autocompleteAddress(query);
      if (!mounted) return;
      final otherPlaceId = isPickup ? _dropoffPlaceId : _pickupPlaceId;
      final otherLatLng = isPickup ? widget.dropoffLatLng : widget.pickupLatLng;
      final otherLabel = (isPickup ? _dropoffCtrl.text : _pickupCtrl.text)
          .trim()
          .toLowerCase();
      final filtered = results.where((r) {
        final placeId = r['place_id'] as String?;
        if (otherPlaceId != null && placeId != null && placeId == otherPlaceId) {
          return false;
        }
        final coords = r['coordinates'] as List?;
        if (otherLatLng != null && coords != null) {
          final candidate = LatLng(
            (coords[1] as num).toDouble(),
            (coords[0] as num).toDouble(),
          );
          return !_isSameLocation(candidate, otherLatLng);
        }
        if (placeId == null && coords == null) {
          final label = (r['label'] as String? ?? '').trim().toLowerCase();
          return otherLabel.isEmpty || label != otherLabel;
        }
        return true;
      }).toList();
      setState(() {
        if (isPickup) {
          _pickupSuggestions = filtered;
          _pickupSearching = false;
        } else {
          _dropoffSuggestions = filtered;
          _dropoffSearching = false;
        }
      });
      _syncOverlay(isPickup: isPickup);
    });
  }

  Future<Map<String, dynamic>?> _resolveFeature(
    Map<String, dynamic> feature,
  ) async {
    final coords = feature['coordinates'] as List?;
    if (coords != null) {
      return {
        'lat': (coords[1] as num).toDouble(),
        'lng': (coords[0] as num).toDouble(),
        'label': feature['label'] as String? ?? '',
      };
    }

    final placeId = feature['place_id'] as String?;
    if (placeId == null || placeId.isEmpty) return null;

    final details = await ApiService.resolvePlaceDetails(placeId);
    final detailCoords = details?['coordinates'] as List?;
    if (details == null || detailCoords == null) return null;

    return {
      'lat': (detailCoords[1] as num).toDouble(),
      'lng': (detailCoords[0] as num).toDouble(),
      'label': details['label'] as String? ?? feature['label'] as String? ?? '',
    };
  }

  bool _isSameLocation(LatLng candidate, LatLng? other) {
    if (other == null) return false;
    return _BookNowScreenState._haversineKm(candidate, other) <= 0.05;
  }

  void _showSameLocationWarning() {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          'Pickup and drop-off locations must be different.',
          style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
        ),
        backgroundColor: TmColors.grey700,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        margin: const EdgeInsets.all(16),
      ),
    );
  }

  Future<void> _selectPickup(Map<String, dynamic> feature) async {
    _pickupFocus.unfocus();
    setState(() {
      _pickupSuggestions = [];
      _pickupSearching = true;
    });
    _syncOverlay(isPickup: true);
    final resolved = await _resolveFeature(feature);
    if (!mounted) return;
    setState(() => _pickupSearching = false);
    if (resolved == null) return;

    final lat = resolved['lat'] as double;
    final lng = resolved['lng'] as double;
    final label = resolved['label'] as String;
    final latlng = LatLng(lat, lng);

    if (_isSameLocation(latlng, widget.dropoffLatLng)) {
      _showSameLocationWarning();
      return;
    }

    _pickupPlaceId = feature['place_id'] as String?;
    _pickupCtrl.text = label;
    _mapController?.animateCamera(CameraUpdate.newLatLngZoom(latlng, 14));
    widget.onPickupSelected(latlng, label);
  }

  Future<void> _selectDropoff(Map<String, dynamic> feature) async {
    _dropoffFocus.unfocus();
    setState(() {
      _dropoffSuggestions = [];
      _dropoffSearching = true;
    });
    _syncOverlay(isPickup: false);
    final resolved = await _resolveFeature(feature);
    if (!mounted) return;
    setState(() => _dropoffSearching = false);
    if (resolved == null) return;

    final lat = resolved['lat'] as double;
    final lng = resolved['lng'] as double;
    final label = resolved['label'] as String;
    final latlng = LatLng(lat, lng);

    if (_isSameLocation(latlng, widget.pickupLatLng)) {
      _showSameLocationWarning();
      return;
    }

    _dropoffPlaceId = feature['place_id'] as String?;
    _dropoffCtrl.text = label;
    _mapController?.animateCamera(CameraUpdate.newLatLngZoom(latlng, 14));
    widget.onDropoffSelected(latlng, label);
  }

  void _clearPickup() {
    _pickupCtrl.clear();
    _pickupPlaceId = null;
    setState(() => _pickupSuggestions = []);
    _syncOverlay(isPickup: true);
    widget.onPickupCleared();
  }

  void _clearDropoff() {
    _dropoffCtrl.clear();
    _dropoffPlaceId = null;
    setState(() => _dropoffSuggestions = []);
    _syncOverlay(isPickup: false);
    widget.onDropoffCleared();
  }

  void _reset() {
    _pickupCtrl.clear();
    _dropoffCtrl.clear();
    _pickupPlaceId = null;
    _dropoffPlaceId = null;
    setState(() {
      _pickupSuggestions = [];
      _dropoffSuggestions = [];
    });
    _syncOverlay(isPickup: true);
    _syncOverlay(isPickup: false);
    widget.onReset();
  }

  Future<void> _useCurrentLocation() async {
    if (_locating) return;
    setState(() => _locating = true);

    try {
      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(
                'Location services are disabled. Please enable GPS.',
                style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
              ),
              backgroundColor: TmColors.grey700,
              behavior: SnackBarBehavior.floating,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(8),
              ),
              margin: const EdgeInsets.all(16),
            ),
          );
        }
        return;
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(
                'Location permission denied. Please allow it in settings.',
                style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
              ),
              backgroundColor: TmColors.grey700,
              behavior: SnackBarBehavior.floating,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(8),
              ),
              margin: const EdgeInsets.all(16),
            ),
          );
        }
        return;
      }

      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 10),
        ),
      );

      if (!mounted) return;

      final latlng = LatLng(position.latitude, position.longitude);
      final address = await ApiService.reverseGeocode(
        position.latitude,
        position.longitude,
      );

      if (!mounted) return;

      if (_isSameLocation(latlng, widget.dropoffLatLng)) {
        _showSameLocationWarning();
        return;
      }

      _pickupPlaceId = null;
      _pickupCtrl.text = address;
      _mapController?.animateCamera(CameraUpdate.newLatLngZoom(latlng, 15));
      widget.onPickupSelected(latlng, address);
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              'Could not get your location. Try again.',
              style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
            ),
            backgroundColor: TmColors.grey700,
            behavior: SnackBarBehavior.floating,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(8),
            ),
            margin: const EdgeInsets.all(16),
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final anyPin = widget.pickupLatLng != null || widget.dropoffLatLng != null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 26, 24, 0),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text('PICKUP & DROP-OFF', style: sectionEyebrowStyle(context)),
              if (widget.pickupLatLng != null)
                GestureDetector(
                  onTap: _reset,
                  behavior: HitTestBehavior.opaque,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    child: Text(
                      'Reset',
                      style: GoogleFonts.inter(
                        color: context.textPrimary,
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 0.1,
                        decoration: TextDecoration.underline,
                        decorationColor: context.divider,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(height: 12),

        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _FieldLabel(text: 'Pickup location'),
              const SizedBox(height: 6),
              CompositedTransformTarget(
                link: _pickupLink,
                child: _SearchField(
                  controller: _pickupCtrl,
                  focusNode: _pickupFocus,
                  placeholder: 'Search pickup location',
                  searching: _pickupSearching,
                  onChanged: (v) => _search(v, true),
                  onClear: _clearPickup,
                ),
              ),
              if (widget.pickupLatLng == null) ...[
                const SizedBox(height: 6),
                GestureDetector(
                  onTap: _locating ? null : _useCurrentLocation,
                  behavior: HitTestBehavior.opaque,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        _locating
                            ? const SkeletonBox(
                                width: 14,
                                height: 14,
                                borderRadius: BorderRadius.all(
                                  Radius.circular(7),
                                ),
                              )
                            : Icon(
                                Icons.my_location_rounded,
                                size: 14,
                                color: context.textPrimary,
                              ),
                        const SizedBox(width: 6),
                        Text(
                          _locating
                              ? 'Getting location...'
                              : 'Use current location',
                          style: GoogleFonts.inter(
                            color: context.textPrimary,
                            fontSize: 12,
                            fontWeight: FontWeight.w600,
                            letterSpacing: 0.1,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 12),

        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _FieldLabel(text: 'Drop-off location'),
              const SizedBox(height: 6),
              CompositedTransformTarget(
                link: _dropoffLink,
                child: _SearchField(
                  controller: _dropoffCtrl,
                  focusNode: _dropoffFocus,
                  placeholder: 'Search drop-off location',
                  searching: _dropoffSearching,
                  onChanged: (v) => _search(v, false),
                  onClear: _clearDropoff,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 20),

        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: Text('Route', style: sectionEyebrowStyle(context)),
        ),
        const SizedBox(height: 8),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(14),
            child: Container(
              height: 260,
              decoration: BoxDecoration(
                border: Border.all(color: context.divider),
              ),
              child: Stack(
                children: [
                  Positioned.fill(
                    child: GoogleMap(
                      initialCameraPosition: const CameraPosition(
                        target: LatLng(14.5995, 120.9842),
                        zoom: 13,
                      ),
                      onMapCreated: (controller) => _mapController = controller,
                      zoomControlsEnabled: false,
                      myLocationButtonEnabled: false,
                      scrollGesturesEnabled: false,
                      zoomGesturesEnabled: false,
                      rotateGesturesEnabled: false,
                      tiltGesturesEnabled: false,
                      polylines: {
                        if (widget.routePoints.isNotEmpty)
                          Polyline(
                            polylineId: const PolylineId('route'),
                            points: widget.routePoints,
                            color: Colors.black,
                            width: 3,
                          ),
                      },
                      markers: {
                        if (widget.pickupLatLng != null)
                          Marker(
                            markerId: const MarkerId('pickup'),
                            position: widget.pickupLatLng!,
                            icon: BitmapDescriptor.defaultMarkerWithHue(
                              BitmapDescriptor.hueViolet,
                            ),
                            infoWindow: const InfoWindow(title: 'Pickup'),
                          ),
                        if (widget.dropoffLatLng != null)
                          Marker(
                            markerId: const MarkerId('dropoff'),
                            position: widget.dropoffLatLng!,
                            icon: BitmapDescriptor.defaultMarkerWithHue(
                              BitmapDescriptor.hueYellow,
                            ),
                            infoWindow: const InfoWindow(title: 'Drop-off'),
                          ),
                      },
                    ),
                  ),
                  if (!anyPin)
                    Positioned.fill(
                      child: Container(
                        color: context.textPrimary.withValues(alpha: 0.85),
                        alignment: Alignment.center,
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(
                              Icons.location_on_rounded,
                              color: TmColors.yellow,
                              size: 28,
                            ),
                            const SizedBox(height: 8),
                            Text(
                              'Select your locations to see the route.',
                              style: GoogleFonts.inter(
                                color: TmColors.white,
                                fontSize: 13,
                                fontWeight: FontWeight.w500,
                                letterSpacing: 0.1,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),

        if (widget.loadingRoute)
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 12, 24, 0),
            child: Row(
              children: [
                const SkeletonBox(
                  width: 12,
                  height: 12,
                  borderRadius: BorderRadius.all(Radius.circular(6)),
                ),
                const SizedBox(width: 8),
                Text(
                  'Calculating route...',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 13,
                    fontWeight: FontWeight.w500,
                    letterSpacing: 0.1,
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}

class _FieldLabel extends StatelessWidget {
  const _FieldLabel({required this.text});
  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: GoogleFonts.inter(
        color: context.textPrimary,
        fontSize: 12.5,
        fontWeight: FontWeight.w500,
        letterSpacing: 0.1,
      ),
    );
  }
}

class _SearchField extends StatelessWidget {
  const _SearchField({
    required this.controller,
    required this.focusNode,
    required this.placeholder,
    required this.searching,
    required this.onChanged,
    required this.onClear,
    this.icon = Icons.location_on_outlined,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final String placeholder;
  final bool searching;
  final void Function(String) onChanged;
  final VoidCallback onClear;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        border: Border.all(color: context.divider),
        borderRadius: BorderRadius.circular(12),
        color: context.surface,
      ),
      child: TextField(
        controller: controller,
        focusNode: focusNode,
        onChanged: onChanged,
        style: GoogleFonts.inter(
          color: context.textPrimary,
          fontSize: 14,
          fontWeight: FontWeight.w500,
          letterSpacing: 0.1,
        ),
        decoration: InputDecoration(
          hintText: placeholder,
          hintStyle: GoogleFonts.inter(
            color: secondaryTextColor(context),
            fontSize: 14,
            letterSpacing: 0.1,
          ),
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 14,
            vertical: 13,
          ),
          border: InputBorder.none,
          prefixIcon: Icon(icon, size: 18, color: secondaryTextColor(context)),
          suffixIcon: searching
              ? const Padding(
                  padding: EdgeInsets.all(14),
                  child: SkeletonBox(
                    width: 14,
                    height: 14,
                    borderRadius: BorderRadius.all(Radius.circular(7)),
                  ),
                )
              : controller.text.isNotEmpty
              ? GestureDetector(
                  onTap: onClear,
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Icon(
                      Icons.close_rounded,
                      size: 18,
                      color: context.textPrimary,
                    ),
                  ),
                )
              : null,
        ),
      ),
    );
  }
}

class _SuggestionList extends StatelessWidget {
  const _SuggestionList({required this.suggestions, required this.onSelect});
  final List<Map<String, dynamic>> suggestions;
  final Future<void> Function(Map<String, dynamic>) onSelect;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(top: 4),
      decoration: BoxDecoration(
        border: Border.all(color: context.divider),
        borderRadius: BorderRadius.circular(12),
        color: context.surface,
      ),
      child: Column(
        children: List.generate(suggestions.length, (i) {
          final feature = suggestions[i];
          final label = feature['label'] as String? ?? '';
          final parts = label.split(', ');
          final main = parts.first;
          final detail = parts.length > 1 ? parts.skip(1).join(', ') : null;

          return GestureDetector(
            onTap: () => onSelect(feature),
            child: Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              decoration: BoxDecoration(
                border: i > 0
                    ? Border(top: BorderSide(color: context.divider))
                    : null,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    main,
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 13,
                      fontWeight: FontWeight.w500,
                      letterSpacing: 0.1,
                    ),
                  ),
                  if (detail != null) ...[
                    const SizedBox(height: 2),
                    Text(
                      detail,
                      style: GoogleFonts.inter(
                        color: secondaryTextColor(context),
                        fontSize: 11,
                        letterSpacing: 0.1,
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ],
              ),
            ),
          );
        }),
      ),
    );
  }
}

IconData _vehicleCategoryIcon(String category) {
  switch (category) {
    case '2_wheeler':
      return Icons.two_wheeler_rounded;
    case 'heavy_vehicle':
      return Icons.local_shipping_rounded;
    default:
      return Icons.directions_car_rounded;
  }
}

List<VehicleTypeModel> _filterVehicleTypes(
  List<VehicleTypeModel> all,
  String query,
) {
  final q = query.trim().toLowerCase();
  if (q.isEmpty) return all;
  return all.where((v) => v.name.toLowerCase().contains(q)).toList();
}

class _NoVehicleTypesFound extends StatelessWidget {
  const _NoVehicleTypesFound();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 16),
      child: Text(
        'No vehicle types found.',
        style: GoogleFonts.inter(
          color: context.textPrimary,
          fontSize: 13,
          fontWeight: FontWeight.w600,
          letterSpacing: 0.1,
        ),
      ),
    );
  }
}

class _VehicleTypeSection extends StatefulWidget {
  const _VehicleTypeSection({
    required this.vehicleTypes,
    required this.vehicleCategories,
    required this.loading,
    required this.selectedVehicle,
    required this.readyTruckTypeIds,
    required this.bookNowEnabled,
    required this.serviceType,
    required this.onSelect,
    required this.onScheduleEntireRequest,
    this.onRetry,
  });

  final List<VehicleTypeModel> vehicleTypes;
  final List<VehicleCategoryModel> vehicleCategories;
  final bool loading;
  final VehicleTypeModel? selectedVehicle;
  final Set<int> readyTruckTypeIds;
  final bool bookNowEnabled;
  final String serviceType;
  final void Function(VehicleTypeModel) onSelect;
  final VoidCallback onScheduleEntireRequest;
  final VoidCallback? onRetry;

  @override
  State<_VehicleTypeSection> createState() => _VehicleTypeSectionState();
}

class _VehicleTypeSectionState extends State<_VehicleTypeSection> {
  final _searchCtrl = TextEditingController();
  final _searchFocus = FocusNode();
  bool _changing = false;

  @override
  void dispose() {
    _searchCtrl.dispose();
    _searchFocus.dispose();
    super.dispose();
  }

  bool get _isSelectedAvailable {
    final vehicle = widget.selectedVehicle;
    if (vehicle == null) return true;
    if (widget.readyTruckTypeIds.isEmpty) return widget.bookNowEnabled;
    return widget.readyTruckTypeIds.contains(vehicle.requiredTruckTypeId);
  }

  void _handleSelect(VehicleTypeModel vehicle) {
    widget.onSelect(vehicle);
    setState(() => _changing = false);
  }

  Widget _vehicleTypeHeading(BuildContext context) {
    return Text('VEHICLE TYPE', style: sectionEyebrowStyle(context));
  }

  @override
  Widget build(BuildContext context) {
    final bool showSelected = widget.selectedVehicle != null && !_changing;

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 28, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'What vehicle are we towing?',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 18,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.4,
            ),
          ),
          const SizedBox(height: 20),
          if (widget.loading)
            Column(
              children: List.generate(
                3,
                (_) => const Padding(
                  padding: EdgeInsets.symmetric(vertical: 8),
                  child: SkeletonBox(
                    width: double.infinity,
                    height: 44,
                    borderRadius: BorderRadius.all(Radius.circular(8)),
                  ),
                ),
              ),
            )
          else if (widget.vehicleTypes.isEmpty)
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                border: Border.all(
                  color: TmColors.error.withValues(alpha: 0.4),
                  width: 1.5,
                ),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Could not load vehicle types.',
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  if (widget.onRetry != null) ...[
                    const SizedBox(height: 10),
                    GestureDetector(
                      onTap: widget.onRetry,
                      child: Text(
                        'Tap to retry',
                        style: GoogleFonts.inter(
                          color: TmColors.yellow,
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                          decoration: TextDecoration.underline,
                          decorationColor: TmColors.yellow,
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            )
          else ...[
            if (showSelected) ...[
              Text('VEHICLE TYPE', style: sectionEyebrowStyle(context)),
              const SizedBox(height: 8),
              _SelectedVehicleTypeRow(
                vehicle: widget.selectedVehicle!,
                onChange: () => setState(() => _changing = true),
              ),
              if (widget.serviceType == 'book_now') ...[
                const SizedBox(height: 16),
                _AvailabilityStatusCard(available: _isSelectedAvailable),
                if (!_isSelectedAvailable) ...[
                  const SizedBox(height: 10),
                  GestureDetector(
                    onTap: widget.onScheduleEntireRequest,
                    behavior: HitTestBehavior.opaque,
                    child: Text(
                      'Schedule entire request',
                      style: GoogleFonts.inter(
                        color: TmColors.yellow,
                        fontSize: 12.5,
                        fontWeight: FontWeight.w700,
                        decoration: TextDecoration.underline,
                        decorationColor: TmColors.yellow,
                      ),
                    ),
                  ),
                ],
              ],
            ] else ...[
              _vehicleTypeHeading(context),
              const SizedBox(height: 8),
              _SearchField(
                controller: _searchCtrl,
                focusNode: _searchFocus,
                placeholder: 'Search vehicle type',
                searching: false,
                icon: Icons.search_rounded,
                onChanged: (_) => setState(() {}),
                onClear: () => setState(_searchCtrl.clear),
              ),
              const SizedBox(height: 10),
              _VehicleCategoryList(
                vehicleTypes: widget.vehicleTypes,
                vehicleCategories: widget.vehicleCategories,
                query: _searchCtrl.text,
                selectedId: widget.selectedVehicle?.id,
                onSelect: _handleSelect,
              ),
            ],
          ],
        ],
      ),
    );
  }
}

String _vehicleCategoryLabel(String category) {
  switch (category) {
    case '2_wheeler':
      return '2-Wheeler';
    case 'heavy_vehicle':
      return 'Heavy Vehicle';
    case '4_wheeler':
      return '4-Wheeler';
    default:
      return '';
  }
}

class _VehicleTypeRow extends StatelessWidget {
  const _VehicleTypeRow({
    required this.vehicle,
    required this.selected,
    required this.onTap,
    this.showCategoryLabel = true,
  });

  final VehicleTypeModel vehicle;
  final bool selected;
  final VoidCallback onTap;
  final bool showCategoryLabel;

  @override
  Widget build(BuildContext context) {
    final categoryLabel = showCategoryLabel
        ? _vehicleCategoryLabel(vehicle.category)
        : '';
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 12),
        decoration: BoxDecoration(
          border: Border(
            bottom: BorderSide(color: context.divider, width: 0.5),
          ),
        ),
        child: Row(
          children: [
            Icon(
              _vehicleCategoryIcon(vehicle.category),
              size: 20,
              color: selected ? TmColors.yellow : context.textPrimary,
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    vehicle.name,
                    overflow: TextOverflow.ellipsis,
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 14,
                      fontWeight: selected ? FontWeight.w700 : FontWeight.w600,
                      letterSpacing: -0.1,
                    ),
                  ),
                  if (categoryLabel.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      categoryLabel,
                      style: GoogleFonts.inter(
                        color: secondaryTextColor(context),
                        fontSize: 12,
                        letterSpacing: 0.1,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(width: 8),
            if (selected)
              const Icon(
                Icons.check_circle_rounded,
                size: 20,
                color: TmColors.yellow,
              )
            else
              Icon(
                Icons.chevron_right_rounded,
                size: 20,
                color: secondaryTextColor(context),
              ),
          ],
        ),
      ),
    );
  }
}

class _VehicleCategoryGroup {
  const _VehicleCategoryGroup({
    required this.slug,
    required this.name,
    required this.vehicles,
  });

  final String slug;
  final String name;
  final List<VehicleTypeModel> vehicles;
}

List<_VehicleCategoryGroup> _groupVehiclesByCategory(
  List<VehicleCategoryModel> categories,
  List<VehicleTypeModel> vehicles,
) {
  final byCategory = <String, List<VehicleTypeModel>>{};
  for (final v in vehicles) {
    byCategory.putIfAbsent(v.category, () => []).add(v);
  }

  final groups = <_VehicleCategoryGroup>[];
  final seen = <String>{};
  for (final c in categories) {
    final vs = byCategory[c.slug];
    if (vs == null || vs.isEmpty) continue;
    groups.add(_VehicleCategoryGroup(slug: c.slug, name: c.name, vehicles: vs));
    seen.add(c.slug);
  }
  for (final entry in byCategory.entries) {
    if (seen.contains(entry.key)) continue;
    final fallbackLabel = _vehicleCategoryLabel(entry.key);
    groups.add(
      _VehicleCategoryGroup(
        slug: entry.key,
        name: fallbackLabel.isNotEmpty ? fallbackLabel : entry.key,
        vehicles: entry.value,
      ),
    );
  }
  return groups;
}

class _VehicleCategoryList extends StatefulWidget {
  const _VehicleCategoryList({
    required this.vehicleTypes,
    required this.vehicleCategories,
    required this.query,
    required this.selectedId,
    required this.onSelect,
  });

  final List<VehicleTypeModel> vehicleTypes;
  final List<VehicleCategoryModel> vehicleCategories;
  final String query;
  final int? selectedId;
  final void Function(VehicleTypeModel) onSelect;

  @override
  State<_VehicleCategoryList> createState() => _VehicleCategoryListState();
}

class _VehicleCategoryListState extends State<_VehicleCategoryList> {
  String? _expandedSlug;

  @override
  Widget build(BuildContext context) {
    if (widget.query.trim().isNotEmpty) {
      final filtered = _filterVehicleTypes(widget.vehicleTypes, widget.query);
      if (filtered.isEmpty) return const _NoVehicleTypesFound();
      return Column(
        children: filtered
            .map(
              (v) => _VehicleTypeRow(
                vehicle: v,
                selected: widget.selectedId == v.id,
                onTap: () => widget.onSelect(v),
              ),
            )
            .toList(),
      );
    }

    final groups = _groupVehiclesByCategory(
      widget.vehicleCategories,
      widget.vehicleTypes,
    );

    if (groups.isEmpty) return const _NoVehicleTypesFound();

    return Column(
      children: groups.map((group) {
        final isExpanded = _expandedSlug == group.slug;
        return _VehicleCategoryGroupTile(
          group: group,
          expanded: isExpanded,
          selectedId: widget.selectedId,
          onToggle: () => setState(() {
            _expandedSlug = isExpanded ? null : group.slug;
          }),
          onSelectVehicle: widget.onSelect,
        );
      }).toList(),
    );
  }
}

class _VehicleCategoryGroupTile extends StatelessWidget {
  const _VehicleCategoryGroupTile({
    required this.group,
    required this.expanded,
    required this.selectedId,
    required this.onToggle,
    required this.onSelectVehicle,
  });

  final _VehicleCategoryGroup group;
  final bool expanded;
  final int? selectedId;
  final VoidCallback onToggle;
  final void Function(VehicleTypeModel) onSelectVehicle;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        GestureDetector(
          onTap: onToggle,
          behavior: HitTestBehavior.opaque,
          child: Container(
            padding: const EdgeInsets.symmetric(vertical: 12),
            decoration: BoxDecoration(
              border: Border(
                bottom: BorderSide(color: context.divider, width: 0.5),
              ),
            ),
            child: Row(
              children: [
                Icon(
                  _vehicleCategoryIcon(group.slug),
                  size: 20,
                  color: context.textPrimary,
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        group.name,
                        overflow: TextOverflow.ellipsis,
                        style: GoogleFonts.inter(
                          color: context.textPrimary,
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                          letterSpacing: -0.1,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        '${group.vehicles.length} option${group.vehicles.length == 1 ? '' : 's'}',
                        style: GoogleFonts.inter(
                          color: secondaryTextColor(context),
                          fontSize: 12,
                          letterSpacing: 0.1,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                AnimatedRotation(
                  turns: expanded ? 0.5 : 0,
                  duration: const Duration(milliseconds: 180),
                  child: Icon(
                    Icons.expand_more_rounded,
                    size: 20,
                    color: secondaryTextColor(context),
                  ),
                ),
              ],
            ),
          ),
        ),
        AnimatedSize(
          duration: const Duration(milliseconds: 200),
          curve: Curves.easeOut,
          alignment: Alignment.topCenter,
          child: expanded
              ? Padding(
                  padding: const EdgeInsets.only(left: 34, top: 4, bottom: 4),
                  child: Column(
                    children: group.vehicles
                        .map(
                          (v) => _VehicleTypeRow(
                            vehicle: v,
                            selected: selectedId == v.id,
                            onTap: () => onSelectVehicle(v),
                            showCategoryLabel: false,
                          ),
                        )
                        .toList(),
                  ),
                )
              : const SizedBox.shrink(),
        ),
      ],
    );
  }
}

class _AvailabilityStatusCard extends StatelessWidget {
  const _AvailabilityStatusCard({required this.available});

  final bool available;

  @override
  Widget build(BuildContext context) {
    final Color color = available
        ? TmColors.success
        : secondaryTextColor(context);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: context.surface,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Icon(
            available
                ? Icons.check_circle_rounded
                : Icons.remove_circle_outline_rounded,
            size: 18,
            color: color,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  available
                      ? 'Available for Book Now'
                      : 'Not available for Book Now',
                  style: GoogleFonts.inter(
                    color: color,
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 0.1,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  available
                      ? 'A suitable towing unit is currently available.'
                      : 'No suitable towing unit is available right now.',
                  style: GoogleFonts.inter(
                    color: secondaryTextColor(context),
                    fontSize: 12,
                    height: 1.35,
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

class _SelectedVehicleTypeRow extends StatelessWidget {
  const _SelectedVehicleTypeRow({
    required this.vehicle,
    required this.onChange,
  });

  final VehicleTypeModel vehicle;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
      decoration: BoxDecoration(
        color: context.card,
        border: Border.all(color: context.divider),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          Icon(
            _vehicleCategoryIcon(vehicle.category),
            size: 18,
            color: context.textPrimary,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              vehicle.name,
              overflow: TextOverflow.ellipsis,
              style: GoogleFonts.inter(
                color: context.textPrimary,
                fontSize: 13.5,
                fontWeight: FontWeight.w700,
                letterSpacing: -0.1,
              ),
            ),
          ),
          const SizedBox(width: 10),
          GestureDetector(
            onTap: onChange,
            behavior: HitTestBehavior.opaque,
            child: Text(
              'Change',
              style: GoogleFonts.inter(
                color: context.textPrimary,
                fontSize: 12.5,
                fontWeight: FontWeight.w600,
                letterSpacing: 0.1,
                decoration: TextDecoration.underline,
                decorationColor: context.divider,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _VehicleImageSection extends StatelessWidget {
  const _VehicleImageSection({
    required this.images,
    required this.hasError,
    required this.onAddTap,
    required this.onRemove,
  });

  final List<XFile> images;
  final bool hasError;
  final VoidCallback onAddTap;
  final void Function(int) onRemove;

  @override
  Widget build(BuildContext context) {
    final bool showError = hasError && images.isEmpty;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text('VEHICLE PHOTOS', style: sectionEyebrowStyle(context)),
              if (images.isNotEmpty)
                Text(
                  '${images.length} / 5 photos',
                  style: GoogleFonts.inter(
                    color: secondaryTextColor(context),
                    fontSize: 12,
                    letterSpacing: 0.1,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            'At least 1 photo required · Up to 5 photos',
            style: GoogleFonts.inter(
              color: secondaryTextColor(context),
              fontSize: 12.5,
              letterSpacing: 0.1,
            ),
          ),
          const SizedBox(height: 10),
          if (images.isEmpty)
            GestureDetector(
              onTap: onAddTap,
              child: Container(
                width: double.infinity,
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  color: context.surface,
                  border: Border.all(
                    color: showError ? TmColors.error : context.divider,
                    width: 1.5,
                  ),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Row(
                  children: [
                    Icon(
                      Icons.add_a_photo_rounded,
                      size: 26,
                      color: showError ? TmColors.error : context.textPrimary,
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Add vehicle photos',
                            style: GoogleFonts.inter(
                              color: showError
                                  ? TmColors.error
                                  : context.textPrimary,
                              fontSize: 14,
                              fontWeight: FontWeight.w700,
                              letterSpacing: -0.1,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            'Take a photo or choose from gallery',
                            style: GoogleFonts.inter(
                              color: secondaryTextColor(context),
                              fontSize: 12.5,
                              letterSpacing: 0.1,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            )
          else
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                ...List.generate(
                  images.length,
                  (i) =>
                      _ImageThumb(file: images[i], onRemove: () => onRemove(i)),
                ),
                if (images.length < 5)
                  GestureDetector(
                    onTap: onAddTap,
                    child: Container(
                      width: 88,
                      height: 88,
                      decoration: BoxDecoration(
                        color: context.surface,
                        border: Border.all(color: context.divider, width: 1.5),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(
                            Icons.add_rounded,
                            size: 22,
                            color: context.textPrimary,
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'Add more',
                            style: GoogleFonts.inter(
                              color: context.textPrimary,
                              fontSize: 11,
                              fontWeight: FontWeight.w600,
                              letterSpacing: 0.1,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
              ],
            ),
          if (showError) ...[
            const SizedBox(height: 6),
            Text(
              'At least 1 vehicle photo is required.',
              style: GoogleFonts.inter(
                color: TmColors.error,
                fontSize: 11,
                letterSpacing: 0.1,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _ImageThumb extends StatelessWidget {
  const _ImageThumb({required this.file, required this.onRemove});
  final XFile file;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: 1),
      duration: const Duration(milliseconds: 180),
      curve: Curves.easeOut,
      builder: (context, t, child) => Opacity(opacity: t, child: child),
      child: SizedBox(
        width: 88,
        height: 88,
        child: Stack(
          children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(10),
              child: kIsWeb
                  ? Image.network(
                      file.path,
                      width: 88,
                      height: 88,
                      fit: BoxFit.cover,
                    )
                  : Image.file(
                      File(file.path),
                      width: 88,
                      height: 88,
                      fit: BoxFit.cover,
                    ),
            ),
            Positioned(
              top: 4,
              right: 4,
              child: GestureDetector(
                onTap: onRemove,
                child: Container(
                  width: 22,
                  height: 22,
                  decoration: BoxDecoration(
                    color: TmColors.black.withValues(alpha: 0.7),
                    borderRadius: BorderRadius.circular(11),
                  ),
                  alignment: Alignment.center,
                  child: const Icon(
                    Icons.close_rounded,
                    color: TmColors.white,
                    size: 14,
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

class _ExtraVehiclesSection extends StatefulWidget {
  const _ExtraVehiclesSection({
    required this.extraVehicles,
    required this.vehicleTypes,
    required this.vehicleCategories,
    required this.canAdd,
    required this.vehicleCount,
    required this.readyTruckTypeIds,
    required this.requestServiceType,
    required this.onAdd,
    required this.onRemove,
    required this.onVehicleSet,
    required this.onScheduleEntireRequest,
    required this.onAddPhotoTap,
    required this.onRemovePhoto,
  });

  final List<_ExtraVehicleData> extraVehicles;
  final List<VehicleTypeModel> vehicleTypes;
  final List<VehicleCategoryModel> vehicleCategories;
  final bool canAdd;
  final int vehicleCount;
  final Set<int> readyTruckTypeIds;
  final String requestServiceType;
  final VoidCallback onAdd;
  final void Function(int) onRemove;
  final void Function(int, VehicleTypeModel) onVehicleSet;
  final VoidCallback onScheduleEntireRequest;
  final void Function(int) onAddPhotoTap;
  final void Function(int, int) onRemovePhoto;

  @override
  State<_ExtraVehiclesSection> createState() => _ExtraVehiclesSectionState();
}

class _ExtraVehiclesSectionState extends State<_ExtraVehiclesSection> {
  _ExtraVehicleData? _expanded;
  late int _lastCount;

  @override
  void initState() {
    super.initState();
    _lastCount = widget.extraVehicles.length;
  }

  @override
  void didUpdateWidget(_ExtraVehiclesSection old) {
    super.didUpdateWidget(old);
    final currentCount = widget.extraVehicles.length;
    if (currentCount > _lastCount) {
      _expanded = widget.extraVehicles.last;
    } else if (_expanded != null && !widget.extraVehicles.contains(_expanded)) {
      _expanded = null;
    }
    _lastCount = currentCount;
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Flexible(
                child: Text(
                  'ADDITIONAL VEHICLES',
                  overflow: TextOverflow.ellipsis,
                  style: sectionEyebrowStyle(context),
                ),
              ),
              const SizedBox(width: 8),
              Text(
                '${widget.vehicleCount} of 6 vehicles',
                style: GoogleFonts.inter(
                  color: widget.vehicleCount >= 6
                      ? TmColors.error
                      : secondaryTextColor(context),
                  fontSize: 11,
                  letterSpacing: 0.4,
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            'You can add up to 5 more vehicles to tow.',
            style: GoogleFonts.inter(
              color: secondaryTextColor(context),
              fontSize: 12.5,
              letterSpacing: 0.1,
            ),
          ),
          ...List.generate(widget.extraVehicles.length, (i) {
            final data = widget.extraVehicles[i];
            return _ExtraVehicleSlot(
              key: ObjectKey(data),
              index: i,
              data: data,
              vehicleTypes: widget.vehicleTypes,
              vehicleCategories: widget.vehicleCategories,
              expanded: identical(_expanded, data),
              onToggleExpand: () => setState(() {
                _expanded = identical(_expanded, data) ? null : data;
              }),
              onRemove: () => widget.onRemove(i),
              onVehicleSet: (vehicle) => widget.onVehicleSet(i, vehicle),
              onScheduleEntireRequest: widget.onScheduleEntireRequest,
              readyTruckTypeIds: widget.readyTruckTypeIds,
              requestServiceType: widget.requestServiceType,
              onAddPhotoTap: () => widget.onAddPhotoTap(i),
              onRemovePhoto: (photoIndex) =>
                  widget.onRemovePhoto(i, photoIndex),
            );
          }),
          const SizedBox(height: 14),
          if (widget.canAdd)
            GestureDetector(
              onTap: widget.onAdd,
              behavior: HitTestBehavior.opaque,
              child: Container(
                padding: const EdgeInsets.symmetric(vertical: 13),
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: context.surface,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(
                      Icons.add_rounded,
                      size: 16,
                      color: context.textPrimary,
                    ),
                    const SizedBox(width: 6),
                    Text(
                      'Add another vehicle',
                      style: GoogleFonts.inter(
                        color: context.textPrimary,
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 0.1,
                      ),
                    ),
                  ],
                ),
              ),
            )
          else
            Container(
              padding: const EdgeInsets.symmetric(vertical: 13),
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: context.surface,
                borderRadius: BorderRadius.circular(10),
              ),
              child: Text(
                'Maximum 6 vehicles reached',
                style: GoogleFonts.inter(
                  color: secondaryTextColor(context),
                  fontSize: 13,
                  letterSpacing: 0.1,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _ExtraVehicleSlot extends StatefulWidget {
  const _ExtraVehicleSlot({
    super.key,
    required this.index,
    required this.data,
    required this.vehicleTypes,
    required this.vehicleCategories,
    required this.expanded,
    required this.onToggleExpand,
    required this.onRemove,
    required this.onVehicleSet,
    required this.onScheduleEntireRequest,
    required this.readyTruckTypeIds,
    required this.requestServiceType,
    required this.onAddPhotoTap,
    required this.onRemovePhoto,
  });

  final int index;
  final _ExtraVehicleData data;
  final List<VehicleTypeModel> vehicleTypes;
  final List<VehicleCategoryModel> vehicleCategories;
  final bool expanded;
  final VoidCallback onToggleExpand;
  final VoidCallback onRemove;
  final void Function(VehicleTypeModel) onVehicleSet;
  final VoidCallback onScheduleEntireRequest;
  final Set<int> readyTruckTypeIds;
  final String requestServiceType;
  final VoidCallback onAddPhotoTap;
  final void Function(int) onRemovePhoto;

  @override
  State<_ExtraVehicleSlot> createState() => _ExtraVehicleSlotState();
}

class _ExtraVehicleSlotState extends State<_ExtraVehicleSlot> {
  final _searchCtrl = TextEditingController();
  final _searchFocus = FocusNode();
  bool _changing = false;

  @override
  void dispose() {
    _searchCtrl.dispose();
    _searchFocus.dispose();
    super.dispose();
  }

  bool _isAvailable(VehicleTypeModel vehicle) {
    if (widget.readyTruckTypeIds.isEmpty) return true;
    return widget.readyTruckTypeIds.contains(vehicle.requiredTruckTypeId);
  }

  @override
  Widget build(BuildContext context) {
    final index = widget.index;
    final data = widget.data;
    final vehicleTypes = widget.vehicleTypes;
    final expanded = widget.expanded;
    final onToggleExpand = widget.onToggleExpand;
    final onRemove = widget.onRemove;
    final onVehicleSet = widget.onVehicleSet;
    final onAddPhotoTap = widget.onAddPhotoTap;
    final onRemovePhoto = widget.onRemovePhoto;
    final hasVehicle = data.vehicle != null;
    final hasPhotos = data.images.isNotEmpty;
    final bool isAvailableForBookNow =
        hasVehicle && _isAvailable(data.vehicle!);
    final bool bookingReady = widget.requestServiceType == 'schedule'
        ? true
        : isAvailableForBookNow;
    final bool isComplete = hasVehicle && hasPhotos && bookingReady;
    final String statusLabel = !hasVehicle
        ? 'Vehicle type required'
        : !hasPhotos
        ? 'Photo required'
        : !bookingReady
        ? 'Unavailable'
        : 'Complete';
    final String subtitle = !hasVehicle
        ? 'Tap to add vehicle type & photos'
        : '${data.vehicle!.name} / '
              '${data.images.length} photo${data.images.length == 1 ? '' : 's'} / '
              '$statusLabel';

    void handleVehicleTap(VehicleTypeModel v) {
      onVehicleSet(v);
      setState(() => _changing = false);
    }

    return Container(
      margin: const EdgeInsets.only(top: 12),
      decoration: BoxDecoration(
        color: context.surface,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          GestureDetector(
            onTap: onToggleExpand,
            behavior: HitTestBehavior.opaque,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(14, 12, 10, 12),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Vehicle ${index + 2}',
                          style: GoogleFonts.inter(
                            color: context.textPrimary,
                            fontSize: 13,
                            fontWeight: FontWeight.w600,
                            letterSpacing: 0.1,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          subtitle,
                          style: GoogleFonts.inter(
                            color: !hasVehicle
                                ? secondaryTextColor(context)
                                : isComplete
                                ? TmColors.success
                                : TmColors.destructive,
                            fontSize: 12,
                            fontWeight: FontWeight.w600,
                            letterSpacing: 0.1,
                          ),
                        ),
                      ],
                    ),
                  ),
                  GestureDetector(
                    onTap: onRemove,
                    behavior: HitTestBehavior.opaque,
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 8,
                        vertical: 8,
                      ),
                      child: Text(
                        'Remove',
                        style: GoogleFonts.inter(
                          color: TmColors.destructive,
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          letterSpacing: 0.1,
                        ),
                      ),
                    ),
                  ),
                  AnimatedRotation(
                    turns: expanded ? 0.5 : 0,
                    duration: const Duration(milliseconds: 180),
                    child: Icon(
                      Icons.expand_more_rounded,
                      size: 20,
                      color: secondaryTextColor(context),
                    ),
                  ),
                ],
              ),
            ),
          ),
          AnimatedSize(
            duration: const Duration(milliseconds: 200),
            curve: Curves.easeOut,
            alignment: Alignment.topCenter,
            child: expanded
                ? Padding(
                    padding: const EdgeInsets.only(bottom: 14),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 14),
                          child: Container(height: 0.5, color: context.divider),
                        ),
                        const SizedBox(height: 12),
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 14),
                          child: Text(
                            'VEHICLE TYPE',
                            style: sectionEyebrowStyle(context),
                          ),
                        ),
                        const SizedBox(height: 8),
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 14),
                          child: (hasVehicle && !_changing)
                              ? _SelectedVehicleTypeRow(
                                  vehicle: data.vehicle!,
                                  onChange: () =>
                                      setState(() => _changing = true),
                                )
                              : Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    _SearchField(
                                      controller: _searchCtrl,
                                      focusNode: _searchFocus,
                                      placeholder: 'Search vehicle type',
                                      searching: false,
                                      icon: Icons.search_rounded,
                                      onChanged: (_) => setState(() {}),
                                      onClear: () =>
                                          setState(_searchCtrl.clear),
                                    ),
                                    const SizedBox(height: 10),
                                    _VehicleCategoryList(
                                      vehicleTypes: vehicleTypes,
                                      vehicleCategories:
                                          widget.vehicleCategories,
                                      query: _searchCtrl.text,
                                      selectedId: data.vehicle?.id,
                                      onSelect: handleVehicleTap,
                                    ),
                                  ],
                                ),
                        ),
                        if (hasVehicle && !_changing) ...[
                          const SizedBox(height: 12),
                          if (widget.requestServiceType == 'book_now') ...[
                            Padding(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 14,
                              ),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  _AvailabilityStatusCard(
                                    available: _isAvailable(data.vehicle!),
                                  ),
                                  if (!_isAvailable(data.vehicle!)) ...[
                                    const SizedBox(height: 10),
                                    GestureDetector(
                                      onTap: widget.onScheduleEntireRequest,
                                      behavior: HitTestBehavior.opaque,
                                      child: Text(
                                        'Schedule entire request',
                                        style: GoogleFonts.inter(
                                          color: TmColors.yellow,
                                          fontSize: 12.5,
                                          fontWeight: FontWeight.w700,
                                          decoration: TextDecoration.underline,
                                          decorationColor: TmColors.yellow,
                                        ),
                                      ),
                                    ),
                                  ],
                                ],
                              ),
                            ),
                          ] else
                            Padding(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 14,
                              ),
                              child: Text(
                                'This vehicle will be scheduled with the rest of your request.',
                                style: GoogleFonts.inter(
                                  color: secondaryTextColor(context),
                                  fontSize: 12.5,
                                  height: 1.4,
                                ),
                              ),
                            ),
                        ],
                        const SizedBox(height: 16),
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 14),
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(
                                'VEHICLE PHOTOS',
                                style: sectionEyebrowStyle(context),
                              ),
                              if (data.images.isNotEmpty)
                                Text(
                                  '${data.images.length} / 5 photos',
                                  style: GoogleFonts.inter(
                                    color: secondaryTextColor(context),
                                    fontSize: 12,
                                    letterSpacing: 0.1,
                                  ),
                                ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 10),
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 14),
                          child: data.images.isEmpty
                              ? GestureDetector(
                                  onTap: onAddPhotoTap,
                                  child: Container(
                                    width: double.infinity,
                                    padding: const EdgeInsets.all(16),
                                    decoration: BoxDecoration(
                                      color: context.card,
                                      border: Border.all(
                                        color: data.imageError
                                            ? TmColors.error
                                            : context.divider,
                                        width: 1.5,
                                      ),
                                      borderRadius: BorderRadius.circular(12),
                                    ),
                                    child: Row(
                                      children: [
                                        Icon(
                                          Icons.add_a_photo_rounded,
                                          size: 22,
                                          color: data.imageError
                                              ? TmColors.error
                                              : context.textPrimary,
                                        ),
                                        const SizedBox(width: 12),
                                        Expanded(
                                          child: Text(
                                            'Add vehicle photos',
                                            style: GoogleFonts.inter(
                                              color: data.imageError
                                                  ? TmColors.error
                                                  : context.textPrimary,
                                              fontSize: 13,
                                              fontWeight: FontWeight.w700,
                                              letterSpacing: -0.1,
                                            ),
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                )
                              : Wrap(
                                  spacing: 8,
                                  runSpacing: 8,
                                  children: [
                                    ...List.generate(
                                      data.images.length,
                                      (i) => _ImageThumb(
                                        file: data.images[i],
                                        onRemove: () => onRemovePhoto(i),
                                      ),
                                    ),
                                    if (data.images.length < 5)
                                      GestureDetector(
                                        onTap: onAddPhotoTap,
                                        child: Container(
                                          width: 88,
                                          height: 88,
                                          decoration: BoxDecoration(
                                            color: context.card,
                                            border: Border.all(
                                              color: context.divider,
                                              width: 1.5,
                                            ),
                                            borderRadius: BorderRadius.circular(
                                              10,
                                            ),
                                          ),
                                          child: Column(
                                            mainAxisAlignment:
                                                MainAxisAlignment.center,
                                            children: [
                                              Icon(
                                                Icons.add_rounded,
                                                size: 22,
                                                color: context.textPrimary,
                                              ),
                                              const SizedBox(height: 4),
                                              Text(
                                                'Add more',
                                                style: GoogleFonts.inter(
                                                  color: context.textPrimary,
                                                  fontSize: 11,
                                                  fontWeight: FontWeight.w600,
                                                  letterSpacing: 0.1,
                                                ),
                                              ),
                                            ],
                                          ),
                                        ),
                                      ),
                                  ],
                                ),
                        ),
                      ],
                    ),
                  )
                : const SizedBox(width: double.infinity),
          ),
        ],
      ),
    );
  }
}

class _NotesSection extends StatelessWidget {
  const _NotesSection({required this.controller});
  final TextEditingController controller;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 28, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('SPECIAL NOTES', style: sectionEyebrowStyle(context)),
          const SizedBox(height: 10),
          Container(
            decoration: BoxDecoration(
              border: Border.all(color: context.divider),
              borderRadius: BorderRadius.circular(6),
            ),
            child: TextField(
              controller: controller,
              minLines: 2,
              maxLines: 5,
              style: GoogleFonts.inter(
                color: context.textPrimary,
                fontSize: 14,
                letterSpacing: 0.1,
              ),
              decoration: InputDecoration(
                hintText: 'Add instructions for the towing team',
                hintStyle: GoogleFonts.inter(
                  color: secondaryTextColor(context),
                  fontSize: 14,
                  letterSpacing: 0.1,
                ),
                contentPadding: const EdgeInsets.all(14),
                border: InputBorder.none,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _StepIndicator extends StatelessWidget {
  const _StepIndicator({required this.step});
  final int step;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(32, 12, 32, 4),
      child: Row(
        children: [
          _StepDot(index: 0, currentStep: step, label: 'Location'),
          _StepLine(done: step > 0),
          _StepDot(index: 1, currentStep: step, label: 'Vehicle'),
          _StepLine(done: step > 1),
          _StepDot(index: 2, currentStep: step, label: 'Review'),
        ],
      ),
    );
  }
}

class _StepDot extends StatelessWidget {
  const _StepDot({
    required this.index,
    required this.currentStep,
    required this.label,
  });
  final int index;
  final int currentStep;
  final String label;

  @override
  Widget build(BuildContext context) {
    final bool active = index == currentStep;
    final bool done = index < currentStep;
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        AnimatedContainer(
          duration: const Duration(milliseconds: 200),
          width: 28,
          height: 28,
          decoration: BoxDecoration(
            color: active
                ? TmColors.yellow
                : done
                ? context.textPrimary
                : context.surface,
            shape: BoxShape.circle,
            border: (!active && !done)
                ? Border.all(color: context.divider, width: 1.5)
                : null,
          ),
          child: Center(
            child: done
                ? Icon(Icons.check_rounded, color: context.bg, size: 14)
                : Text(
                    '${index + 1}',
                    style: GoogleFonts.inter(
                      color: active
                          ? TmColors.black
                          : secondaryTextColor(context),
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      letterSpacing: -0.1,
                    ),
                  ),
          ),
        ),
        const SizedBox(height: 4),
        Text(
          label,
          style: GoogleFonts.inter(
            color: active ? context.textPrimary : secondaryTextColor(context),
            fontSize: 10,
            fontWeight: active ? FontWeight.w700 : FontWeight.w500,
            letterSpacing: 0.3,
          ),
        ),
      ],
    );
  }
}

class _StepLine extends StatelessWidget {
  const _StepLine({required this.done});
  final bool done;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Container(
        height: 2,
        margin: const EdgeInsets.only(bottom: 20, left: 4, right: 4),
        color: done ? context.textPrimary : context.divider,
      ),
    );
  }
}

class _PriceBreakdown extends StatelessWidget {
  const _PriceBreakdown({
    required this.pricing,
    required this.bookNowVehiclePreviews,
    required this.scheduledExtraPreviews,
    required this.vehicleTypes,
    required this.bookNowVehicleCount,
    required this.priceFmt,
    required this.serviceType,
    required this.primaryVehicleName,
  });

  final Map<String, dynamic> pricing;
  final List<Map<String, dynamic>> bookNowVehiclePreviews;
  final List<Map<String, dynamic>> scheduledExtraPreviews;
  final List<VehicleTypeModel> vehicleTypes;
  final int bookNowVehicleCount;
  final NumberFormat priceFmt;
  final String serviceType;
  final String primaryVehicleName;

  static double _num(dynamic v) => (v as num?)?.toDouble() ?? 0.0;

  String _vehicleName(int vehicleTypeId) {
    for (final v in vehicleTypes) {
      if (v.id == vehicleTypeId) return v.name;
    }
    return 'Vehicle';
  }

  @override
  Widget build(BuildContext context) {
    if (serviceType == 'schedule' ||
        scheduledExtraPreviews.isNotEmpty ||
        bookNowVehiclePreviews.length > 1) {
      return _buildScheduledBreakdown(context);
    }
    return _buildBookNowBreakdown(context);
  }

  Widget _buildBookNowBreakdown(BuildContext context) {
    final baseRate = _num(pricing['base_rate']);
    final baseRateTotal = _num(pricing['base_rate_total']);
    final distanceFee = _num(pricing['distance_fee']);
    final additionalFee = _num(pricing['additional_fee']);
    final discountAmount = _num(pricing['discount_amount']);
    final vatAmount = _num(pricing['vat_amount']);
    final finalTotal = _num(pricing['final_total']);
    final bool combinedBaseRate = bookNowVehicleCount > 1 && baseRateTotal > 0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _BRow(
          label: combinedBaseRate
              ? 'Base Rate ($bookNowVehicleCount vehicles)'
              : 'Base Rate',
          value:
              '₱${priceFmt.format(combinedBaseRate ? baseRateTotal : baseRate)}',
        ),
        _BRow(
          label: 'Distance Fee',
          value: '₱${priceFmt.format(distanceFee)}',
          caption: 'First 4 km included.',
        ),
        if (discountAmount > 0)
          _BRow(
            label: 'Discount',
            value: '-₱${priceFmt.format(discountAmount)}',
          ),
        if (additionalFee != 0)
          _BRow(
            label: 'Additional Fee',
            value: '₱${priceFmt.format(additionalFee)}',
          ),
        _BRow(label: 'VAT (12%)', value: '₱${priceFmt.format(vatAmount)}'),
        const SizedBox(height: 4),
        Container(height: 1, color: context.divider),
        const SizedBox(height: 12),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Text(
                'Total',
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                  letterSpacing: -0.1,
                ),
              ),
            ),
            const SizedBox(width: 8),
            Text(
              '₱${priceFmt.format(finalTotal)}',
              style: GoogleFonts.inter(
                color: context.textPrimary,
                fontSize: 19,
                fontWeight: FontWeight.w700,
                letterSpacing: -0.4,
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildScheduledBreakdown(BuildContext context) {
    final vehiclePreviews = serviceType == 'book_now'
        ? bookNowVehiclePreviews
        : <Map<String, dynamic>>[];
    final names = <String>[
      primaryVehicleName,
      for (final ev in serviceType == 'book_now'
          ? vehiclePreviews.skip(1)
          : scheduledExtraPreviews)
        _vehicleName((ev['vehicle_type_id'] as num? ?? 0).toInt()),
    ];
    final multiVehicle = names.length > 1;
    final baseRates = serviceType == 'book_now' && vehiclePreviews.isNotEmpty
        ? [for (final ev in vehiclePreviews) _num(ev['base_rate'])]
        : [
            _num(pricing['base_rate']),
            for (final ev in scheduledExtraPreviews) _num(ev['base_rate']),
          ];
    final distanceFees =
        serviceType == 'book_now' && vehiclePreviews.isNotEmpty
            ? [for (final ev in vehiclePreviews) _num(ev['distance_fee'])]
            : [
                _num(pricing['distance_fee']),
                for (final ev in scheduledExtraPreviews)
                  _num(ev['distance_fee']),
              ];
    final vatAmounts = serviceType == 'book_now' && vehiclePreviews.isNotEmpty
        ? [for (final ev in vehiclePreviews) _num(ev['vat_amount'])]
        : [
            _num(pricing['vat_amount']),
            for (final ev in scheduledExtraPreviews) _num(ev['vat_amount']),
          ];
    final finalTotals = serviceType == 'book_now' && vehiclePreviews.isNotEmpty
        ? [for (final ev in vehiclePreviews) _num(ev['final_total'])]
        : [
            _num(pricing['final_total']),
            for (final ev in scheduledExtraPreviews) _num(ev['final_total']),
          ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (int i = 0; i < names.length; i++) ...[
          if (i > 0) ...[
            const SizedBox(height: 18),
            Container(height: 1, color: context.divider),
            const SizedBox(height: 14),
          ],
          Text(
            multiVehicle
                ? 'Vehicle ${i + 1} — ${names[i]}'
                : 'VEHICLE ${i + 1}',
            style: multiVehicle
                ? GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    letterSpacing: -0.1,
                  )
                : sectionEyebrowStyle(context),
          ),
          if (!multiVehicle) ...[
            const SizedBox(height: 4),
            Text(
              names[i],
              style: GoogleFonts.inter(
                color: context.textPrimary,
                fontSize: 14,
                fontWeight: FontWeight.w700,
                letterSpacing: -0.1,
              ),
            ),
          ],
          const SizedBox(height: 8),
          _BRow(
            label: multiVehicle ? 'Base Rate' : 'Estimated Base Rate',
            value: '₱${priceFmt.format(baseRates[i])}',
          ),
          _BRow(
            label: multiVehicle ? 'Distance Fee' : 'Estimated Distance Fee',
            value: '₱${priceFmt.format(distanceFees[i])}',
            caption: multiVehicle ? 'First 4 km included.' : null,
          ),
          _BRow(
            label: multiVehicle ? 'VAT (12%)' : 'Estimated VAT',
            value: '₱${priceFmt.format(vatAmounts[i])}',
          ),
          _BRow(
            label: multiVehicle ? 'Vehicle Total' : 'Estimated Total',
            value: '₱${priceFmt.format(finalTotals[i])}',
          ),
        ],
        if (multiVehicle) ...[
          const SizedBox(height: 14),
          Container(height: 1, color: context.divider),
          const SizedBox(height: 12),
          _BRow(
            label: 'Estimated Total',
            value:
                '₱${priceFmt.format(finalTotals.fold<double>(0, (sum, value) => sum + value))}',
          ),
        ],
        const SizedBox(height: 14),
        Container(height: 1, color: context.divider),
        const SizedBox(height: 12),
        if (serviceType == 'schedule') ...[
          Text(
            'Scheduled pricing may change after quotation review.',
            style: GoogleFonts.inter(
              color: secondaryTextColor(context),
              fontSize: 11.5,
              letterSpacing: 0.1,
              height: 1.4,
            ),
          ),
        ],
      ],
    );
  }
}

class _BRow extends StatelessWidget {
  const _BRow({required this.label, required this.value, this.caption});
  final String label;
  final String value;
  final String? caption;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  label,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 14,
                    letterSpacing: 0.1,
                  ),
                ),
              ),
              Text(
                value,
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  letterSpacing: 0.1,
                ),
              ),
            ],
          ),
          if (caption != null) ...[
            const SizedBox(height: 2),
            Text(
              caption!,
              style: GoogleFonts.inter(
                color: secondaryTextColor(context),
                fontSize: 11.5,
                letterSpacing: 0.1,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _ReviewRow extends StatelessWidget {
  const _ReviewRow({required this.label, required this.value, this.caption});
  final String label;
  final String value;
  final String? caption;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: GoogleFonts.inter(
            color: secondaryTextColor(context),
            fontSize: 12,
            letterSpacing: 0.1,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          value,
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 14,
            fontWeight: FontWeight.w600,
            letterSpacing: 0.1,
            height: 1.4,
          ),
        ),
        if (caption != null) ...[
          const SizedBox(height: 3),
          Text(
            caption!,
            style: GoogleFonts.inter(
              color: secondaryTextColor(context),
              fontSize: 11.5,
              letterSpacing: 0.1,
              height: 1.4,
            ),
          ),
        ],
      ],
    );
  }
}

typedef _ReviewVehicle = ({
  String label,
  String vehicleName,
  String serviceType,
  DateTime? scheduledDate,
  TimeOfDay? scheduledTime,
  List<XFile> images,
});

class _VehicleReviewEntry extends StatelessWidget {
  const _VehicleReviewEntry({required this.vehicle});

  final _ReviewVehicle vehicle;

  String _formatSchedule() {
    final scheduledDate = vehicle.scheduledDate;
    final scheduledTime = vehicle.scheduledTime;
    if (scheduledDate == null) return 'Scheduled';
    final date = DateFormat('MMM d, yyyy').format(scheduledDate);
    if (scheduledTime == null) return date;
    final h = scheduledTime.hourOfPeriod == 0 ? 12 : scheduledTime.hourOfPeriod;
    final m = scheduledTime.minute.toString().padLeft(2, '0');
    final period = scheduledTime.period == DayPeriod.am ? 'AM' : 'PM';
    return '$date · $h:$m $period';
  }

  Widget _identity(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          vehicle.label,
          style: GoogleFonts.inter(
            color: secondaryTextColor(context),
            fontSize: 12,
            letterSpacing: 0.1,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          vehicle.vehicleName,
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 15,
            fontWeight: FontWeight.w700,
            letterSpacing: -0.1,
          ),
        ),
        const SizedBox(height: 2),
        Text(
          vehicle.serviceType == 'schedule' ? _formatSchedule() : 'Book Now',
          style: GoogleFonts.inter(
            color: secondaryTextColor(context),
            fontSize: 12.5,
            fontWeight: FontWeight.w500,
            letterSpacing: 0.1,
          ),
        ),
      ],
    );
  }

  Widget _photos() {
    if (vehicle.images.isEmpty) return const SizedBox.shrink();
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: vehicle.images.map((f) => _ReviewPhotoThumb(file: f)).toList(),
    );
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final bool wide =
            constraints.maxWidth >= 300 && vehicle.images.isNotEmpty;
        if (!wide) {
          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _identity(context),
              if (vehicle.images.isNotEmpty) ...[
                const SizedBox(height: 10),
                _photos(),
              ],
            ],
          );
        }
        return Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(child: _identity(context)),
            const SizedBox(width: 12),
            _photos(),
          ],
        );
      },
    );
  }
}

class _ReviewPhotoThumb extends StatelessWidget {
  const _ReviewPhotoThumb({required this.file});
  final XFile file;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(6),
      child: kIsWeb
          ? Image.network(file.path, width: 52, height: 52, fit: BoxFit.cover)
          : Image.file(
              File(file.path),
              width: 52,
              height: 52,
              fit: BoxFit.cover,
            ),
    );
  }
}
