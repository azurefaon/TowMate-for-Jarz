import 'dart:async';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/api_service.dart';

class TlNavigateScreen extends StatefulWidget {
  const TlNavigateScreen({super.key, required this.task});
  final TaskModel task;

  @override
  State<TlNavigateScreen> createState() => _TlNavigateScreenState();
}

class _TlNavigateScreenState extends State<TlNavigateScreen> {
  GoogleMapController? _mapController;
  LatLng? _currentPosition;
  List<LatLng> _routePoints = [];
  double? _distanceKm;
  double? _durationMin;
  StreamSubscription<Position>? _positionSub;
  bool _initialCentered = false;
  bool _loadingRoute = false;
  LatLng? _pendingCameraTarget;
  double _pendingCameraZoom = 15;

  LatLng get _pickupPoint =>
      LatLng(widget.task.pickupLat, widget.task.pickupLng);
  LatLng get _dropoffPoint =>
      LatLng(widget.task.dropoffLat, widget.task.dropoffLng);

  bool get _hasValidDropoff =>
      widget.task.dropoffLat != 0.0 || widget.task.dropoffLng != 0.0;

  // Destination depends on what phase of the task we're in
  LatLng get _destinationPoint {
    return _isDropoffPhase ? _dropoffPoint : _pickupPoint;
  }

  bool get _isDropoffPhase {
    const dropoffStatuses = {
      'on_job', 'arrived_dropoff', 'waiting_verification'
    };
    return dropoffStatuses.contains(widget.task.status);
  }

  String get _destinationAddress =>
      _isDropoffPhase ? widget.task.dropoffAddress : widget.task.pickupAddress;

  String get _destinationLabel =>
      _isDropoffPhase ? 'Drop-off' : 'Pickup';

  @override
  void initState() {
    super.initState();
    _startTracking();
  }

  @override
  void didUpdateWidget(TlNavigateScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Reload route when task status changes (e.g. on_the_way → on_job)
    // which changes the destination from pickup to drop-off.
    if (oldWidget.task.status != widget.task.status && _currentPosition != null) {
      setState(() => _routePoints = []);
      _loadRoute(_currentPosition!);
    }
  }

  Future<void> _startTracking() async {
    final permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      await Geolocator.requestPermission();
    }

    _positionSub = Geolocator.getPositionStream(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 8,
      ),
    ).listen((pos) {
      if (!mounted) return;
      final point = LatLng(pos.latitude, pos.longitude);
      setState(() => _currentPosition = point);

      if (!_initialCentered) {
        _centerCameraOnce(point, zoom: 15);
        _loadRoute(point);
      }
    });

    // Fallback: if stream takes too long, get a one-shot position
    try {
      final pos = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 8),
        ),
      );
      if (!mounted) return;
      final point = LatLng(pos.latitude, pos.longitude);
      if (_currentPosition == null) {
        setState(() => _currentPosition = point);
        _centerCameraOnce(point, zoom: 15);
        _loadRoute(point);
      }
    } catch (_) {
      // Fall back to centering on destination if GPS unavailable
      if (!mounted) return;
      _centerCameraOnce(_destinationPoint, zoom: 14);
    }
  }

  // Centers the camera exactly once, whichever happens last: getting a
  // position/fallback target, or the GoogleMap platform view finishing
  // initialization (onMapCreated). If the controller isn't ready yet, the
  // target is queued and applied as soon as onMapCreated fires.
  void _centerCameraOnce(LatLng target, {required double zoom}) {
    if (_initialCentered) return;
    _initialCentered = true;

    if (_mapController != null) {
      _mapController!.moveCamera(CameraUpdate.newLatLngZoom(target, zoom));
    } else {
      _pendingCameraTarget = target;
      _pendingCameraZoom = zoom;
    }
  }

  Future<void> _loadRoute(LatLng from) async {
    setState(() => _loadingRoute = true);

    final result = await ApiService.calculateRoute(
      from.latitude, from.longitude,
      _destinationPoint.latitude, _destinationPoint.longitude,
    );

    if (!mounted) return;
    if (result['success'] == true) {
      final coords = result['coordinates'] as List? ?? [];
      final points = coords
          .map((c) => LatLng(
                (c[0] as num).toDouble(),
                (c[1] as num).toDouble(),
              ))
          .toList();
      setState(() {
        _routePoints = points;
        _distanceKm = (result['distance_km'] as num?)?.toDouble();
        _durationMin = result['duration_min'] != null
            ? (result['duration_min'] as num).toDouble()
            : null;
        _loadingRoute = false;
      });
    } else {
      setState(() => _loadingRoute = false);
    }
  }

  void _recenter() {
    if (_currentPosition != null) {
      _mapController?.moveCamera(
        CameraUpdate.newLatLngZoom(_currentPosition!, 15),
      );
    }
  }

  Future<void> _openInGoogleMaps() async {
    final dest = _destinationPoint;
    final uri = Uri.parse(
      'https://www.google.com/maps/dir/?api=1'
      '&destination=${dest.latitude},${dest.longitude}'
      '&travelmode=driving',
    );
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  }

  @override
  void dispose() {
    _positionSub?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Expanded(
          child: Stack(
            children: [
              GoogleMap(
                initialCameraPosition: CameraPosition(
                  target: _destinationPoint,
                  zoom: 14,
                ),
                onMapCreated: (controller) {
                  _mapController = controller;
                  if (_pendingCameraTarget != null) {
                    controller.moveCamera(CameraUpdate.newLatLngZoom(
                        _pendingCameraTarget!, _pendingCameraZoom));
                    _pendingCameraTarget = null;
                  }
                },
                zoomControlsEnabled: false,
                myLocationButtonEnabled: false,
                markers: {
                  Marker(
                    markerId: const MarkerId('pickup'),
                    position: _pickupPoint,
                    icon: BitmapDescriptor.defaultMarkerWithHue(
                        BitmapDescriptor.hueViolet),
                    infoWindow: const InfoWindow(title: 'Pickup'),
                  ),
                  if (_hasValidDropoff)
                    Marker(
                      markerId: const MarkerId('dropoff'),
                      position: _dropoffPoint,
                      icon: BitmapDescriptor.defaultMarkerWithHue(
                          BitmapDescriptor.hueYellow),
                      infoWindow: const InfoWindow(title: 'Drop-off'),
                    ),
                  if (_currentPosition != null)
                    Marker(
                      markerId: const MarkerId('current'),
                      position: _currentPosition!,
                      icon: BitmapDescriptor.defaultMarkerWithHue(
                          BitmapDescriptor.hueAzure),
                      infoWindow: const InfoWindow(title: 'You'),
                      zIndexInt: 2,
                    ),
                },
                polylines: {
                  if (_routePoints.isNotEmpty)
                    Polyline(
                      polylineId: const PolylineId('route'),
                      points: _routePoints,
                      color: TmColors.yellow,
                      width: 4,
                    ),
                },
              ),

              // Loading route indicator
              if (_loadingRoute)
                Positioned(
                  top: 12,
                  left: 0,
                  right: 0,
                  child: Center(
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 14, vertical: 6),
                      decoration: BoxDecoration(
                        color: TmColors.white,
                        borderRadius: BorderRadius.circular(20),
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withValues(alpha: 0.1),
                            blurRadius: 8,
                          ),
                        ],
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const SizedBox(
                            width: 12,
                            height: 12,
                            child: CircularProgressIndicator(
                                strokeWidth: 1.5,
                                color: TmColors.yellow),
                          ),
                          const SizedBox(width: 8),
                          Text('Calculating route…',
                              style: GoogleFonts.inter(
                                  color: TmColors.grey700, fontSize: 12)),
                        ],
                      ),
                    ),
                  ),
                ),

              // Recenter button
              Positioned(
                right: 14,
                bottom: 14,
                child: GestureDetector(
                  onTap: _recenter,
                  child: Container(
                    width: 40,
                    height: 40,
                    decoration: BoxDecoration(
                      color: TmColors.white,
                      shape: BoxShape.circle,
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withValues(alpha: 0.12),
                          blurRadius: 8,
                          offset: const Offset(0, 2),
                        ),
                      ],
                    ),
                    child: const Icon(Icons.my_location_rounded,
                        color: TmColors.black, size: 20),
                  ),
                ),
              ),
            ],
          ),
        ),

        // Bottom info card
        _BottomCard(
          destinationLabel: _destinationLabel,
          destinationAddress: _destinationAddress,
          distanceKm: _distanceKm,
          durationMin: _durationMin,
          isGpsActive: widget.task.isGpsPhase,
          onNavigate: _openInGoogleMaps,
        ),
      ],
    );
  }
}

