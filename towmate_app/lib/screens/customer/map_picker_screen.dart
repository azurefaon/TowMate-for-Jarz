import 'dart:async';

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:geolocator/geolocator.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../core/theme.dart';
import '../../services/api_service.dart';
import '../../widgets/quotation_price_cards.dart';

class MapPickerResult {
  const MapPickerResult({
    required this.latLng,
    required this.address,
  });

  final LatLng latLng;
  final String address;
}

class MapPickerScreen extends StatefulWidget {
  const MapPickerScreen({
    super.key,
    required this.title,
    this.initialLatLng,
  });

  final String title;
  final LatLng? initialLatLng;

  @override
  State<MapPickerScreen> createState() => _MapPickerScreenState();
}

class _MapPickerScreenState extends State<MapPickerScreen> {
  static const _defaultCenter = LatLng(14.5995, 120.9842);

  GoogleMapController? _mapController;
  LatLng _center = _defaultCenter;
  String? _resolvedAddress;
  bool _resolvingAddress = false;
  bool _addressUnresolved = false;
  bool _mapReady = false;
  bool _mapFailed = false;
  bool _locating = false;
  Timer? _idleDebounce;
  int _geocodeRequestId = 0;
  final _descriptionCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _center = widget.initialLatLng ?? _defaultCenter;
    Timer(const Duration(seconds: 8), () {
      if (!mounted || _mapReady) return;
      setState(() => _mapFailed = true);
    });
    if (widget.initialLatLng != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _resolveAddress());
    } else {
      _tryUseCurrentLocation(silent: true);
    }
  }

  @override
  void dispose() {
    _idleDebounce?.cancel();
    _descriptionCtrl.dispose();
    _mapController?.dispose();
    super.dispose();
  }

  void _onCameraMove(CameraPosition position) {
    _center = position.target;
  }

  void _onCameraIdle() {
    _idleDebounce?.cancel();
    _idleDebounce = Timer(const Duration(milliseconds: 450), _resolveAddress);
  }

  Future<void> _resolveAddress() async {
    if (!mounted) return;
    final requestId = ++_geocodeRequestId;
    setState(() {
      _resolvingAddress = true;
      _addressUnresolved = false;
    });

    final address = await ApiService.reverseGeocode(
      _center.latitude,
      _center.longitude,
    );

    if (!mounted || requestId != _geocodeRequestId) return;

    final resolved = address.trim().isEmpty || address == 'Unknown location'
        ? null
        : address;

    setState(() {
      _resolvingAddress = false;
      _resolvedAddress = resolved;
      _addressUnresolved = resolved == null;
    });
  }

  Future<void> _tryUseCurrentLocation({bool silent = false}) async {
    if (_locating) return;
    setState(() => _locating = true);
    try {
      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        if (!silent) _showSnack('Location services are disabled. Please enable GPS.');
        return;
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        if (!silent) {
          _showSnack('Location permission denied. Please allow it in settings.');
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
      _center = latlng;
      if (_mapController != null) {
        await _mapController!.animateCamera(
          CameraUpdate.newLatLngZoom(latlng, 16),
        );
      } else {
        setState(() {});
      }
      await _resolveAddress();
    } catch (_) {
      if (!silent) _showSnack('Could not get your location. Try again.');
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  void _showSnack(String message) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          message,
          style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
        ),
        backgroundColor: TmColors.grey700,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        margin: const EdgeInsets.all(16),
      ),
    );
  }

  void _confirm() {
    final description = _descriptionCtrl.text.trim();
    final address = _resolvedAddress ??
        (description.isNotEmpty ? description : null);
    if (address == null) return;

    Navigator.pop(
      context,
      MapPickerResult(latLng: _center, address: address),
    );
  }

  bool get _canConfirm {
    if (_resolvedAddress != null) return true;
    return _addressUnresolved && _descriptionCtrl.text.trim().isNotEmpty;
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
                    icon: Icon(Icons.close_rounded, color: context.textPrimary, size: 22),
                    onPressed: () => Navigator.pop(context),
                  ),
                  Expanded(
                    child: Text(
                      widget.title,
                      textAlign: TextAlign.center,
                      style: GoogleFonts.inter(
                        color: context.textPrimary,
                        fontSize: 16,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -0.4,
                      ),
                    ),
                  ),
                  const SizedBox(width: 40),
                ],
              ),
            ),
            Expanded(
              child: Stack(
                alignment: Alignment.center,
                children: [
                  if (_mapFailed)
                    Container(
                      color: context.surface,
                      alignment: Alignment.center,
                      padding: const EdgeInsets.all(24),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.map_outlined, color: context.textTertiary, size: 32),
                          const SizedBox(height: 10),
                          Text(
                            'The map could not load. Go back and use search instead.',
                            textAlign: TextAlign.center,
                            style: GoogleFonts.inter(color: context.textSecondary, fontSize: 13),
                          ),
                        ],
                      ),
                    )
                  else ...[
                    GoogleMap(
                      initialCameraPosition: CameraPosition(target: _center, zoom: 15),
                      onMapCreated: (controller) {
                        _mapController = controller;
                        if (mounted) setState(() => _mapReady = true);
                      },
                      onCameraMove: _onCameraMove,
                      onCameraIdle: _onCameraIdle,
                      zoomControlsEnabled: false,
                      myLocationButtonEnabled: false,
                      rotateGesturesEnabled: false,
                      tiltGesturesEnabled: false,
                    ),
                    IgnorePointer(
                      child: Padding(
                        padding: const EdgeInsets.only(bottom: 34),
                        child: Icon(
                          Icons.location_on_rounded,
                          color: TmColors.black,
                          size: 40,
                        ),
                      ),
                    ),
                    Positioned(
                      right: 16,
                      bottom: 16,
                      child: FloatingActionButton.small(
                        heroTag: 'mapPickerLocate',
                        backgroundColor: context.card,
                        foregroundColor: context.textPrimary,
                        elevation: 1,
                        onPressed: _locating ? null : () => _tryUseCurrentLocation(),
                        child: _locating
                            ? SizedBox(
                                width: 16,
                                height: 16,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                  color: context.textPrimary,
                                ),
                              )
                            : const Icon(Icons.my_location_rounded, size: 18),
                      ),
                    ),
                  ],
                ],
              ),
            ),
            Container(
              padding: const EdgeInsets.fromLTRB(24, 16, 24, 20),
              decoration: BoxDecoration(
                border: Border(top: BorderSide(color: context.divider, width: 0.5)),
                color: context.bg,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('SELECTED LOCATION', style: sectionEyebrowStyle(context)),
                  const SizedBox(height: 8),
                  if (_resolvingAddress)
                    Row(
                      children: [
                        SizedBox(
                          width: 14,
                          height: 14,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: context.textTertiary,
                          ),
                        ),
                        const SizedBox(width: 8),
                        Text(
                          'Finding address...',
                          style: GoogleFonts.inter(color: context.textSecondary, fontSize: 13),
                        ),
                      ],
                    )
                  else if (_resolvedAddress != null)
                    Text(
                      _resolvedAddress!,
                      style: GoogleFonts.inter(
                        color: context.textPrimary,
                        fontSize: 14,
                        fontWeight: FontWeight.w600,
                        height: 1.4,
                      ),
                    )
                  else if (_addressUnresolved) ...[
                    Text(
                      "We couldn't find an exact address for this pin.",
                      style: GoogleFonts.inter(color: context.textSecondary, fontSize: 13),
                    ),
                    const SizedBox(height: 10),
                    TextField(
                      controller: _descriptionCtrl,
                      onChanged: (_) => setState(() {}),
                      style: GoogleFonts.inter(color: context.textPrimary, fontSize: 13),
                      decoration: InputDecoration(
                        hintText: 'Describe this location or a nearby landmark',
                        hintStyle: GoogleFonts.inter(color: context.textTertiary, fontSize: 13),
                        filled: true,
                        fillColor: context.surface,
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(10),
                          borderSide: BorderSide(color: context.divider),
                        ),
                        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                      ),
                    ),
                  ]
                  else
                    Text(
                      'Move the map to position the pin.',
                      style: GoogleFonts.inter(color: context.textSecondary, fontSize: 13),
                    ),
                  const SizedBox(height: 14),
                  SizedBox(
                    width: double.infinity,
                    height: 48,
                    child: ElevatedButton(
                      onPressed: _canConfirm ? _confirm : null,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: TmColors.yellow,
                        foregroundColor: TmColors.black,
                        disabledBackgroundColor: TmColors.grey300,
                        disabledForegroundColor: TmColors.grey700,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                        elevation: 0,
                      ),
                      child: Text(
                        'Confirm Location',
                        style: GoogleFonts.inter(color: TmColors.black, fontSize: 15, letterSpacing: 0.2),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
