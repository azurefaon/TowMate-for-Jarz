import 'dart:async';
import 'dart:io';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_map_cancellable_tile_provider/flutter_map_cancellable_tile_provider.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';
import 'package:latlong2/latlong.dart';
import '../../core/theme.dart';
import '../../models/truck_type_model.dart';
import '../../models/vehicle_type_model.dart';
import '../../services/api_service.dart';
import '../../widgets/tm_drawer.dart';
import 'booking_review_screen.dart';

class _ExtraVehicleData {
  TruckTypeModel? truck;
  VehicleTypeModel? vehicle;
}

class BookNowScreen extends StatefulWidget {
  const BookNowScreen({super.key});

  @override
  State<BookNowScreen> createState() => _BookNowScreenState();
}

class _BookNowScreenState extends State<BookNowScreen> {
  // Booking mode
  String _serviceType = 'book_now';
  DateTime? _scheduledDate;
  TimeOfDay? _scheduledTime;

  // Vehicle selection (primary)
  List<TruckTypeModel> _truckTypes = [];
  TruckTypeModel? _selectedTruckType;
  VehicleTypeModel? _selectedVehicleType;
  bool _loadingTypes = true;

  // Availability
  bool _bookNowEnabled = true;
  String? _availabilityMessage;
  Map<String, int> _readyByClass = {};

  // Location (set by _LocationSection callbacks)
  LatLng? _pickupLatLng;
  LatLng? _dropoffLatLng;
  String _pickupAddress = '';
  String _dropoffAddress = '';

  // Route
  List<LatLng> _routePoints = [];
  double? _distanceKm;
  double? _durationMin;
  bool _loadingRoute = false;

  // Vehicle images
  final List<XFile> _vehicleImages = [];
  bool _imageError = false;
  final _picker = ImagePicker();

  // Extra vehicles (up to 3 additional)
  final List<_ExtraVehicleData> _extraVehicles = [];

  // Notes
  final _notesCtrl = TextEditingController();

  // Route error display
  String? _submitError;

  // Pre-fill state from "Book same trip" args
  bool _prefillApplied = false;
  String? _prefillPickupAddress;
  String? _prefillDropoffAddress;

  @override
  void initState() {
    super.initState();
    _loadData();
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
    _notesCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadData() async {
    if (mounted) setState(() => _loadingTypes = true);

    // Start both fetches concurrently but don't block the picker on availability.
    final typeFuture = ApiService.fetchTruckTypes();
    final availFuture = ApiService.fetchAvailability();

    // Show the picker as soon as truck types arrive.
    final types = await typeFuture;
    if (!mounted) return;
    setState(() {
      _truckTypes = types;
      _loadingTypes = false;
    });

    // Availability result comes in afterwards (already in-flight).
    final avail = await availFuture;
    if (!mounted) return;
    setState(() {
      _bookNowEnabled = avail['book_now_enabled'] as bool? ?? true;
      _availabilityMessage = _bookNowEnabled
          ? null
          : (avail['message'] as String? ??
              'No tow trucks are currently available for immediate dispatch.');
      final byClass = avail['ready_by_class'];
      if (byClass is Map && byClass.isNotEmpty) {
        _readyByClass = byClass.map(
          (k, v) => MapEntry(
            k.toString().toLowerCase(),
            (v as num? ?? 0).toInt(),
          ),
        );
      }
    });
  }

  void _resetVehicle() {
    setState(() {
      _selectedTruckType = null;
      _selectedVehicleType = null;
    });
  }

  void _selectVehicle(TruckTypeModel truck, VehicleTypeModel vehicle) {
    setState(() {
      _selectedTruckType = truck;
      _selectedVehicleType = vehicle;
    });

    // Per-class availability gate: only fire if we have real data and this
    // specific class has no ready units, and we're in Book Now mode.
    if (_serviceType == 'book_now' && _readyByClass.isNotEmpty) {
      final available = _readyByClass[truck.truckClass.toLowerCase()] ?? 0;
      if (available == 0) _showNoUnitsModal();
    }
  }

  void _onPickupSelected(LatLng latlng, String address) {
    setState(() {
      _pickupLatLng = latlng;
      _pickupAddress = address;
      _routePoints = [];
      _distanceKm = null;
      _submitError = null;
    });
    if (_dropoffLatLng != null) _calculateRoute();
  }

  void _onDropoffSelected(LatLng latlng, String address) {
    setState(() {
      _dropoffLatLng = latlng;
      _dropoffAddress = address;
      _routePoints = [];
      _distanceKm = null;
      _submitError = null;
    });
    if (_pickupLatLng != null) _calculateRoute();
  }

  void _resetLocations() {
    setState(() {
      _pickupLatLng = null;
      _dropoffLatLng = null;
      _pickupAddress = '';
      _dropoffAddress = '';
      _routePoints = [];
      _distanceKm = null;
      _submitError = null;
    });
  }

  Future<void> _calculateRoute() async {
    if (_pickupLatLng == null || _dropoffLatLng == null) return;
    setState(() {
      _loadingRoute = true;
      _submitError = null;
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
          .map((c) => LatLng((c[0] as num).toDouble(), (c[1] as num).toDouble()))
          .toList();
      setState(() {
        _routePoints = pts;
        _distanceKm = (result['distance_km'] as num).toDouble();
        _durationMin = result['duration_min'] != null
            ? (result['duration_min'] as num).toDouble()
            : null;
        _loadingRoute = false;
      });
    } else {
      setState(() {
        _loadingRoute = false;
        _submitError = result['message'] as String? ?? 'Could not calculate route.';
      });
    }
  }

  double get _liveTotal {
    if (_selectedTruckType == null || _distanceKm == null) return 0;
    final distanceFee = (_distanceKm! / 4).floor() * 200.0;
    double bases = _selectedTruckType!.baseRate;
    for (final ev in _extraVehicles) {
      if (ev.truck != null) bases += ev.truck!.baseRate;
    }
    return bases + distanceFee;
  }

  List<String> get _missingFields {
    final items = <String>[];
    if (_selectedTruckType == null || _selectedVehicleType == null) {
      items.add('Select a vehicle type to tow');
    }
    if (_pickupLatLng == null || _dropoffLatLng == null) {
      items.add('Set both pickup and drop-off on the map');
    } else if (_distanceKm == null && !_loadingRoute) {
      items.add('Waiting for route calculation...');
    }
    if (_vehicleImages.isEmpty) {
      items.add('Upload at least 1 vehicle photo');
    }
    if (_serviceType == 'schedule' && (_scheduledDate == null || _scheduledTime == null)) {
      items.add('Choose a scheduled date and time');
    }
    if (_extraVehicles.any((v) => v.truck == null || v.vehicle == null)) {
      items.add('Complete vehicle selection for all added vehicles');
    }
    return items;
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
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(
          'Only JPG and PNG images are accepted.',
          style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
        ),
        backgroundColor: TmColors.error,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        margin: const EdgeInsets.all(16),
      ));
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

  void _addExtraVehicle() {
    if (_extraVehicles.length >= 3) return;
    setState(() => _extraVehicles.add(_ExtraVehicleData()));
  }

  void _removeExtraVehicle(int index) {
    setState(() => _extraVehicles.removeAt(index));
  }

  void _setExtraVehicle(int index, TruckTypeModel truck, VehicleTypeModel vehicle) {
    setState(() {
      _extraVehicles[index].truck = truck;
      _extraVehicles[index].vehicle = vehicle;
    });
  }

  bool get _canReview {
    if (_selectedTruckType == null || _selectedVehicleType == null) return false;
    if (_pickupLatLng == null || _dropoffLatLng == null) return false;
    if (_distanceKm == null || _loadingRoute) return false;
    if (_vehicleImages.isEmpty) return false;
    if (_serviceType == 'schedule' && (_scheduledDate == null || _scheduledTime == null)) return false;
    if (_extraVehicles.any((v) => v.truck == null || v.vehicle == null)) return false;
    return true;
  }

  Future<void> _showNoUnitsModal() async {
    await showDialog<void>(
      context: context,
      barrierDismissible: true,
      builder: (ctx) => AlertDialog(
        backgroundColor: TmColors.white,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text(
          'No Units Available',
          style: GoogleFonts.inter(
            color: TmColors.black,
            fontSize: 17,
            letterSpacing: -0.3,
          ),
        ),
        content: Text(
          _availabilityMessage ??
              'No tow trucks are currently available for immediate dispatch.',
          style: GoogleFonts.inter(
            color: TmColors.grey700,
            fontSize: 14,
            height: 1.5,
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text(
              'Cancel',
              style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 14),
            ),
          ),
          TextButton(
            onPressed: () {
              Navigator.pop(ctx);
              setState(() => _serviceType = 'schedule');
            },
            child: Text(
              'Schedule Instead',
              style: GoogleFonts.inter(color: TmColors.black, fontSize: 14),
            ),
          ),
        ],
      ),
    );
  }

