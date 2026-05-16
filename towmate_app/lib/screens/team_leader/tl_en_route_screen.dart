import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/team_leader_service.dart';
import '../../widgets/tl_task_detail_card.dart';
import 'tl_return_screen.dart';

class TlEnRouteScreen extends StatefulWidget {
  const TlEnRouteScreen(
      {super.key, required this.task, required this.onUpdate});
  final TaskModel task;
  final void Function(TaskModel) onUpdate;

  @override
  State<TlEnRouteScreen> createState() => _TlEnRouteScreenState();
}

class _TlEnRouteScreenState extends State<TlEnRouteScreen> {
  bool _loading = false;

  Future<void> _arrive() async {
    setState(() => _loading = true);
    final res = await TeamLeaderService.updateStatus(
        widget.task.bookingCode, 'arrived_pickup');
    if (!mounted) return;
    if (res['success'] == true) {
      widget.onUpdate(widget.task.copyWith(status: 'arrived_pickup'));
    } else {
      setState(() => _loading = false);
      _showError(res['message'] as String? ?? 'Failed.');
    }
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
            _banner(),
            const SizedBox(height: 20),
            TlTaskDetailCard(task: widget.task),
            const SizedBox(height: 24),
            _primaryBtn('Arrived at Pickup', Icons.location_on_rounded, _arrive),
            const SizedBox(height: 12),
            _outlineBtn('Return Task', Icons.undo_rounded, _return),
          ],
        ),
      ),
    );
  }

  Widget _banner() {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: TmColors.black,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        children: [
          const Icon(Icons.navigation_rounded,
              color: TmColors.yellow, size: 22),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('En Route to Pickup',
                    style: GoogleFonts.inter(
                        color: TmColors.white, fontSize: 14)),
                Text('GPS tracking is active',
                    style: GoogleFonts.inter(
                        color: TmColors.grey500, fontSize: 12)),
              ],
            ),
          ),
          Container(
            width: 8,
            height: 8,
            decoration: const BoxDecoration(
                color: TmColors.success, shape: BoxShape.circle),
          ),
        ],
      ),
    );
  }

  Widget _primaryBtn(String label, IconData icon, VoidCallback onTap) {
    return SizedBox(
      width: double.infinity,
      height: 52,
      child: ElevatedButton(
        onPressed: _loading ? null : onTap,
        style: ElevatedButton.styleFrom(
          backgroundColor: TmColors.yellow,
          foregroundColor: TmColors.black,
          disabledBackgroundColor: TmColors.yellow.withValues(alpha: 0.6),
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

  Widget _outlineBtn(String label, IconData icon, VoidCallback onTap) {
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
              style: GoogleFonts.inter(color: TmColors.grey700, fontSize: 14)),
        ]),
      ),
    );
  }
}
