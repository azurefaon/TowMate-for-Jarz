import 'dart:async';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../../core/theme.dart';
import '../../models/booking_model.dart';
import '../../models/tracking_model.dart';
import '../../services/api_service.dart';
import '../../services/live_tracking_controller.dart';
import '../../widgets/live_tracking_widgets.dart';

/// Customer Live Tracking v1 — dedicated map for exactly ONE booking_code.
///
/// Truck marker and route appear only while the backend returns a current
/// location (live/updating). Unavailable keeps only the destination; when
/// the backend reports tracking=false the screen shows an ended state and
/// stops polling, but stays open.
class LiveTrackingScreen extends StatefulWidget {
  const LiveTrackingScreen({super.key, required this.bookingCode});
  final String bookingCode;

  @override
  State<LiveTrackingScreen> createState() => _LiveTrackingScreenState();
}

class _LiveTrackingScreenState extends State<LiveTrackingScreen> {
  static const _manila = LatLng(14.5995, 120.9842);

  BookingModel? _booking;
  GoogleMapController? _map;
  BitmapDescriptor? _truckIcon;
  String? _framedFor;

  @override
  void initState() {
    super.initState();
    _loadBooking();
    _buildTruckIcon();
  }

  Future<void> _loadBooking() async {
    final b = await ApiService.fetchBookingDetail(widget.bookingCode);
    if (!mounted || b == null) return;
    setState(() => _booking = b);
  }

  /// Truck marker drawn from Icons.local_shipping_outlined (no image asset).
  Future<void> _buildTruckIcon() async {
    try {
      const size = 96.0;
      final recorder = ui.PictureRecorder();
      final canvas = Canvas(recorder);
      const center = Offset(size / 2, size / 2);
      canvas.drawCircle(center, size / 2, Paint()..color = TmColors.black);
      canvas.drawCircle(center, size / 2 - 6, Paint()..color = TmColors.yellow);
      const icon = Icons.local_shipping_outlined;
      final painter = TextPainter(
        textDirection: TextDirection.ltr,
        text: TextSpan(
          text: String.fromCharCode(icon.codePoint),
          style: TextStyle(
            fontSize: 52,
            fontFamily: icon.fontFamily,
            package: icon.fontPackage,
            color: TmColors.black,
          ),
        ),
      )..layout();
      painter.paint(canvas, Offset((size - painter.width) / 2, (size - painter.height) / 2));
      final image = await recorder.endRecording().toImage(size.toInt(), size.toInt());
      final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
      if (bytes == null || !mounted) return;
      setState(() {
        _truckIcon = BitmapDescriptor.bytes(bytes.buffer.asUint8List(), width: 40, height: 40);
      });
    } catch (_) {
      // Fall back to the default marker below.
    }
  }

  @override
  void dispose() {
    _map?.dispose();
    super.dispose();
  }

  void _openDetails() {
    Navigator.pushNamed(context, '/booking-detail', arguments: widget.bookingCode);
  }

