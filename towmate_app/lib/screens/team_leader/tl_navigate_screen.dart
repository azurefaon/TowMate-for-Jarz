import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_map_cancellable_tile_provider/flutter_map_cancellable_tile_provider.dart';
import 'package:geolocator/geolocator.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:latlong2/latlong.dart';
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
  final _mapController = MapController();
  LatLng? _currentPosition;
  List<LatLng> _routePoints = [];
  double? _distanceKm;
  double? _durationMin;
  StreamSubscription<Position>? _positionSub;
  bool _initialCentered = false;
  bool _loadingRoute = false;

  LatLng get _pickupPoint =>
      LatLng(widget.task.pickupLat, widget.task.pickupLng);
  LatLng get _dropoffPoint =>
      LatLng(widget.task.dropoffLat, widget.task.dropoffLng);

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
        _initialCentered = true;
        _mapController.move(point, 15);
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
        _mapController.move(point, 15);
        _initialCentered = true;
        _loadRoute(point);
      }
    } catch (_) {
      // Fall back to centering on destination if GPS unavailable
      if (!mounted) return;
      if (!_initialCentered) {
        _initialCentered = true;
        _mapController.move(_destinationPoint, 14);
      }
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
      _mapController.move(_currentPosition!, 15);
    }
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
              FlutterMap(
                mapController: _mapController,
                options: MapOptions(
                  initialCenter: _destinationPoint,
                  initialZoom: 14,
                ),
                children: [
                  TileLayer(
                    urlTemplate:
                        'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                    userAgentPackageName: 'com.towmate.app',
                    tileProvider: CancellableNetworkTileProvider(),
                  ),
                  if (_routePoints.isNotEmpty)
                    PolylineLayer(
                      polylines: [
                        Polyline(
                          points: _routePoints,
                          color: TmColors.yellow,
                          strokeWidth: 4.0,
                        ),
                      ],
                    ),
                  MarkerLayer(
                    markers: [
                      Marker(
                        point: _pickupPoint,
                        width: 28,
                        height: 28,
                        child: const _MapPin(label: 'P', dark: true),
                      ),
                      Marker(
                        point: _dropoffPoint,
                        width: 28,
                        height: 28,
                        child: const _MapPin(label: 'D', dark: false),
                      ),
                      if (_currentPosition != null)
                        Marker(
                          point: _currentPosition!,
                          width: 20,
                          height: 20,
                          child: const _CurrentPin(),
                        ),
                    ],
                  ),
                ],
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
        ),
      ],
    );
  }
}

// ── Same marker style as book_now_screen.dart ──────────────────────────────

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

class _CurrentPin extends StatelessWidget {
  const _CurrentPin();

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: const Color(0xFF2563EB),
        shape: BoxShape.circle,
        border: Border.all(color: TmColors.white, width: 2),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.25),
            blurRadius: 4,
            offset: const Offset(0, 2),
          ),
        ],
      ),
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
  });

  final String destinationLabel;
  final String destinationAddress;
  final double? distanceKm;
  final double? durationMin;
  final bool isGpsActive;

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
          ],
        ),
      ),
    );
  }
}
