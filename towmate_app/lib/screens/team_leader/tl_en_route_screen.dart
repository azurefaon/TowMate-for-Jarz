import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/demo_flags.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/team_leader_service.dart';
import 'tl_return_screen.dart';

class TlEnRouteScreen extends StatefulWidget {
  const TlEnRouteScreen({
    super.key,
    required this.task,
    required this.onUpdate,
    required this.onNavigateToPickup,
  });
  final TaskModel task;
  final void Function(TaskModel) onUpdate;
  final VoidCallback onNavigateToPickup;

  @override
  State<TlEnRouteScreen> createState() => _TlEnRouteScreenState();
}

class _TlEnRouteScreenState extends State<TlEnRouteScreen> {
  bool _loading = false;

  Future<bool> _ensureOnTheWay() async {
    if (widget.task.status != 'accepted') return true;
    final res = await TeamLeaderService.updateStatus(
      widget.task.bookingCode,
      'on_the_way',
    );
    if (!mounted) return false;
    if (res['success'] != true) {
      _showError(res['message'] as String? ?? 'Failed.');
      return false;
    }
    widget.onUpdate(widget.task.copyWith(status: 'on_the_way'));
    return true;
  }

  Future<void> _arrive() async {
    setState(() => _loading = true);
    if (!await _ensureOnTheWay()) {
      if (mounted) setState(() => _loading = false);
      return;
    }
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
      widget.task.bookingCode,
      'arrived_pickup',
      lat: lat,
      lng: lng,
    );
    if (!mounted) return;
    if (res['success'] == true) {
      widget.onUpdate(widget.task.copyWith(status: 'arrived_pickup'));
      return;
    }
    await _reconcileAfterFailure();
  }

  Future<void> _back() async {
    if (widget.task.status == 'accepted') return;
    setState(() => _loading = true);
    final res = await TeamLeaderService.updateStatus(
      widget.task.bookingCode,
      'accepted',
    );
    if (!mounted) return;
    if (res['success'] == true) {
      widget.onUpdate(widget.task.copyWith(status: 'accepted'));
    } else {
      setState(() => _loading = false);
      _showError(res['message'] as String? ?? 'Failed.');
    }
  }

  Future<void> _testArrive() async {
    setState(() => _loading = true);
    if (!await _ensureOnTheWay()) {
      if (mounted) setState(() => _loading = false);
      return;
    }
    final res = await TeamLeaderService.updateStatus(
      widget.task.bookingCode,
      'arrived_pickup',
      lat: widget.task.pickupLat,
      lng: widget.task.pickupLng,
      isDemo: true,
    );
    if (!mounted) return;
    if (res['success'] == true) {
      widget.onUpdate(widget.task.copyWith(status: 'arrived_pickup'));
      return;
    }
    await _reconcileAfterFailure();
  }

  Future<void> _reconcileAfterFailure() async {
    TaskModel? latest;
    try {
      latest = await TeamLeaderService.getCurrentTask();
    } catch (_) {
      latest = null;
    }
    if (!mounted) return;
    setState(() => _loading = false);
    if (latest != null &&
        latest.bookingCode == widget.task.bookingCode &&
        latest.status != widget.task.status) {
      widget.onUpdate(latest);
      return;
    }
    _showError('Failed to update. Please try again.');
  }

  Future<void> _return() async {
    final ok = await Navigator.push<bool>(
      context,
      MaterialPageRoute(builder: (_) => TlReturnScreen(task: widget.task)),
    );
    if (ok == true && mounted) {
      widget.onUpdate(widget.task.copyWith(status: 'returned'));
    }
  }

  void _showError(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(msg), backgroundColor: TmColors.error),
    );
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
              const SizedBox(height: 24),
              _taskCard(context, task),
              const SizedBox(height: 20),
              _primaryBtn('Arrived at Pickup', _arrive),
              if (kTlDemoArrivalVisible) ...[
                const SizedBox(height: 12),
                _demoBtn(context, 'Demo Arrival', _testArrive),
              ],
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(child: _backBtn(context)),
                  const SizedBox(width: 12),
                  Expanded(child: _returnBtn(context)),
                ],
              ),
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
          'En Route to Pickup',
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
              'Step 1 of 6',
              style: GoogleFonts.inter(
                color: context.textTertiary,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
            const Spacer(),
            Text(
              '1 / 6',
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
            value: 1 / 6,
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
        children: [
          Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _iconRow(
                  context,
                  icon: Icons.location_on_rounded,
                  label: 'PICKUP',
                  child: Text(
                    task.pickupAddress,
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 17,
                      fontWeight: FontWeight.w700,
                      height: 1.3,
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                _iconRow(
                  context,
                  icon: Icons.route_rounded,
                  crossAxisAlignment: CrossAxisAlignment.center,
                  child: Text(
                    '${task.distanceKm.toStringAsFixed(1)} km',
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
                const SizedBox(height: 18),
                _navigateBtn(),
              ],
            ),
          ),
          Divider(height: 1, thickness: 1, color: context.divider),
          Padding(
            padding: const EdgeInsets.all(18),
            child: _iconRow(
              context,
              icon: Icons.person_outline_rounded,
              label: 'CUSTOMER',
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    task.customerName,
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 16,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Row(
                    children: [
                      Icon(Icons.call_outlined, size: 14, color: context.textTertiary),
                      const SizedBox(width: 6),
                      Flexible(
                        child: Text(
                          task.customerPhone,
                          style: GoogleFonts.inter(
                            color: context.textTertiary,
                            fontSize: 14,
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
          Divider(height: 1, thickness: 1, color: context.divider),
          Padding(
            padding: const EdgeInsets.all(18),
            child: _iconRow(
              context,
              icon: Icons.local_shipping_outlined,
              label: 'SERVICE TYPE',
              child: Text(
                task.truckTypeName,
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ),
          Divider(height: 1, thickness: 1, color: context.divider),
          Padding(
            padding: const EdgeInsets.all(18),
            child: _iconRow(
              context,
              icon: Icons.schedule_rounded,
              label: 'SCHEDULE',
              child: Text(
                task.serviceType == 'book_now' ? 'Immediate' : 'Scheduled',
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ),
          if (hasNote) ...[
            Divider(height: 1, thickness: 1, color: context.divider),
            Padding(
              padding: const EdgeInsets.all(18),
              child: _iconRow(
                context,
                icon: Icons.notes_rounded,
                label: 'CUSTOMER NOTE',
                child: Text(
                  task.notes!,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 14,
                    height: 1.4,
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _iconRow(
    BuildContext context, {
    required IconData icon,
    String? label,
    required Widget child,
    CrossAxisAlignment crossAxisAlignment = CrossAxisAlignment.start,
  }) {
    return Row(
      crossAxisAlignment: crossAxisAlignment,
      children: [
        Icon(icon, size: 20, color: context.textTertiary),
        const SizedBox(width: 14),
        Expanded(
          child: label == null
              ? child
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      label,
                      style: GoogleFonts.inter(
                        color: context.textTertiary,
                        fontSize: 11.5,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 1.0,
                      ),
                    ),
                    const SizedBox(height: 7),
                    child,
                  ],
                ),
        ),
      ],
    );
  }

  Widget _navigateBtn() {
    return SizedBox(
      width: double.infinity,
      height: 54,
      child: ElevatedButton(
        onPressed: widget.onNavigateToPickup,
        style: ElevatedButton.styleFrom(
          backgroundColor: TmColors.yellow,
          foregroundColor: TmColors.black,
          padding: const EdgeInsets.symmetric(horizontal: 10),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          elevation: 0,
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.navigation_rounded, color: TmColors.black, size: 18),
            const SizedBox(width: 8),
            Flexible(
              child: Text(
                'Navigate to Pickup',
                textAlign: TextAlign.center,
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
            const SizedBox(width: 6),
            const Icon(Icons.chevron_right_rounded, color: TmColors.black, size: 18),
          ],
        ),
      ),
    );
  }

  Widget _primaryBtn(String label, VoidCallback onTap) {
    return SizedBox(
      width: double.infinity,
      height: 56,
      child: ElevatedButton(
        onPressed: _loading ? null : onTap,
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

  Widget _demoBtn(BuildContext context, String label, VoidCallback onTap) {
    return SizedBox(
      width: double.infinity,
      height: 56,
      child: OutlinedButton(
        onPressed: _loading ? null : onTap,
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

  Widget _backBtn(BuildContext context) {
    return SizedBox(
      height: 56,
      child: OutlinedButton(
        onPressed: _back,
        style: OutlinedButton.styleFrom(
          backgroundColor: context.surface,
          side: BorderSide.none,
          padding: const EdgeInsets.symmetric(horizontal: 8),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.arrow_back_rounded, color: context.textPrimary, size: 18),
            const SizedBox(width: 6),
            Flexible(
              child: Text(
                'Back',
                textAlign: TextAlign.center,
                overflow: TextOverflow.ellipsis,
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _returnBtn(BuildContext context) {
    return SizedBox(
      height: 56,
      child: ElevatedButton(
        onPressed: _return,
        style: ElevatedButton.styleFrom(
          backgroundColor: TmColors.error,
          foregroundColor: TmColors.white,
          padding: const EdgeInsets.symmetric(horizontal: 8),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          elevation: 0,
        ),
        child: Text(
          'Return Task',
          textAlign: TextAlign.center,
          overflow: TextOverflow.ellipsis,
          style: GoogleFonts.inter(
            color: TmColors.white,
            fontSize: 15,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    );
  }
}