  /// Frame truck + destination once per phase (not on every poll, so the
  /// customer can pan freely).
  void _maybeFrame(TrackingSnapshot? s) {
    final map = _map;
    if (map == null || s == null) return;
    final key = '${s.phase}-${s.hasCurrentLocation}';
    if (key == _framedFor) return;
    final points = <LatLng>[
      if (s.hasCurrentLocation) LatLng(s.location!.lat, s.location!.lng),
      if (s.destination != null) LatLng(s.destination!.lat, s.destination!.lng),
    ];
    if (points.isEmpty) return;
    _framedFor = key;
    if (points.length == 1) {
      unawaited(map.animateCamera(CameraUpdate.newLatLngZoom(points.first, 15)));
      return;
    }
    final sw = LatLng(
      points.map((p) => p.latitude).reduce((a, b) => a < b ? a : b),
      points.map((p) => p.longitude).reduce((a, b) => a < b ? a : b),
    );
    final ne = LatLng(
      points.map((p) => p.latitude).reduce((a, b) => a > b ? a : b),
      points.map((p) => p.longitude).reduce((a, b) => a > b ? a : b),
    );
    unawaited(map.animateCamera(CameraUpdate.newLatLngBounds(LatLngBounds(southwest: sw, northeast: ne), 64)));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      body: SafeArea(
        child: LiveTrackingScope(
          bookingCode: widget.bookingCode,
          enabled: true,
          builder: (context, controller) {
            WidgetsBinding.instance.addPostFrameCallback((_) {
              if (mounted && controller != null && !controller.ended) _maybeFrame(controller.snapshot);
            });
            return Column(
              children: [
                _header(context),
                Expanded(
                  child: LayoutBuilder(
                    builder: (context, constraints) {
                      final mapHeight = constraints.maxHeight * 0.58;
                      return Column(
                        children: [
                          SizedBox(height: mapHeight, child: _mapView(controller)),
                          Expanded(child: _InfoCard(
                            controller: controller,
                            booking: _booking,
                            onViewDetails: _openDetails,
                          )),
                        ],
                      );
                    },
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _header(BuildContext context) {
    final vehicle = _booking?.displayVehicleName ?? '';
    final subtitle = vehicle.isNotEmpty ? '${widget.bookingCode} · $vehicle' : widget.bookingCode;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: context.divider, width: 0.5)),
      ),
      child: Row(
        children: [
          IconButton(
            tooltip: 'Back',
            icon: Icon(Icons.arrow_back_rounded, color: context.textTertiary),
            onPressed: () => Navigator.maybePop(context),
            padding: EdgeInsets.zero,
            constraints: const BoxConstraints(),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Live Tracking',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 17,
                    fontWeight: FontWeight.w700,
                    letterSpacing: -0.2,
                  ),
                ),
                Text(
                  subtitle,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: GoogleFonts.inter(color: context.textTertiary, fontSize: 12),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _mapView(LiveTrackingController? controller) {
    final ended = controller?.ended ?? false;
    final snap = controller?.snapshot;
    final booking = _booking;

    // Destination: from the tracking answer; once tracking ended, fall back
    // to the booking's own pickup/drop-off for the phase that was shown.
    TrackingPoint? dest = snap?.destination;
    final destIsPickup = snap?.isPickupPhase ?? (booking?.status == 'on_the_way');
    if (dest == null && booking != null) {
      final lat = destIsPickup ? booking.pickupLat : booking.dropoffLat;
      final lng = destIsPickup ? booking.pickupLng : booking.dropoffLng;
      if (lat != null && lng != null) dest = TrackingPoint(lat, lng);
    }

    final showTruck = !ended && snap != null && snap.hasCurrentLocation;
    final route = showTruck ? controller!.routePoints : const <LatLng>[];

    final markers = <Marker>{
      if (dest != null)
        Marker(
          markerId: MarkerId(destIsPickup ? 'pickup' : 'dropoff'),
          position: LatLng(dest.lat, dest.lng),
          icon: BitmapDescriptor.defaultMarkerWithHue(
            destIsPickup ? BitmapDescriptor.hueViolet : BitmapDescriptor.hueYellow,
          ),
          infoWindow: InfoWindow(title: destIsPickup ? 'Pickup' : 'Drop-off'),
        ),
      if (showTruck)
        Marker(
          markerId: const MarkerId('truck'),
          position: LatLng(snap.location!.lat, snap.location!.lng),
          icon: _truckIcon ?? BitmapDescriptor.defaultMarker,
          anchor: const Offset(0.5, 0.5),
          infoWindow: const InfoWindow(title: 'Tow truck'),
        ),
    };

    return GoogleMap(
      key: const ValueKey('live-tracking-map'),
      initialCameraPosition: CameraPosition(
        target: dest != null ? LatLng(dest.lat, dest.lng) : _manila,
        zoom: 14,
      ),
      onMapCreated: (c) {
        _map = c;
        if (controller != null && !controller.ended) _maybeFrame(controller.snapshot);
      },
      zoomControlsEnabled: false,
      myLocationButtonEnabled: false,
      mapToolbarEnabled: false,
      markers: markers,
      polylines: {
        if (route.length >= 2)
          Polyline(
            polylineId: const PolylineId('route'),
            points: route,
            color: Colors.black,
            width: 4,
          ),
      },
    );
  }
}

class _InfoCard extends StatelessWidget {
  const _InfoCard({required this.controller, required this.booking, required this.onViewDetails});
  final LiveTrackingController? controller;
  final BookingModel? booking;
  final VoidCallback onViewDetails;

  @override
  Widget build(BuildContext context) {
    final c = controller;
    final snap = c?.snapshot;
    final ended = c?.ended ?? false;
    final pickup = snap?.isPickupPhase ?? (booking?.status == 'on_the_way');
    final destinationAddress = booking == null ? null : (pickup ? booking!.pickupAddress : booking!.dropoffAddress);

    final String headline;
    if (ended) {
      headline = 'Live tracking has ended';
    } else {
      headline = pickup ? 'Tow truck heading to pickup' : 'Vehicle heading to drop-off';
    }

    final Widget body;
    if (ended) {
      body = Text(
        'This booking is no longer on the way, so live location is off. '
        'Check the booking details for the latest status.',
        style: GoogleFonts.inter(color: secondaryTrackingText(context), fontSize: 13.5, height: 1.4),
      );
    } else if (c == null || snap == null) {
      body = Text(
        'Connecting to live location…',
        style: GoogleFonts.inter(color: secondaryTrackingText(context), fontSize: 13.5),
      );
    } else if (!snap.hasCurrentLocation) {
      body = TrackingUnavailableNotice(snapshot: snap);
    } else {
      body = TrackingEtaDistance(
        controller: c,
        etaLabel: pickup ? 'ETA TO PICKUP' : 'ETA TO DROP-OFF',
        large: true,
      );
    }

    return Container(
      key: const ValueKey('live-tracking-info-card'),
      width: double.infinity,
      decoration: BoxDecoration(
        color: context.card,
        border: Border(top: BorderSide(color: context.divider)),
      ),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 18, 20, 20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              headline,
              style: GoogleFonts.inter(
                color: context.textPrimary,
                fontSize: 19,
                fontWeight: FontWeight.w800,
                letterSpacing: -0.3,
              ),
            ),
            if (!ended) ...[
              const SizedBox(height: 6),
              TrackingFreshnessIndicator(controller: c),
            ],
            const SizedBox(height: 14),
            body,
            if (destinationAddress != null && destinationAddress.isNotEmpty) ...[
              const SizedBox(height: 14),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    pickup ? Icons.radio_button_checked_rounded : Icons.flag_rounded,
                    size: 18,
                    color: context.textTertiary,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          pickup ? 'Pickup' : 'Drop-off',
                          style: GoogleFonts.inter(color: secondaryTrackingText(context), fontSize: 12.5),
                        ),
                        Text(
                          destinationAddress,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: GoogleFonts.inter(
                            color: context.textPrimary,
                            fontSize: 14,
                            fontWeight: FontWeight.w600,
                            height: 1.3,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ],
            const SizedBox(height: 18),
            OutlinedButton(
              onPressed: onViewDetails,
              style: OutlinedButton.styleFrom(
                minimumSize: const Size(double.infinity, 48),
                side: BorderSide(color: context.divider),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              ),
              child: Text(
                'View booking details',
                style: GoogleFonts.inter(color: context.textPrimary, fontSize: 14.5, fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
