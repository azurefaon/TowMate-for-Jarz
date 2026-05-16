import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/team_leader_service.dart';
import '../../widgets/tl_checklist_item.dart';
import 'tl_return_screen.dart';

class TlInspectionScreen extends StatefulWidget {
  const TlInspectionScreen(
      {super.key, required this.task, required this.onUpdate});
  final TaskModel task;
  final void Function(TaskModel) onUpdate;

  @override
  State<TlInspectionScreen> createState() => _TlInspectionScreenState();
}

class _TlInspectionScreenState extends State<TlInspectionScreen> {
  final Map<String, bool> _checks = {
    'Vehicle condition noted': false,
    'Damage documented (if any)': false,
    'Vehicle keys secured / neutral set': false,
    'Tow equipment attached': false,
  };
  bool _loading = false;

  bool get _allChecked => _checks.values.every((v) => v);

  Future<void> _proceed() async {
    setState(() => _loading = true);
    final res =
        await TeamLeaderService.updateStatus(widget.task.bookingCode, 'loading_vehicle');
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
            Text('Pre-Tow Inspection',
                style: GoogleFonts.inter(
                    color: TmColors.black, fontSize: 15, letterSpacing: -0.2)),
            const SizedBox(height: 4),
            Text('Complete all items before loading the vehicle.',
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
              'Proceed to Loading',
              Icons.arrow_forward_rounded,
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
          const Icon(Icons.info_outline_rounded,
              color: TmColors.grey500, size: 18),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              'Booking: ${widget.task.bookingCode}  ·  ${widget.task.customerName}',
              style: GoogleFonts.inter(
                  color: TmColors.grey700, fontSize: 13),
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