  void _navigateToReview() {
    if (_vehicleImages.isEmpty) setState(() => _imageError = true);
    if (!_canReview) return;

    if (!_bookNowEnabled && _serviceType == 'book_now') {
      _showNoUnitsModal();
      return;
    }

    String? scheduledDateStr;
    String? scheduledTimeStr;
    if (_serviceType == 'schedule' && _scheduledDate != null && _scheduledTime != null) {
      scheduledDateStr =
          '${_scheduledDate!.year.toString().padLeft(4, '0')}-'
          '${_scheduledDate!.month.toString().padLeft(2, '0')}-'
          '${_scheduledDate!.day.toString().padLeft(2, '0')}';
      scheduledTimeStr =
          '${_scheduledTime!.hour.toString().padLeft(2, '0')}:'
          '${_scheduledTime!.minute.toString().padLeft(2, '0')}';
    }

    final extraData = _extraVehicles
        .where((v) => v.truck != null && v.vehicle != null)
        .map((v) => <String, dynamic>{
              'truck_type_id': v.truck!.id,
              'vehicle_type_id': v.vehicle!.id,
              'truck_name': v.truck!.name,
              'vehicle_name': v.vehicle!.name,
              'base_rate': v.truck!.baseRate,
            })
        .toList();

    Navigator.push<void>(
      context,
      MaterialPageRoute(
        builder: (_) => BookingReviewScreen(
          serviceType: _serviceType,
          scheduledDate: _scheduledDate,
          scheduledTime: _scheduledTime,
          pickupAddress: _pickupAddress,
          pickupLat: _pickupLatLng!.latitude,
          pickupLng: _pickupLatLng!.longitude,
          dropoffAddress: _dropoffAddress,
          dropoffLat: _dropoffLatLng!.latitude,
          dropoffLng: _dropoffLatLng!.longitude,
          distanceKm: _distanceKm!,
          durationMin: _durationMin,
          primaryTruck: _selectedTruckType!,
          primaryVehicle: _selectedVehicleType!,
          extraVehicles: extraData,
          vehicleImagePaths: _vehicleImages.map((x) => x.path).toList(),
          notes: _notesCtrl.text.trim(),
          scheduledDateStr: scheduledDateStr,
          scheduledTimeStr: scheduledTimeStr,
        ),
      ),
    );
  }

