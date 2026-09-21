import 'dart:async';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:google_navigation_flutter/google_navigation_flutter.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';

enum _NavStatus { initializing, permissionDenied, error, ready }

class TlNavigateScreen extends StatefulWidget {
  const TlNavigateScreen({super.key, required this.task});
  final TaskModel task;

  @override
  State<TlNavigateScreen> createState() => _TlNavigateScreenState();
}

class _TlNavigateScreenState extends State<TlNavigateScreen> {
  GoogleNavigationViewController? _viewController;
  StreamSubscription<NavInfoEvent>? _navInfoSub;
  _NavStatus _status = _NavStatus.initializing;
  String? _errorMessage;
  bool _sessionInitialized = false;
  String? _destinationKeyStarted;
  StepInfo? _currentStep;
  int? _remainingMeters;
  int? _remainingSeconds;

  LatLng get _pickupPoint =>
      LatLng(latitude: widget.task.pickupLat, longitude: widget.task.pickupLng);
  LatLng get _dropoffPoint =>
      LatLng(latitude: widget.task.dropoffLat, longitude: widget.task.dropoffLng);

  bool get _isDropoffPhase {
    const dropoffStatuses = {'on_job', 'arrived_dropoff', 'waiting_verification'};
    return dropoffStatuses.contains(widget.task.status);
  }

  LatLng get _destinationPoint => _isDropoffPhase ? _dropoffPoint : _pickupPoint;

  String get _destinationAddress =>
      _isDropoffPhase ? widget.task.dropoffAddress : widget.task.pickupAddress;

  String get _destinationLabel => _isDropoffPhase ? 'Drop-off' : 'Pickup';

  String get _destinationKey =>
      '${_destinationPoint.latitude},${_destinationPoint.longitude}';

  @override
  void initState() {
    super.initState();
    _initializeNavigation();
  }

  @override
  void didUpdateWidget(TlNavigateScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.task.status != widget.task.status &&
        _status == _NavStatus.ready) {
      _setDestination();
    }
  }

  Future<void> _initializeNavigation() async {
    setState(() {
      _status = _NavStatus.initializing;
      _errorMessage = null;
    });

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      if (!mounted) return;
      setState(() => _status = _NavStatus.permissionDenied);
      return;
    }

    try {
      if (!await GoogleMapsNavigator.areTermsAccepted()) {
        final accepted = await GoogleMapsNavigator.showTermsAndConditionsDialog(
          'Navigation Terms of Service',
          'TowMate',
        );
        if (!accepted) {
          if (!mounted) return;
          setState(() {
            _status = _NavStatus.error;
            _errorMessage = 'Navigation terms must be accepted to continue.';
          });
          return;
        }
      }

      await GoogleMapsNavigator.initializeNavigationSession(
        taskRemovedBehavior: TaskRemovedBehavior.continueService,
      );
      _sessionInitialized = true;

      if (!mounted) return;
      setState(() => _status = _NavStatus.ready);
    } on SessionInitializationException catch (e) {
      if (!mounted) return;
      setState(() {
        _status = e.code == SessionInitializationError.locationPermissionMissing
            ? _NavStatus.permissionDenied
            : _NavStatus.error;
        _errorMessage = switch (e.code) {
          SessionInitializationError.notAuthorized =>
            'Navigation could not start. Please contact support.',
          SessionInitializationError.locationPermissionMissing =>
            'Location permission is required to navigate.',
          SessionInitializationError.termsNotAccepted =>
            'Navigation terms must be accepted to continue.',
        };
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _status = _NavStatus.error;
        _errorMessage = 'Could not start navigation. Please try again.';
      });
    }
  }

  Future<void> _onViewCreated(GoogleNavigationViewController controller) async {
    _viewController = controller;
    try {
      await controller.setMyLocationEnabled(true);
    } catch (_) {}
    await _setDestination();
  }

  Future<void> _setDestination() async {
    if (_viewController == null) return;
    if (_destinationKeyStarted == _destinationKey) return;

    final label = _destinationLabel;
    try {
      final status = await GoogleMapsNavigator.setDestinations(
        Destinations(
          waypoints: [
            NavigationWaypoint.withLatLngTarget(
              title: label,
              target: _destinationPoint,
            ),
          ],
          displayOptions: NavigationDisplayOptions(
            showDestinationMarkers: true,
            showStopSigns: true,
            showTrafficLights: true,
          ),
        ),
      );

      if (!mounted) return;
      if (status == NavigationRouteStatus.statusOk) {
        _destinationKeyStarted = _destinationKey;
        await GoogleMapsNavigator.startGuidance();
        _navInfoSub ??= GoogleMapsNavigator.setNavInfoListener(
          _onNavInfo,
          numNextStepsToPreview: 0,
        );
      } else {
        setState(() {
          _status = _NavStatus.error;
          _errorMessage = 'Could not calculate a route to the $label location.';
        });
      }
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _status = _NavStatus.error;
        _errorMessage = 'Could not start guidance. Please try again.';
      });
    }
  }

  void _onNavInfo(NavInfoEvent event) {
    if (!mounted) return;
    setState(() {
      _currentStep = event.navInfo.currentStep;
      _remainingMeters = event.navInfo.distanceToFinalDestinationMeters;
      _remainingSeconds = event.navInfo.timeToFinalDestinationSeconds;
    });
  }

  @override
  void dispose() {
    _navInfoSub?.cancel();
    if (_sessionInitialized) {
      GoogleMapsNavigator.cleanup();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_status == _NavStatus.permissionDenied) {
      return _StatusMessage(
        icon: Icons.location_off_rounded,
        title: 'Location permission needed',
        message:
            'Enable location access for TowMate in your device settings, then try again.',
        onRetry: _initializeNavigation,
      );
    }

    if (_status == _NavStatus.error) {
      return _StatusMessage(
        icon: Icons.error_outline_rounded,
        title: 'Navigation unavailable',
        message: _errorMessage ?? 'Something went wrong starting navigation.',
        onRetry: _initializeNavigation,
      );
    }

    if (_status == _NavStatus.initializing) {
      return const Center(
        child: CircularProgressIndicator(color: TmColors.yellow),
      );
    }

    return Column(
      children: [
        Expanded(
          child: GoogleMapsNavigationView(
            onViewCreated: _onViewCreated,
            initialCameraPosition: CameraPosition(
              target: _destinationPoint,
              zoom: 14,
            ),
            initialNavigationUIEnabledPreference:
                NavigationUIEnabledPreference.automatic,
          ),
        ),
        _BottomCard(
          destinationLabel: _destinationLabel,
          destinationAddress: _destinationAddress,
          instruction: _currentStep?.fullInstructions,
          remainingMeters: _remainingMeters,
          remainingSeconds: _remainingSeconds,
          isGpsActive: widget.task.isGpsPhase,
        ),
      ],
    );
  }
}

