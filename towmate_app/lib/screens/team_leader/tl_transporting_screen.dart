import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/demo_flags.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/team_leader_service.dart';

class TlTransportingScreen extends StatefulWidget {
  const TlTransportingScreen({
    super.key,
    required this.task,
    required this.onUpdate,
    required this.onNavigateToDropoff,
  });
  final TaskModel task;
  final void Function(TaskModel) onUpdate;
  final VoidCallback onNavigateToDropoff;

  @override
  State<TlTransportingScreen> createState() => _TlTransportingScreenState();
}

class _TlTransportingScreenState extends State<TlTransportingScreen> {
  bool _loading = false;

  Future<void> _arrive() async {
    setState(() => _loading = true);
    double? lat;
    double? lng;
    try {
      final pos = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 10),
        ),
      );
      lat = pos.latitude;
      lng = pos.longitude;
    } catch (_) {}
    final res = await TeamLeaderService.updateStatus(
        widget.task.bookingCode, 'arrived_dropoff',
        lat: lat, lng: lng);
    if (!mounted) return;
    if (res['success'] == true) {
      widget.onUpdate(widget.task.copyWith(status: 'arrived_dropoff'));
    } else {
      setState(() => _loading = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(res['message'] as String? ?? 'Failed.'),
          backgroundColor: TmColors.error));
    }
  }

  Future<void> _back() async {
    setState(() => _loading = true);
    final res = await TeamLeaderService.updateStatus(
        widget.task.bookingCode, 'loading_vehicle');
    if (!mounted) return;
    if (res['success'] == true) {
      widget.onUpdate(widget.task.copyWith(status: 'loading_vehicle'));
    } else {
      setState(() => _loading = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(res['message'] as String? ?? 'Failed.'),
          backgroundColor: TmColors.error));
    }
  }

  Future<void> _testArrive() async {
    setState(() => _loading = true);
    final res = await TeamLeaderService.updateStatus(
        widget.task.bookingCode, 'arrived_dropoff',
        lat: widget.task.dropoffLat,
        lng: widget.task.dropoffLng,
        isDemo: true);
    if (!mounted) return;
    if (res['success'] == true) {
      widget.onUpdate(widget.task.copyWith(status: 'arrived_dropoff'));
    } else {
      setState(() => _loading = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(res['message'] as String? ?? 'Failed.'),
          backgroundColor: TmColors.error));
    }
  }

  @override
  Widget build(BuildContext context) {
    final task = widget.task;
    return Container(
      width: double.infinity,
      height: double.infinity,
      color: context.bg,
      child: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 28),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _stepHeader(context),
              const SizedBox(height: 20),
              _taskCard(context, task),
              const SizedBox(height: 16),
              _actionContext(context),
              const SizedBox(height: 18),
              _primaryBtn('Arrived at Drop-off', _loading ? null : _arrive),
              if (kTlDemoArrivalVisible) ...[
                const SizedBox(height: 12),
                _demoBtn(context, 'Demo Arrival', _loading ? null : _testArrive),
              ],
              const SizedBox(height: 12),
              _secondaryBtn(context, 'Back', _back),
            ],
          ),
        ),
      ),
    );
  }

  Widget _stepHeader(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Towing',
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 26,
            fontWeight: FontWeight.w800,
            letterSpacing: -0.5,
            height: 1.15,
          ),
        ),
        const SizedBox(height: 14),
        Row(
          children: [
            Text(
              'Step 3 of 6',
              style: GoogleFonts.inter(
                color: context.textTertiary,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
            const Spacer(),
            Text(
              '3 / 6',
              style: GoogleFonts.inter(
                color: context.textTertiary,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: 3 / 6,
            minHeight: 6,
            backgroundColor: context.divider,
            valueColor: const AlwaysStoppedAnimation(TmColors.yellow),
          ),
        ),
      ],
    );
  }

  Widget _taskCard(BuildContext context, TaskModel task) {
    final hasNote = task.notes != null && task.notes!.trim().isNotEmpty;
    final serviceDetails = '${task.truckTypeName} · ${task.distanceKm.toStringAsFixed(1)} km · '
        '${task.serviceType == 'book_now' ? 'Immediate' : 'Scheduled'}';
    return Container(
      width: double.infinity,
      decoration: BoxDecoration(
        color: context.card,
        borderRadius: BorderRadius.circular(23),
        border: Border.all(color: context.divider),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: context.isDark ? 0.24 : 0.06),
            blurRadius: 20,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 15),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _label(context, 'DROP-OFF'),
                const SizedBox(height: 6),
                Text(
                  task.dropoffAddress,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 17,
                    fontWeight: FontWeight.w800,
                    height: 1.3,
                  ),
                ),
                const SizedBox(height: 12),
                _navigateBtn(),
              ],
            ),
          ),
          Divider(height: 1, thickness: 1, color: context.divider),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 13),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _label(context, 'CUSTOMER'),
                const SizedBox(height: 6),
                Text(
                  task.customerName,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 16,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  task.customerPhone,
                  style: GoogleFonts.inter(
                    color: context.textTertiary,
                    fontSize: 14,
                  ),
                ),
              ],
            ),
          ),
          Divider(height: 1, thickness: 1, color: context.divider),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 13),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _label(context, 'SERVICE DETAILS'),
                const SizedBox(height: 6),
                Text(
                  serviceDetails,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 15,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
          Divider(height: 1, thickness: 1, color: context.divider),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 13),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _label(context, 'PICKUP'),
                const SizedBox(height: 6),
                Text(
                  task.pickupAddress,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 15,
                    fontWeight: FontWeight.w600,
                    height: 1.3,
                  ),
                ),
              ],
            ),
          ),
          if (hasNote) ...[
            Divider(height: 1, thickness: 1, color: context.divider),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 13),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _label(context, 'CUSTOMER NOTE'),
                  const SizedBox(height: 6),
                  Text(
                    task.notes!,
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 14,
                      height: 1.4,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _label(BuildContext context, String text) {
    return Text(
      text,
      style: GoogleFonts.inter(
        color: context.textTertiary,
        fontSize: 11.5,
        fontWeight: FontWeight.w800,
        letterSpacing: 1.0,
      ),
    );
  }

  Widget _navigateBtn() {
    return SizedBox(
      height: 40,
      child: ElevatedButton(
        onPressed: widget.onNavigateToDropoff,
        style: ElevatedButton.styleFrom(
          backgroundColor: TmColors.yellow,
          foregroundColor: TmColors.black,
          padding: const EdgeInsets.symmetric(horizontal: 14),
          minimumSize: Size.zero,
          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          elevation: 0,
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.navigation_rounded, color: TmColors.black, size: 15),
            const SizedBox(width: 6),
            Flexible(
              child: Text(
                'Navigate to Drop-off',
                overflow: TextOverflow.ellipsis,
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
            const SizedBox(width: 4),
            const Icon(Icons.chevron_right_rounded, color: TmColors.black, size: 15),
          ],
        ),
      ),
    );
  }

  Widget _actionContext(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Approaching Drop-off',
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 16,
            fontWeight: FontWeight.w800,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          'Confirm arrival once you reach the destination.',
          style: GoogleFonts.inter(
            color: context.textTertiary,
            fontSize: 13,
            height: 1.4,
          ),
        ),
      ],
    );
  }

  Widget _primaryBtn(String label, VoidCallback? onTap) {
    return SizedBox(
      width: double.infinity,
      height: 56,
      child: ElevatedButton(
        onPressed: onTap,
        style: ElevatedButton.styleFrom(
          backgroundColor: TmColors.yellow,
          foregroundColor: TmColors.black,
          disabledBackgroundColor: TmColors.yellow.withValues(alpha: 0.6),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          elevation: 0,
        ),
        child: _loading
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(
                  color: TmColors.black,
                  strokeWidth: 2,
                ),
              )
            : Text(
                label,
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                ),
              ),
      ),
    );
  }

  Widget _demoBtn(BuildContext context, String label, VoidCallback? onTap) {
    return SizedBox(
      width: double.infinity,
      height: 56,
      child: OutlinedButton(
        onPressed: onTap,
        style: OutlinedButton.styleFrom(
          backgroundColor: context.card,
          side: const BorderSide(color: TmColors.yellow, width: 2),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
        child: Text(
          label,
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 15,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    );
  }

  Widget _secondaryBtn(BuildContext context, String label, VoidCallback? onTap) {
    return SizedBox(
      width: double.infinity,
      height: 56,
      child: OutlinedButton(
        onPressed: onTap,
        style: OutlinedButton.styleFrom(
          backgroundColor: context.card,
          side: BorderSide(color: context.divider),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
        child: Text(
          label,
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 15,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    );
  }
}