  void _showImageSourceSheet() {
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: TmColors.white,
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
                  color: TmColors.grey300,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
            const SizedBox(height: 20),
            Text(
              'Add Vehicle Photo',
              style: GoogleFonts.inter(
                color: TmColors.black,
                fontSize: 15,
                letterSpacing: -0.2,
              ),
            ),
            const SizedBox(height: 16),
            GestureDetector(
              onTap: () {
                Navigator.pop(ctx);
                _pickImage(ImageSource.camera);
              },
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 12),
                child: Text(
                  'Take Photo',
                  style: GoogleFonts.inter(
                    color: TmColors.grey700,
                    fontSize: 15,
                    letterSpacing: 0.1,
                  ),
                ),
              ),
            ),
            Container(height: 0.5, color: TmColors.grey300),
            GestureDetector(
              onTap: () {
                Navigator.pop(ctx);
                _pickImage(ImageSource.gallery);
              },
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 12),
                child: Text(
                  'Choose from Gallery',
                  style: GoogleFonts.inter(
                    color: TmColors.grey700,
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
    return Scaffold(
      backgroundColor: TmColors.white,
      drawer: const TmDrawer(currentRoute: '/book-now', isLoggedIn: true),
      body: Builder(
        builder: (ctx) => SafeArea(
          child: Column(
            children: [
              _TopBar(onMenuTap: () => Scaffold.of(ctx).openDrawer()),
              Expanded(
                child: SingleChildScrollView(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // ── Section 1: Booking mode ────────────────────────────
                      _BookingModeSection(
                        serviceType: _serviceType,
                        scheduledDate: _scheduledDate,
                        scheduledTime: _scheduledTime,
                        onServiceTypeChanged: (v) => setState(() => _serviceType = v),
                        onDateChanged: (d) => setState(() => _scheduledDate = d),
                        onTimeChanged: (t) => setState(() => _scheduledTime = t),
                      ),
                      // ── Section 2: Location ────────────────────────────────
                      _LocationSection(
                        pickupLatLng: _pickupLatLng,
                        dropoffLatLng: _dropoffLatLng,
                        routePoints: _routePoints,
                        loadingRoute: _loadingRoute,
                        onPickupSelected: _onPickupSelected,
                        onDropoffSelected: _onDropoffSelected,
                        onReset: _resetLocations,
                        initialPickupAddress: _prefillPickupAddress,
                        initialDropoffAddress: _prefillDropoffAddress,
                      ),
                      // ── Section 3: Vehicle Details ─────────────────────────
                      _VehicleSection(
                        truckTypes: _truckTypes,
                        loading: _loadingTypes,
                        selectedTruck: _selectedTruckType,
                        selectedVehicle: _selectedVehicleType,
                        readyByClass: _readyByClass,
                        onSelect: _selectVehicle,
                        onRetry: _loadData,
                        onReset: _resetVehicle,
                      ),
                      // ── Section 3b: Vehicle Images ─────────────────────────
                      const SizedBox(height: 20),
                      _VehicleImageSection(
                        images: _vehicleImages,
                        hasError: _imageError,
                        onAddTap: _showImageSourceSheet,
                        onRemove: _removeImage,
                      ),
                      // ── Section 3c: Extra Vehicles ─────────────────────────
                      if (_truckTypes.isNotEmpty) ...[
                        const SizedBox(height: 20),
                        _ExtraVehiclesSection(
                          extraVehicles: _extraVehicles,
                          truckTypes: _truckTypes,
                          canAdd: _extraVehicles.length < 3,
                          onAdd: _addExtraVehicle,
                          onRemove: _removeExtraVehicle,
                          onVehicleSet: _setExtraVehicle,
                        ),
                      ],
                      // ── Section 4: Notes ───────────────────────────────────
                      _NotesSection(controller: _notesCtrl),
                      // ── Review button ──────────────────────────────────────
                      Padding(
                        padding: const EdgeInsets.fromLTRB(24, 28, 24, 0),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            if (_submitError != null) ...[
                              Container(
                                width: double.infinity,
                                padding: const EdgeInsets.all(12),
                                decoration: BoxDecoration(
                                  color: TmColors.error.withValues(alpha: 0.08),
                                  borderRadius: BorderRadius.circular(6),
                                  border: const Border(
                                    left: BorderSide(color: TmColors.error, width: 3),
                                  ),
                                ),
                                child: Text(
                                  _submitError!,
                                  style: GoogleFonts.inter(
                                    color: TmColors.error,
                                    fontSize: 13,
                                    letterSpacing: 0.1,
                                  ),
                                ),
                              ),
                              const SizedBox(height: 16),
                            ],
                            if (_selectedTruckType != null && _distanceKm != null) ...[
                              const SizedBox(height: 16),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                                decoration: BoxDecoration(
                                  color: TmColors.grey100,
                                  borderRadius: BorderRadius.circular(6),
                                ),
                                child: Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    Text(
                                      'Estimated fare',
                                      style: GoogleFonts.inter(
                                        color: TmColors.grey500,
                                        fontSize: 13,
                                        letterSpacing: 0.1,
                                      ),
                                    ),
                                    Text(
                                      '₱${_liveTotal.toStringAsFixed(2)}',
                                      style: GoogleFonts.inter(
                                        color: TmColors.black,
                                        fontSize: 18,
                                        letterSpacing: -0.4,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                            const SizedBox(height: 16),
                            SizedBox(
                              width: double.infinity,
                              height: 52,
                              child: ElevatedButton(
                                onPressed: _canReview ? _navigateToReview : null,
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: TmColors.black,
                                  foregroundColor: TmColors.white,
                                  disabledBackgroundColor: TmColors.grey300,
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(6),
                                  ),
                                  elevation: 0,
                                ),
                                child: Text(
                                  'Review Booking',
                                  style: GoogleFonts.inter(
                                    color: TmColors.white,
                                    fontSize: 15,
                                    letterSpacing: 0.2,
                                  ),
                                ),
                              ),
                            ),
                            if (!_canReview) ...[
                              const SizedBox(height: 12),
                              ..._missingFields.map((msg) => Padding(
                                    padding: const EdgeInsets.only(bottom: 4),
                                    child: Text(
                                      '· $msg',
                                      style: GoogleFonts.inter(
                                        color: TmColors.grey500,
                                        fontSize: 12,
                                        letterSpacing: 0.1,
                                      ),
                                    ),
                                  )),
                            ],
                          ],
                        ),
                      ),
                      const SizedBox(height: 48),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ─── Top bar ───────────────────────────────────────────────────────────────

class _TopBar extends StatelessWidget {
  const _TopBar({required this.onMenuTap});
  final VoidCallback onMenuTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
      decoration: const BoxDecoration(
        border: Border(bottom: BorderSide(color: TmColors.grey300, width: 0.5)),
      ),
      child: Row(
        children: [
          IconButton(
            icon: const Icon(Icons.menu_rounded, color: TmColors.grey700),
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
          const SizedBox(width: 40),
        ],
      ),
    );
  }
}

// ─── Section 1: Booking mode ───────────────────────────────────────────────

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
      padding: const EdgeInsets.fromLTRB(24, 28, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Book Your Towing Service',
            style: GoogleFonts.inter(
              color: TmColors.black,
              fontSize: 20,
              letterSpacing: -0.4,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'Choose a booking mode, set your locations, and confirm.',
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 13,
              letterSpacing: 0.1,
              height: 1.5,
            ),
          ),
          const SizedBox(height: 20),
          Text(
            'BOOKING MODE',
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 11,
              letterSpacing: 0.8,
            ),
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              _ModeChip(
                label: 'Book Now',
                selected: serviceType == 'book_now',
                onTap: () => onServiceTypeChanged('book_now'),
              ),
              const SizedBox(width: 8),
              _ModeChip(
                label: 'Schedule Later',
                selected: serviceType == 'schedule',
                onTap: () => onServiceTypeChanged('schedule'),
              ),
            ],
          ),
          if (serviceType == 'schedule') ...[
            const SizedBox(height: 16),
            Row(
              children: [
                Expanded(
                  child: _DateTimeField(
                    label: 'Preferred Date',
                    value: scheduledDate != null ? _formatDate(scheduledDate!) : null,
                    placeholder: 'Select date',
                    onTap: () async {
                      final picked = await showDatePicker(
                        context: context,
                        initialDate: DateTime.now().add(const Duration(days: 1)),
                        firstDate: DateTime.now(),
                        lastDate: DateTime.now().add(const Duration(days: 90)),
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
                    value: scheduledTime != null ? _formatTime(scheduledTime!) : null,
                    placeholder: 'Select time',
                    onTap: () async {
                      final picked = await showTimePicker(
                        context: context,
                        initialTime: TimeOfDay.now(),
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
          ],
        ],
      ),
    );
  }
}

class _ModeChip extends StatelessWidget {
  const _ModeChip({required this.label, required this.selected, required this.onTap});
  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        decoration: BoxDecoration(
          color: selected ? TmColors.black : TmColors.grey100,
          borderRadius: BorderRadius.circular(6),
          border: selected ? null : Border.all(color: TmColors.grey300),
        ),
        child: Text(
          label,
          style: GoogleFonts.inter(
            color: selected ? TmColors.white : TmColors.grey700,
            fontSize: 13,
            letterSpacing: 0.1,
          ),
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
  });
  final String label;
  final String? value;
  final String placeholder;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: GoogleFonts.inter(
            color: TmColors.grey500,
            fontSize: 11,
            letterSpacing: 0.4,
          ),
        ),
        const SizedBox(height: 6),
        GestureDetector(
          onTap: onTap,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 13),
            decoration: BoxDecoration(
              border: Border.all(color: value != null ? TmColors.black : TmColors.grey300),
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text(
              value ?? placeholder,
              style: GoogleFonts.inter(
                color: value != null ? TmColors.black : TmColors.grey500,
                fontSize: 13,
                letterSpacing: 0.1,
              ),
            ),
          ),
        ),
      ],
    );
  }
}

// ─── Section 2: Location with search + map ─────────────────────────────────

class _LocationSection extends StatefulWidget {
  const _LocationSection({
    required this.pickupLatLng,
    required this.dropoffLatLng,
    required this.routePoints,
    required this.loadingRoute,
    required this.onPickupSelected,
    required this.onDropoffSelected,
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
  final _mapController = MapController();

  List<Map<String, dynamic>> _pickupSuggestions = [];
  List<Map<String, dynamic>> _dropoffSuggestions = [];
  bool _pickupSearching = false;
  bool _dropoffSearching = false;
  bool _locating = false;
  Timer? _debounce;

  static const _shortcuts = [
    'Gas Station',
    'Hospital',
    'Police Station',
    'NAIA Airport',
    'SM Mall',
    'LRT Station',
  ];

  @override
  void initState() {
    super.initState();
    _pickupCtrl = TextEditingController(
        text: widget.initialPickupAddress ?? '');
    _dropoffCtrl = TextEditingController(
        text: widget.initialDropoffAddress ?? '');
  }

  @override
  void dispose() {
    _pickupCtrl.dispose();
    _dropoffCtrl.dispose();
    _pickupFocus.dispose();
    _dropoffFocus.dispose();
    _debounce?.cancel();
    _mapController.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(_LocationSection old) {
    super.didUpdateWidget(old);

    // Route arrived → fit camera so the entire route is visible (Grab/Uber style)
    if (widget.routePoints.isNotEmpty && old.routePoints.isEmpty) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        _fitRoute(widget.routePoints);
      });
    } else if (widget.pickupLatLng != null &&
        widget.dropoffLatLng != null &&
        widget.routePoints.isEmpty &&
        (old.pickupLatLng == null || old.dropoffLatLng == null)) {
      // Both pins just set but route not ready yet → fit to 2-point bounds
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        _fitRoute([widget.pickupLatLng!, widget.dropoffLatLng!]);
      });
    }

    if (widget.pickupLatLng == null && old.pickupLatLng != null) {
      _pickupCtrl.clear();
      _dropoffCtrl.clear();
      setState(() {
        _pickupSuggestions = [];
        _dropoffSuggestions = [];
      });
    }
  }

  void _fitRoute(List<LatLng> points) {
    if (points.isEmpty) return;
    try {
      final bounds = LatLngBounds.fromPoints(points);
      _mapController.fitCamera(
        CameraFit.bounds(
          bounds: bounds,
          padding: const EdgeInsets.all(56),
        ),
      );
    } catch (_) {}
  }

  void _search(String query, bool isPickup) {
    _debounce?.cancel();
    if (query.length < 2) {
      setState(() => isPickup ? _pickupSuggestions = [] : _dropoffSuggestions = []);
      return;
    }
    setState(() => isPickup ? _pickupSearching = true : _dropoffSearching = true);
    _debounce = Timer(const Duration(milliseconds: 420), () async {
      final results = await ApiService.searchAddress(query);
      if (!mounted) return;
      setState(() {
        if (isPickup) {
          _pickupSuggestions = results;
          _pickupSearching = false;
        } else {
          _dropoffSuggestions = results;
          _dropoffSearching = false;
        }
      });
    });
  }

  void _selectPickup(Map<String, dynamic> feature) {
    final coords = feature['coordinates'] as List;
    final lat = (coords[1] as num).toDouble();
    final lng = (coords[0] as num).toDouble();
    final label = feature['label'] as String? ?? '';
    _pickupCtrl.text = label;
    _pickupFocus.unfocus();
    setState(() {
      _pickupSuggestions = [];
      _pickupSearching = false;
    });
    _mapController.move(LatLng(lat, lng), 14);
    widget.onPickupSelected(LatLng(lat, lng), label);
  }

  void _selectDropoff(Map<String, dynamic> feature) {
    final coords = feature['coordinates'] as List;
    final lat = (coords[1] as num).toDouble();
    final lng = (coords[0] as num).toDouble();
    final label = feature['label'] as String? ?? '';
    _dropoffCtrl.text = label;
    _dropoffFocus.unfocus();
    setState(() {
      _dropoffSuggestions = [];
      _dropoffSearching = false;
    });
    _mapController.move(LatLng(lat, lng), 14);
    widget.onDropoffSelected(LatLng(lat, lng), label);
  }

  void _reset() {
    _pickupCtrl.clear();
    _dropoffCtrl.clear();
    setState(() {
      _pickupSuggestions = [];
      _dropoffSuggestions = [];
    });
    widget.onReset();
  }

  void _applyShortcut(String text) {
    if (_dropoffFocus.hasFocus) {
      _dropoffCtrl.text = text;
      _search(text, false);
    } else {
      _pickupCtrl.text = text;
      _pickupFocus.requestFocus();
      _search(text, true);
    }
  }

  Future<void> _useCurrentLocation() async {
    if (_locating) return;
    setState(() => _locating = true);

    try {
      // Check if location services are enabled
      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(
              'Location services are disabled. Please enable GPS.',
              style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
            ),
            backgroundColor: TmColors.grey700,
            behavior: SnackBarBehavior.floating,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            margin: const EdgeInsets.all(16),
          ));
        }
        return;
      }

      // Request permission
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(
              'Location permission denied. Please allow it in settings.',
              style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
            ),
            backgroundColor: TmColors.grey700,
            behavior: SnackBarBehavior.floating,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            margin: const EdgeInsets.all(16),
          ));
        }
        return;
      }

      // Get position
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 10),
        ),
      );

      if (!mounted) return;

      final latlng = LatLng(position.latitude, position.longitude);
      final address = await ApiService.reverseGeocode(position.latitude, position.longitude);

      if (!mounted) return;

      _pickupCtrl.text = address;
      _mapController.move(latlng, 15);
      widget.onPickupSelected(latlng, address);
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(
            'Could not get your location. Try again.',
            style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
          ),
          backgroundColor: TmColors.grey700,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          margin: const EdgeInsets.all(16),
        ));
      }
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  Future<void> _onMapTap(LatLng point) async {
    if (widget.pickupLatLng == null) {
      final address = await ApiService.reverseGeocode(point.latitude, point.longitude);
      if (!mounted) return;
      _pickupCtrl.text = address;
      widget.onPickupSelected(point, address);
    } else if (widget.dropoffLatLng == null) {
      final address = await ApiService.reverseGeocode(point.latitude, point.longitude);
      if (!mounted) return;
      _dropoffCtrl.text = address;
      widget.onDropoffSelected(point, address);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bothSet = widget.pickupLatLng != null && widget.dropoffLatLng != null;
    final anyPin = widget.pickupLatLng != null || widget.dropoffLatLng != null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // ── Header ──────────────────────────────────────────────────────────
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 28, 24, 0),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'PICKUP & DROP-OFF',
                style: GoogleFonts.inter(
                  color: TmColors.grey500,
                  fontSize: 11,
                  letterSpacing: 0.8,
                ),
              ),
              if (widget.pickupLatLng != null)
                GestureDetector(
                  onTap: _reset,
                  child: Text(
                    'Reset',
                    style: GoogleFonts.inter(
                      color: TmColors.grey500,
                      fontSize: 13,
                      letterSpacing: 0.1,
                    ),
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(height: 12),

        // ── Pickup search field ──────────────────────────────────────────────
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _FieldLabel(text: 'Pickup Location'),
              const SizedBox(height: 6),
              _SearchField(
                controller: _pickupCtrl,
                focusNode: _pickupFocus,
                placeholder: 'Where should we pick you up?',
                confirmed: widget.pickupLatLng != null,
                searching: _pickupSearching,
                onChanged: (v) => _search(v, true),
              ),
              if (_pickupSuggestions.isNotEmpty)
                _SuggestionList(
                  suggestions: _pickupSuggestions,
                  onSelect: _selectPickup,
                ),
              if (widget.pickupLatLng == null) ...[
                const SizedBox(height: 8),
                GestureDetector(
                  onTap: _locating ? null : _useCurrentLocation,
                  child: _locating
                      ? Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const SizedBox(
                              width: 12,
                              height: 12,
                              child: CircularProgressIndicator(
                                strokeWidth: 1.5,
                                color: TmColors.grey500,
                              ),
                            ),
                            const SizedBox(width: 6),
                            Text(
                              'Getting location...',
                              style: GoogleFonts.inter(
                                color: TmColors.grey500,
                                fontSize: 12,
                                letterSpacing: 0.1,
                              ),
                            ),
                          ],
                        )
                      : Text(
                          'Use current location',
                          style: GoogleFonts.inter(
                            color: TmColors.grey700,
                            fontSize: 12,
                            letterSpacing: 0.1,
                            decoration: TextDecoration.underline,
                          ),
                        ),
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 12),

        // ── Dropoff search field ─────────────────────────────────────────────
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _FieldLabel(text: 'Drop-off Location'),
              const SizedBox(height: 6),
              _SearchField(
                controller: _dropoffCtrl,
                focusNode: _dropoffFocus,
                placeholder: 'Where are you headed?',
                confirmed: widget.dropoffLatLng != null,
                searching: _dropoffSearching,
                onChanged: (v) => _search(v, false),
              ),
              if (_dropoffSuggestions.isNotEmpty)
                _SuggestionList(
                  suggestions: _dropoffSuggestions,
                  onSelect: _selectDropoff,
                ),
            ],
          ),
        ),
        const SizedBox(height: 14),

        // ── Quick-search shortcuts ───────────────────────────────────────────
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Quick search',
                style: GoogleFonts.inter(
                  color: TmColors.grey500,
                  fontSize: 11,
                  letterSpacing: 0.4,
                ),
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 6,
                children: _shortcuts.map((s) {
                  return GestureDetector(
                    onTap: () => _applyShortcut(s),
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                      decoration: BoxDecoration(
                        color: TmColors.grey100,
                        borderRadius: BorderRadius.circular(5),
                        border: Border.all(color: TmColors.grey300),
                      ),
                      child: Text(
                        s,
                        style: GoogleFonts.inter(
                          color: TmColors.grey700,
                          fontSize: 12,
                          letterSpacing: 0.1,
                        ),
                      ),
                    ),
                  );
                }).toList(),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),

        // ── Map (280px, auto-fits route when both pins placed) ──────────────
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: Text(
            'Live Route Preview',
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 11,
              letterSpacing: 0.4,
            ),
          ),
        ),
        const SizedBox(height: 8),
        SizedBox(
          height: 280,
          child: Stack(
            children: [
              // FlutterMap is always in the tree so MapController is always ready
              Positioned.fill(
                child: Container(
                  decoration: const BoxDecoration(
                    border: Border(
                      top: BorderSide(color: TmColors.grey300, width: 0.5),
                      bottom: BorderSide(color: TmColors.grey300, width: 0.5),
                    ),
                  ),
                  child: FlutterMap(
                    mapController: _mapController,
                    options: MapOptions(
                      initialCenter: const LatLng(14.5995, 120.9842),
                      initialZoom: 13,
                      onTap: bothSet ? null : (_, pt) => _onMapTap(pt),
                      interactionOptions: const InteractionOptions(
                        flags: InteractiveFlag.all,
                      ),
                    ),
                    children: [
                      TileLayer(
                        urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                        userAgentPackageName: 'com.towmate.app',
                        tileProvider: CancellableNetworkTileProvider(),
                      ),
                      if (widget.routePoints.isNotEmpty)
                        PolylineLayer(polylines: [
                          Polyline(
                            points: widget.routePoints,
                            color: TmColors.yellow,
                            strokeWidth: 3.0,
                          ),
                        ]),
                      MarkerLayer(markers: [
                        if (widget.pickupLatLng != null)
                          Marker(
                            point: widget.pickupLatLng!,
                            width: 28,
                            height: 28,
                            child: _MapPin(label: 'P', dark: true),
                          ),
                        if (widget.dropoffLatLng != null)
                          Marker(
                            point: widget.dropoffLatLng!,
                            width: 28,
                            height: 28,
                            child: _MapPin(label: 'D', dark: false),
                          ),
                      ]),
                    ],
                  ),
                ),
              ),
              // Grey overlay hides map until first pin is placed
              if (!anyPin)
                Positioned.fill(
                  child: Container(
                    color: TmColors.grey100,
                    alignment: Alignment.center,
                    child: Text(
                      'Set pickup location to see map',
                      style: GoogleFonts.inter(
                        color: TmColors.grey500,
                        fontSize: 13,
                        letterSpacing: 0.1,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),

        if (widget.loadingRoute)
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 12, 24, 0),
            child: Text(
              'Calculating route...',
              style: GoogleFonts.inter(
                color: TmColors.grey500,
                fontSize: 13,
                letterSpacing: 0.1,
              ),
            ),
          ),
      ],
    );
  }
}