class _StatusMessage extends StatelessWidget {
  const _StatusMessage({
    required this.icon,
    required this.title,
    required this.message,
    required this.onRetry,
  });

  final IconData icon;
  final String title;
  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 44, color: TmColors.grey500),
            const SizedBox(height: 16),
            Text(
              title,
              style: GoogleFonts.inter(
                color: TmColors.black,
                fontSize: 17,
                fontWeight: FontWeight.w700,
              ),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 6),
            Text(
              message,
              style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 13),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 20),
            ElevatedButton(
              onPressed: onRetry,
              style: ElevatedButton.styleFrom(
                backgroundColor: TmColors.black,
                foregroundColor: TmColors.white,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(10),
                ),
                padding:
                    const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
                elevation: 0,
              ),
              child: Text(
                'Try Again',
                style:
                    GoogleFonts.inter(fontSize: 14, fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _BottomCard extends StatelessWidget {
  const _BottomCard({
    required this.destinationLabel,
    required this.destinationAddress,
    this.instruction,
    this.remainingMeters,
    this.remainingSeconds,
    required this.isGpsActive,
  });

  final String destinationLabel;
  final String destinationAddress;
  final String? instruction;
  final int? remainingMeters;
  final int? remainingSeconds;
  final bool isGpsActive;

  String _formatDistance(int meters) {
    if (meters >= 1000) {
      return '${(meters / 1000).toStringAsFixed(1)} km';
    }
    return '$meters m';
  }

  String _formatDuration(int seconds) {
    final minutes = (seconds / 60).round();
    if (minutes >= 60) {
      final hours = minutes ~/ 60;
      final remMinutes = minutes % 60;
      return '${hours}h ${remMinutes}m';
    }
    return '$minutes min';
  }

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
            if (instruction != null) ...[
              const SizedBox(height: 8),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.turn_slight_right_rounded,
                      size: 15, color: TmColors.grey700),
                  const SizedBox(width: 5),
                  Expanded(
                    child: Text(
                      instruction!,
                      style: GoogleFonts.inter(
                          color: TmColors.grey700, fontSize: 12.5),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ],
              ),
            ],
            if (remainingMeters != null || remainingSeconds != null) ...[
              const SizedBox(height: 6),
              Row(
                children: [
                  if (remainingMeters != null) ...[
                    const Icon(Icons.straighten_rounded,
                        size: 13, color: TmColors.grey500),
                    const SizedBox(width: 4),
                    Text(
                      _formatDistance(remainingMeters!),
                      style: GoogleFonts.inter(
                          color: TmColors.grey500, fontSize: 12),
                    ),
                  ],
                  if (remainingMeters != null && remainingSeconds != null)
                    const SizedBox(width: 14),
                  if (remainingSeconds != null) ...[
                    const Icon(Icons.schedule_rounded,
                        size: 13, color: TmColors.grey500),
                    const SizedBox(width: 4),
                    Text(
                      _formatDuration(remainingSeconds!),
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
