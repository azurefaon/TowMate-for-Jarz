import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/team_leader_service.dart';
import '../../widgets/tl_task_detail_card.dart';

/// "Arrived" step — merges the former arrived_pickup / in_progress /
/// loading_vehicle statuses into a single screen with one action button.
/// Shown for any of those three raw statuses so a partially-completed
/// chain (see [_proceed]/[_back]) always resumes on the same screen.
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
    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            TlTaskDetailCard(task: widget.task),
            const SizedBox(height: 24),
            Text('Arrived at Pickup',
                style: GoogleFonts.inter(
                    color: TmColors.black, fontSize: 15, letterSpacing: -0.2)),
            const SizedBox(height: 4),
            Text('Confirm to load the vehicle and start towing.',
                style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 12)),
            const SizedBox(height: 24),
            _primaryBtn('Start Towing', Icons.local_shipping_rounded,
                _loading ? null : _proceed),
            const SizedBox(height: 12),
            _outlineBtn(
                'Back', Icons.arrow_back_rounded, _loading ? null : _back),
          ],
        ),
      ),
    );
  }

  Widget _primaryBtn(String label, IconData icon, VoidCallback? onTap) {
    return SizedBox(
      width: double.infinity,
      height: 52,
      child: ElevatedButton(
        onPressed: onTap,
        style: ElevatedButton.styleFrom(
          backgroundColor: TmColors.yellow,
          foregroundColor: TmColors.black,
          disabledBackgroundColor: TmColors.yellow.withValues(alpha: 0.4),
          shape: const StadiumBorder(),
          elevation: 0,
        ),
        child: _loading
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(
                    color: TmColors.black, strokeWidth: 2))
            : Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                Icon(icon, color: TmColors.black, size: 20),
                const SizedBox(width: 8),
                Text(label,
                    style: GoogleFonts.inter(
                        color: TmColors.black, fontSize: 15)),
              ]),
      ),
    );
  }

  Widget _outlineBtn(String label, IconData icon, VoidCallback? onTap) {
    return SizedBox(
      width: double.infinity,
      height: 48,
      child: OutlinedButton(
        onPressed: onTap,
        style: OutlinedButton.styleFrom(
          side: const BorderSide(color: TmColors.grey300),
          shape: const StadiumBorder(),
        ),
        child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
          Icon(icon, color: TmColors.grey700, size: 18),
          const SizedBox(width: 8),
          Text(label,
              style: GoogleFonts.inter(
                  color: TmColors.grey700, fontSize: 14)),
        ]),
      ),
    );
  }
}
