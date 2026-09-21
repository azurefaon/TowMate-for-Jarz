import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/team_leader_service.dart';

class TlArrivedPickupScreen extends StatefulWidget {
  const TlArrivedPickupScreen(
      {super.key, required this.task, required this.onUpdate});
  final TaskModel task;
  final void Function(TaskModel) onUpdate;

  @override
  State<TlArrivedPickupScreen> createState() => _TlArrivedPickupScreenState();
}

class _TlArrivedPickupScreenState extends State<TlArrivedPickupScreen> {
  static const List<String> _forwardOrder = [
    'arrived_pickup',
    'in_progress',
    'loading_vehicle',
    'on_job',
  ];
  static const List<String> _backOrder = [
    'on_the_way',
    'arrived_pickup',
    'in_progress',
    'loading_vehicle',
  ];

  bool _loading = false;

  Future<void> _proceed() async {
    setState(() => _loading = true);
    var status = widget.task.status;
    var idx = _forwardOrder.indexOf(status);
    if (idx < 0) idx = 0;
    for (var i = idx + 1; i < _forwardOrder.length; i++) {
      final target = _forwardOrder[i];
      final res =
          await TeamLeaderService.updateStatus(widget.task.bookingCode, target);
      if (!mounted) return;
      if (res['success'] != true) {
        setState(() => _loading = false);
        _showError(res['message'] as String? ?? 'Failed.');
        if (status != widget.task.status) {
          widget.onUpdate(widget.task.copyWith(status: status));
        }
        return;
      }
      status = target;
    }
    widget.onUpdate(widget.task.copyWith(status: status));
  }

  Future<void> _back() async {
    setState(() => _loading = true);
    var status = widget.task.status;
    var idx = _backOrder.indexOf(status);
    if (idx < 0) idx = _backOrder.length - 1;
    for (var i = idx - 1; i >= 0; i--) {
      final target = _backOrder[i];
      final res =
          await TeamLeaderService.updateStatus(widget.task.bookingCode, target);
      if (!mounted) return;
      if (res['success'] != true) {
        setState(() => _loading = false);
        _showError(res['message'] as String? ?? 'Failed.');
        if (status != widget.task.status) {
          widget.onUpdate(widget.task.copyWith(status: status));
        }
        return;
      }
      status = target;
    }
    widget.onUpdate(widget.task.copyWith(status: status));
  }

  void _showError(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(msg), backgroundColor: TmColors.error));
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
              _primaryBtn('Start Towing', _loading ? null : _proceed),
              const SizedBox(height: 12),
              _secondaryBtn(context, 'Back', _loading ? null : _back),
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
          'Arrived at Pickup',
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
              'Step 2 of 6',
              style: GoogleFonts.inter(
                color: context.textTertiary,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
            const Spacer(),
            Text(
              '2 / 6',
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
            value: 2 / 6,
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
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 15),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _label(context, 'PICKUP'),
                const SizedBox(height: 6),
                Text(
                  task.pickupAddress,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 17,
                    fontWeight: FontWeight.w700,
                    height: 1.3,
                  ),
                ),
              ],
            ),
          ),
          Divider(height: 1, thickness: 1, color: context.divider),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 15),
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
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 15),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _label(context, 'SERVICE TYPE'),
                const SizedBox(height: 6),
                Text(
                  task.truckTypeName,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
          Divider(height: 1, thickness: 1, color: context.divider),
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
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    height: 1.3,
                  ),
                ),
              ],
            ),
          ),
          if (hasNote) ...[
            Divider(height: 1, thickness: 1, color: context.divider),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 15),
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

  Widget _actionContext(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Ready to Tow',
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 16,
            fontWeight: FontWeight.w800,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          'Confirm that the vehicle is secured before starting the trip.',
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
