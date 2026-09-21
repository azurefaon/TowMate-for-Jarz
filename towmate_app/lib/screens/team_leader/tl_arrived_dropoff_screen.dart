import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/team_leader_service.dart';
import 'tl_awaiting_confirm_screen.dart';

class TlArrivedDropoffScreen extends StatefulWidget {
  const TlArrivedDropoffScreen(
      {super.key, required this.task, required this.onUpdate});
  final TaskModel task;
  final void Function(TaskModel) onUpdate;

  @override
  State<TlArrivedDropoffScreen> createState() =>
      _TlArrivedDropoffScreenState();
}

class _TlArrivedDropoffScreenState extends State<TlArrivedDropoffScreen> {
  bool _loading = false;
  Timer? _waitPollTimer;

  @override
  void initState() {
    super.initState();
    _syncWaitPoll();
  }

  @override
  void didUpdateWidget(covariant TlArrivedDropoffScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    _syncWaitPoll();
  }

  @override
  void dispose() {
    _waitPollTimer?.cancel();
    super.dispose();
  }

  void _syncWaitPoll() {
    final shouldPoll =
        widget.task.isGroupBooking && widget.task.status == 'arrived_dropoff';
    if (shouldPoll && _waitPollTimer == null) {
      _waitPollTimer =
          Timer.periodic(const Duration(seconds: 5), (_) => _pollForUpdates());
    } else if (!shouldPoll && _waitPollTimer != null) {
      _waitPollTimer?.cancel();
      _waitPollTimer = null;
    }
  }

  Future<void> _pollForUpdates() async {
    TaskModel? latest;
    try {
      latest = await TeamLeaderService.getCurrentTask();
    } catch (_) {
      return;
    }
    if (!mounted || latest == null) return;
    if (latest.bookingCode == widget.task.bookingCode &&
        (latest.groupReadyForPayment != widget.task.groupReadyForPayment ||
            latest.status != widget.task.status)) {
      widget.onUpdate(latest);
    }
  }

  void _proceed() {
    if (widget.task.isGroupBooking && !widget.task.groupReadyForPayment) {
      return;
    }
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => Scaffold(
          backgroundColor: TmColors.grey100,
          body: TlAwaitingConfirmScreen(
            task: widget.task,
            onUpdate: widget.onUpdate,
          ),
        ),
      ),
    );
  }

  Future<void> _back() async {
    setState(() => _loading = true);
    final res =
        await TeamLeaderService.updateStatus(widget.task.bookingCode, 'on_job');
    if (!mounted) return;
    if (res['success'] == true) {
      widget.onUpdate(widget.task.copyWith(status: 'on_job'));
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
    final waitingOnGroup = task.isGroupBooking && !task.groupReadyForPayment;

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
              const SizedBox(height: 22),
              _actionContext(context, task, waitingOnGroup),
              const SizedBox(height: 18),
              _primaryBtn(
                waitingOnGroup ? 'Waiting for Other Vehicle' : 'Proceed to Verification',
                (_loading || waitingOnGroup) ? null : _proceed,
              ),
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
          'Arrived at Drop-off',
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
              'Step 4 of 6',
              style: GoogleFonts.inter(
                color: context.textTertiary,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
            const Spacer(),
            Text(
              '4 / 6',
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
            value: 4 / 6,
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

  Widget _actionContext(BuildContext context, TaskModel task, bool waitingOnGroup) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Customer Verification',
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 16,
            fontWeight: FontWeight.w800,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          waitingOnGroup
              ? 'Vehicle ${task.groupPosition} of ${task.groupVehicleCount} — waiting for the other vehicle in this group to reach drop-off before payment can be collected.'
              : 'Continue to payment and customer signature.',
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