// ─── Search field ───────────────────────────────────────────────────────────

class _FieldLabel extends StatelessWidget {
  const _FieldLabel({required this.text});
  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: GoogleFonts.inter(
        color: TmColors.grey700,
        fontSize: 13,
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
    required this.confirmed,
    required this.searching,
    required this.onChanged,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final String placeholder;
  final bool confirmed;
  final bool searching;
  final void Function(String) onChanged;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        border: Border.all(
          color: confirmed ? TmColors.black : TmColors.grey300,
          width: confirmed ? 1.5 : 1.0,
        ),
        borderRadius: BorderRadius.circular(6),
        color: TmColors.white,
      ),
      child: TextField(
        controller: controller,
        focusNode: focusNode,
        onChanged: onChanged,
        style: GoogleFonts.inter(
          color: TmColors.black,
          fontSize: 14,
          letterSpacing: 0.1,
        ),
        decoration: InputDecoration(
          hintText: placeholder,
          hintStyle: GoogleFonts.inter(
            color: TmColors.grey500,
            fontSize: 14,
            letterSpacing: 0.1,
          ),
          contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
          border: InputBorder.none,
          suffixIcon: searching
              ? const Padding(
                  padding: EdgeInsets.all(14),
                  child: SizedBox(
                    width: 14,
                    height: 14,
                    child: CircularProgressIndicator(
                      strokeWidth: 1.5,
                      color: TmColors.grey500,
                    ),
                  ),
                )
              : null,
        ),
      ),
    );
  }
}