// ── Bottom card ────────────────────────────────────────────────────────────

class _BottomCard extends StatelessWidget {
  const _BottomCard({
    required this.destinationLabel,
    required this.destinationAddress,
    this.distanceKm,
    this.durationMin,
    required this.isGpsActive,
    required this.onNavigate,
  });

  final String destinationLabel;
  final String destinationAddress;
  final double? distanceKm;
  final double? durationMin;
  final bool isGpsActive;
  final VoidCallback onNavigate;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: TmColors.white,
      padding: const EdgeInsets.fromLTRB(20, 14, 20, 20),
      child: SafeArea(
        top: false,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  width: 8,
                  height: 8,
                  decoration: BoxDecoration(
                    color: isGpsActive ? TmColors.success : TmColors.grey300,
                    shape: BoxShape.circle,
                  ),
                ),
                const SizedBox(width: 6),
                Text(
                  isGpsActive ? 'GPS Active — sending live location' : 'GPS Paused',
                  style: GoogleFonts.inter(
                      color: TmColors.grey500, fontSize: 11),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: TmColors.black,
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: Text(
                    destinationLabel,
                    style: GoogleFonts.inter(
                        color: TmColors.white,
                        fontSize: 11,
                        letterSpacing: 0.2),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    destinationAddress,
                    style: GoogleFonts.inter(
                        color: TmColors.black, fontSize: 13),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
            if (distanceKm != null || durationMin != null) ...[
              const SizedBox(height: 6),
              Row(
                children: [
                  if (distanceKm != null) ...[
                    const Icon(Icons.straighten_rounded,
                        size: 13, color: TmColors.grey500),
                    const SizedBox(width: 4),
                    Text(
                      '${distanceKm!.toStringAsFixed(1)} km',
                      style: GoogleFonts.inter(
                          color: TmColors.grey500, fontSize: 12),
                    ),
                  ],
                  if (distanceKm != null && durationMin != null)
                    const SizedBox(width: 14),
                  if (durationMin != null) ...[
                    const Icon(Icons.schedule_rounded,
                        size: 13, color: TmColors.grey500),
                    const SizedBox(width: 4),
                    Text(
                      '${durationMin!.round()} min',
                      style: GoogleFonts.inter(
                          color: TmColors.grey500, fontSize: 12),
                    ),
                  ],
                ],
              ),
            ],
            if (isGpsActive) ...[
              const SizedBox(height: 14),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  onPressed: onNavigate,
                  icon: const Icon(Icons.navigation_rounded, size: 18),
                  label: Text(
                    'Navigate',
                    style: GoogleFonts.inter(
                        fontSize: 14, fontWeight: FontWeight.w600),
                  ),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: TmColors.black,
                    foregroundColor: TmColors.white,
                    padding: const EdgeInsets.symmetric(vertical: 13),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                    elevation: 0,
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
