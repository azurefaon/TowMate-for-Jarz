import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/team_leader_service.dart';
import '../../widgets/tl_checklist_item.dart';
import 'tl_return_screen.dart';

class TlLoadingScreen extends StatefulWidget {
  const TlLoadingScreen(
      {super.key, required this.task, required this.onUpdate});
  final TaskModel task;
  final void Function(TaskModel) onUpdate;

  @override
  State<TlLoadingScreen> createState() => _TlLoadingScreenState();
}

class _TlLoadingScreenState extends State<TlLoadingScreen> {
  final Map<String, bool> _checks = {
    'Vehicle secured on flatbed': false,
    'Safety straps / chains fastened': false,
    'Lights and hazards verified': false,
    'Ready to transport': false,
  };
  bool _loading = false;

  bool get _allChecked => _checks.values.every((v) => v);

  Future<void> _proceed() async {
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

  Future<void> _return() async {
    final ok = await Navigator.push<bool>(
      context,
      MaterialPageRoute(builder: (_) => TlReturnScreen(task: widget.task)),
    );
    if (ok == true && mounted) {
      widget.onUpdate(widget.task.copyWith(status: 'returned'));
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _infoCard(),
            const SizedBox(height: 24),
            Text('Vehicle Loading',
                style: GoogleFonts.inter(
                    color: TmColors.black, fontSize: 15, letterSpacing: -0.2)),
            const SizedBox(height: 4),
            Text('Confirm all loading steps are complete.',
                style: GoogleFonts.inter(
                    color: TmColors.grey500, fontSize: 12)),
            const SizedBox(height: 16),
            ..._checks.keys.map((k) => TlChecklistItem(
                  label: k,
                  checked: _checks[k]!,
                  onTap: () => setState(() => _checks[k] = !_checks[k]!),
                )),
            const SizedBox(height: 24),
            _primaryBtn(
              'Start Transport',
              Icons.local_shipping_rounded,
              _allChecked && !_loading ? _proceed : null,
            ),
            const SizedBox(height: 12),
            _outlineBtn('Return Task', Icons.undo_rounded, _return),
          ],
        ),
      ),
    );
  }

  Widget _infoCard() {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: TmColors.grey100,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          const Icon(Icons.local_shipping_outlined,
              color: TmColors.grey500, size: 18),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              '${widget.task.bookingCode}  ·  Drop-off: ${widget.task.dropoffAddress}',
              style: GoogleFonts.inter(color: TmColors.grey700, fontSize: 13),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
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
              style: GoogleFonts.inter(
                  color: TmColors.grey700, fontSize: 14)),
        ]),
      ),
    );
  }
}