// ─── Suggestion list ────────────────────────────────────────────────────────

class _SuggestionList extends StatelessWidget {
  const _SuggestionList({required this.suggestions, required this.onSelect});
  final List<Map<String, dynamic>> suggestions;
  final void Function(Map<String, dynamic>) onSelect;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(top: 2),
      decoration: BoxDecoration(
        border: Border.all(color: TmColors.grey300),
        borderRadius: BorderRadius.circular(6),
        color: TmColors.white,
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
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
              decoration: BoxDecoration(
                border: i > 0
                    ? const Border(top: BorderSide(color: TmColors.grey300, width: 0.5))
                    : null,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    main,
                    style: GoogleFonts.inter(
                      color: TmColors.grey700,
                      fontSize: 13,
                      letterSpacing: 0.1,
                    ),
                  ),
                  if (detail != null) ...[
                    const SizedBox(height: 2),
                    Text(
                      detail,
                      style: GoogleFonts.inter(
                        color: TmColors.grey500,
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

class _MapPin extends StatelessWidget {
  const _MapPin({required this.label, required this.dark});
  final String label;
  final bool dark;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: dark ? TmColors.black : TmColors.yellow,
        borderRadius: BorderRadius.circular(4),
      ),
      alignment: Alignment.center,
      child: Text(
        label,
        style: GoogleFonts.inter(
          color: dark ? TmColors.white : TmColors.black,
          fontSize: 12,
          letterSpacing: 0.3,
        ),
      ),
    );
  }
}

// ─── Section 3a: Primary vehicle selection ─────────────────────────────────

class _VehicleSection extends StatefulWidget {
  const _VehicleSection({
    required this.truckTypes,
    required this.loading,
    required this.selectedTruck,
    required this.selectedVehicle,
    required this.readyByClass,
    required this.onSelect,
    this.onRetry,
    this.onReset,
  });

  final List<TruckTypeModel> truckTypes;
  final bool loading;
  final TruckTypeModel? selectedTruck;
  final VehicleTypeModel? selectedVehicle;
  final Map<String, int> readyByClass;
  final void Function(TruckTypeModel, VehicleTypeModel) onSelect;
  final VoidCallback? onRetry;
  final VoidCallback? onReset;

  @override
  State<_VehicleSection> createState() => _VehicleSectionState();
}

class _VehicleSectionState extends State<_VehicleSection> {
  TruckTypeModel? _focusedTruck;

  @override
  void initState() {
    super.initState();
    if (widget.truckTypes.isNotEmpty) {
      _focusedTruck = widget.selectedTruck ?? widget.truckTypes.first;
    }
  }

  @override
  void didUpdateWidget(_VehicleSection old) {
    super.didUpdateWidget(old);
    if (old.truckTypes.isEmpty && widget.truckTypes.isNotEmpty && _focusedTruck == null) {
      setState(() {
        _focusedTruck = widget.selectedTruck ?? widget.truckTypes.first;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final trucks = widget.truckTypes;

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 28, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'VEHICLE TO TOW',
                style: GoogleFonts.inter(
                  color: TmColors.grey500,
                  fontSize: 11,
                  letterSpacing: 0.8,
                ),
              ),
              if (widget.selectedTruck != null && widget.onReset != null)
                GestureDetector(
                  onTap: widget.onReset,
                  child: Text(
                    'Clear',
                    style: GoogleFonts.inter(
                      color: TmColors.grey500,
                      fontSize: 13,
                      letterSpacing: 0.1,
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            'What type of vehicle needs to be towed?',
            style: GoogleFonts.inter(
              color: TmColors.black,
              fontSize: 16,
              letterSpacing: -0.2,
            ),
          ),
          const SizedBox(height: 20),
          if (widget.loading)
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: TmColors.grey100,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(
                'Loading vehicle types...',
                style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 14),
              ),
            )
          else if (trucks.isEmpty)
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                border: Border.all(color: TmColors.grey300),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Could not load vehicle types.',
                    style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 14),
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
            // ── Step 1: Select Tow Class ──────────────────────────────────
            Text(
              '1. Select Tow Class',
              style: GoogleFonts.inter(
                color: TmColors.grey700,
                fontSize: 13,
                letterSpacing: 0.1,
              ),
            ),
            const SizedBox(height: 10),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: trucks.map((truck) {
                  final isAvailable = widget.readyByClass.isEmpty ||
                      (widget.readyByClass[truck.truckClass.toLowerCase()] ?? 0) > 0;
                  return _ClassPickerCard(
                    truck: truck,
                    isFocused: _focusedTruck?.id == truck.id,
                    isSelected: widget.selectedTruck?.id == truck.id,
                    isAvailable: isAvailable,
                    onTap: () => setState(() => _focusedTruck = truck),
                  );
                }).toList(),
              ),
            ),
            // ── Step 2: Select Vehicle Type ───────────────────────────────
            if (_focusedTruck != null) ...[
              const SizedBox(height: 24),
              Text(
                '2. Select Vehicle Type',
                style: GoogleFonts.inter(
                  color: TmColors.grey700,
                  fontSize: 13,
                  letterSpacing: 0.1,
                ),
              ),
              const SizedBox(height: 10),
              Text(
                '${_focusedTruck!.name.toUpperCase()} VEHICLES',
                style: GoogleFonts.inter(
                  color: TmColors.grey500,
                  fontSize: 11,
                  letterSpacing: 0.6,
                ),
              ),
              const SizedBox(height: 10),
              if (_focusedTruck!.vehicleTypes.isNotEmpty) ...[
                Builder(builder: (context) {
                  final isAvailable = widget.readyByClass.isEmpty ||
                      (widget.readyByClass[_focusedTruck!.truckClass.toLowerCase()] ?? 0) > 0;
                  return Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: _focusedTruck!.vehicleTypes.map((v) {
                      return _VehicleChip(
                        label: v.name,
                        selected: widget.selectedTruck?.id == _focusedTruck!.id &&
                            widget.selectedVehicle?.id == v.id,
                        muted: !isAvailable,
                        onTap: () => widget.onSelect(_focusedTruck!, v),
                      );
                    }).toList(),
                  );
                }),
              ] else
                Text(
                  'No vehicle types configured for this class.',
                  style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 13),
                ),
            ],
          ],
        ],
      ),
    );
  }
}

class _ClassPickerCard extends StatelessWidget {
  const _ClassPickerCard({
    required this.truck,
    required this.isFocused,
    required this.isSelected,
    required this.isAvailable,
    required this.onTap,
  });

  final TruckTypeModel truck;
  final bool isFocused;
  final bool isSelected;
  final bool isAvailable;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final Color bg = isSelected
        ? TmColors.black
        : isAvailable ? TmColors.white : TmColors.grey100;
    final Color borderColor = (isSelected || isFocused) ? TmColors.black : TmColors.grey300;
    final double borderWidth = (isSelected || isFocused) ? 1.5 : 1.0;
    final Color nameColor = isSelected
        ? TmColors.white
        : isAvailable ? TmColors.black : TmColors.grey300;
    final Color metaColor = isSelected ? TmColors.grey300 : TmColors.grey500;
    final Color availColor = isSelected
        ? TmColors.grey300
        : isAvailable ? TmColors.grey500 : TmColors.grey300;
    final String availText = isAvailable ? 'available' : 'unavailable';

    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        width: 148,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        margin: const EdgeInsets.only(right: 10),
        decoration: BoxDecoration(
          color: bg,
          border: Border.all(color: borderColor, width: borderWidth),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              truck.name,
              style: GoogleFonts.inter(
                color: nameColor,
                fontSize: 14,
                letterSpacing: -0.1,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              '₱${truck.baseRate.toStringAsFixed(0)} base rate',
              style: GoogleFonts.inter(
                color: metaColor,
                fontSize: 12,
                letterSpacing: 0.1,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              availText,
              style: GoogleFonts.inter(
                color: availColor,
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

class _VehicleChip extends StatelessWidget {
  const _VehicleChip({
    required this.label,
    required this.selected,
    required this.onTap,
    this.muted = false,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;
  final bool muted;

  @override
  Widget build(BuildContext context) {
    final Color bg = selected
        ? TmColors.black
        : muted
            ? TmColors.grey100
            : TmColors.white;
    final Color borderColor = selected
        ? TmColors.black
        : muted
            ? TmColors.grey300
            : TmColors.grey300;
    final Color textColor = selected
        ? TmColors.white
        : muted
            ? TmColors.grey500
            : TmColors.grey700;

    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        decoration: BoxDecoration(
          color: bg,
          borderRadius: BorderRadius.circular(6),
          border: Border.all(
            color: borderColor,
            width: selected ? 1.5 : 1.0,
          ),
        ),
        child: Text(
          label,
          style: GoogleFonts.inter(
            color: textColor,
            fontSize: 13,
            letterSpacing: 0.1,
          ),
        ),
      ),
    );
  }
}

// ─── Section 3b: Vehicle image upload ──────────────────────────────────────

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
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'VEHICLE PHOTOS  (1–5 required)',
            style: GoogleFonts.inter(
              color: hasError && images.isEmpty ? TmColors.error : TmColors.grey500,
              fontSize: 11,
              letterSpacing: 0.8,
            ),
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              ...List.generate(images.length, (i) => _ImageThumb(
                file: images[i],
                onRemove: () => onRemove(i),
              )),
              if (images.length < 5)
                GestureDetector(
                  onTap: onAddTap,
                  child: Container(
                    width: 88,
                    height: 88,
                    decoration: BoxDecoration(
                      color: TmColors.grey100,
                      border: Border.all(
                        color: hasError && images.isEmpty
                            ? TmColors.error
                            : TmColors.grey300,
                      ),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Center(
                      child: Text(
                        '+ Photo',
                        style: GoogleFonts.inter(
                          color: hasError && images.isEmpty
                              ? TmColors.error
                              : TmColors.grey500,
                          fontSize: 12,
                          letterSpacing: 0.2,
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          ),
          if (hasError && images.isEmpty) ...[
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
    return SizedBox(
      width: 88,
      height: 88,
      child: Stack(
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(6),
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
                width: 20,
                height: 20,
                decoration: BoxDecoration(
                  color: TmColors.black.withValues(alpha: 0.7),
                  borderRadius: BorderRadius.circular(10),
                ),
                alignment: Alignment.center,
                child: Text(
                  '×',
                  style: GoogleFonts.inter(
                    color: TmColors.white,
                    fontSize: 14,
                    height: 1.0,
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

// ─── Section 3c: Extra vehicles ─────────────────────────────────────────────

class _ExtraVehiclesSection extends StatelessWidget {
  const _ExtraVehiclesSection({
    required this.extraVehicles,
    required this.truckTypes,
    required this.canAdd,
    required this.onAdd,
    required this.onRemove,
    required this.onVehicleSet,
  });

  final List<_ExtraVehicleData> extraVehicles;
  final List<TruckTypeModel> truckTypes;
  final bool canAdd;
  final VoidCallback onAdd;
  final void Function(int) onRemove;
  final void Function(int, TruckTypeModel, VehicleTypeModel) onVehicleSet;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'ADDITIONAL VEHICLES',
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 11,
              letterSpacing: 0.8,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'Towing multiple vehicles on the same trip? Add each vehicle below with its tow class and vehicle type.',
            style: GoogleFonts.inter(
              color: TmColors.grey700,
              fontSize: 13,
              letterSpacing: 0.1,
              height: 1.5,
            ),
          ),
          ...List.generate(extraVehicles.length, (i) => _ExtraVehicleSlot(
            index: i,
            data: extraVehicles[i],
            truckTypes: truckTypes,
            onRemove: () => onRemove(i),
            onVehicleSet: (truck, vehicle) => onVehicleSet(i, truck, vehicle),
          )),
          if (canAdd) ...[
            const SizedBox(height: 14),
            GestureDetector(
              onTap: onAdd,
              child: Container(
                padding: const EdgeInsets.symmetric(vertical: 12),
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  border: Border.all(color: TmColors.grey300),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  '+ Add another vehicle',
                  style: GoogleFonts.inter(
                    color: TmColors.grey700,
                    fontSize: 13,
                    letterSpacing: 0.1,
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _ExtraVehicleSlot extends StatelessWidget {
  const _ExtraVehicleSlot({
    required this.index,
    required this.data,
    required this.truckTypes,
    required this.onRemove,
    required this.onVehicleSet,
  });

  final int index;
  final _ExtraVehicleData data;
  final List<TruckTypeModel> truckTypes;
  final VoidCallback onRemove;
  final void Function(TruckTypeModel, VehicleTypeModel) onVehicleSet;

  @override
  Widget build(BuildContext context) {
    final hasSelection = data.truck != null && data.vehicle != null;

    return Container(
      margin: const EdgeInsets.only(top: 14),
      decoration: BoxDecoration(
        border: Border.all(
          color: hasSelection ? TmColors.black : TmColors.grey300,
          width: hasSelection ? 1.5 : 1.0,
        ),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header row
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  'Vehicle ${index + 2}',
                  style: GoogleFonts.inter(
                    color: TmColors.grey700,
                    fontSize: 13,
                    letterSpacing: 0.2,
                  ),
                ),
                GestureDetector(
                  onTap: onRemove,
                  child: Text(
                    'Remove',
                    style: GoogleFonts.inter(
                      color: TmColors.grey500,
                      fontSize: 12,
                      letterSpacing: 0.1,
                    ),
                  ),
                ),
              ],
            ),
          ),
          if (hasSelection) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
              child: Text(
                '${data.truck!.name}  ·  ${data.vehicle!.name}',
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 12,
                  letterSpacing: 0.1,
                ),
              ),
            ),
          ],
          const SizedBox(height: 12),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14),
            child: Container(height: 0.5, color: TmColors.grey300),
          ),
          const SizedBox(height: 12),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14),
            child: Text(
              'SELECT TOW CLASS & VEHICLE TYPE',
              style: GoogleFonts.inter(
                color: TmColors.grey500,
                fontSize: 10,
                letterSpacing: 0.6,
              ),
            ),
          ),
          const SizedBox(height: 10),
          // Each truck class as a sub-section
          ...truckTypes.where((t) => t.vehicleTypes.isNotEmpty).map((truck) {
            return Padding(
              padding: const EdgeInsets.fromLTRB(14, 0, 14, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    truck.name,
                    style: GoogleFonts.inter(
                      color: TmColors.grey500,
                      fontSize: 11,
                      letterSpacing: 0.4,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: truck.vehicleTypes.map((v) {
                      final selected =
                          data.vehicle?.id == v.id && data.truck?.id == truck.id;
                      return _VehicleChip(
                        label: v.name,
                        selected: selected,
                        onTap: () => onVehicleSet(truck, v),
                      );
                    }).toList(),
                  ),
                ],
              ),
            );
          }),
        ],
      ),
    );
  }
}

// ─── Section 4: Notes ──────────────────────────────────────────────────────

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
          Text(
            'SPECIAL NOTES',
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 11,
              letterSpacing: 0.8,
            ),
          ),
          const SizedBox(height: 10),
          Container(
            decoration: BoxDecoration(
              border: Border.all(color: TmColors.grey300),
              borderRadius: BorderRadius.circular(6),
            ),
            child: TextField(
              controller: controller,
              minLines: 3,
              maxLines: 6,
              style: GoogleFonts.inter(
                color: TmColors.black,
                fontSize: 14,
                letterSpacing: 0.1,
              ),
              decoration: InputDecoration(
                hintText: 'Any special instructions or notes...',
                hintStyle: GoogleFonts.inter(
                  color: TmColors.grey500,
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
