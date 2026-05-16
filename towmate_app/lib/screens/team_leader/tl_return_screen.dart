import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/team_leader_service.dart';

class TlReturnScreen extends StatefulWidget {
  const TlReturnScreen({super.key, required this.task});
  final TaskModel task;

  @override
  State<TlReturnScreen> createState() => _TlReturnScreenState();
}

class _TlReturnScreenState extends State<TlReturnScreen> {
  static const _reasons = [
    'Customer not available',
    'Vehicle not accessible',
    'Wrong vehicle information',
    'Customer cancelled',
    'Safety concern at location',
    'Truck breakdown',
    'Other',
  ];

  String? _selectedReason;
  final _notesCtrl = TextEditingController();
  bool _loading = false;
  String? _error;

  @override
  void dispose() {
    _notesCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_selectedReason == null) {
      setState(() => _error = 'Please select a reason.');
      return;
    }
    setState(() { _loading = true; _error = null; });

    final res = await TeamLeaderService.returnTask(
      widget.task.bookingCode,
      _selectedReason!,
      _notesCtrl.text.trim().isEmpty ? null : _notesCtrl.text.trim(),
    );

    if (!mounted) return;
    if (res['success'] == true) {
      Navigator.pop(context, true);
    } else {
      setState(() {
        _loading = false;
        _error = res['message'] as String? ?? 'Return failed.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: TmColors.white,
      appBar: AppBar(
        backgroundColor: TmColors.white,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.close_rounded, color: TmColors.black),
          onPressed: () => Navigator.pop(context, false),
        ),
        title: Text('Return Task',
            style: GoogleFonts.inter(
                color: TmColors.black, fontSize: 16, letterSpacing: -0.2)),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: TmColors.error.withValues(alpha: 0.06),
                borderRadius: BorderRadius.circular(12),
                border: const Border(
                    left: BorderSide(color: TmColors.error, width: 3)),
              ),
              child: Text(
                'Returning a task will mark it as unresolved and notify the dispatcher. This cannot be undone.',
                style: GoogleFonts.inter(
                    color: TmColors.error, fontSize: 13),
              ),
            ),
            const SizedBox(height: 24),
            Text('Reason for Return',
                style: GoogleFonts.inter(
                    color: TmColors.black, fontSize: 14)),
            const SizedBox(height: 12),
            ..._reasons.map((r) => _reasonTile(r)),
            const SizedBox(height: 20),
            Text('Additional Notes (optional)',
                style: GoogleFonts.inter(
                    color: TmColors.black, fontSize: 14)),
            const SizedBox(height: 8),
            TextFormField(
              controller: _notesCtrl,
              maxLines: 4,
              style: GoogleFonts.inter(
                  color: TmColors.black, fontSize: 14),
              decoration: InputDecoration(
                hintText: 'Describe the situation...',
                hintStyle: GoogleFonts.inter(
                    color: TmColors.grey500, fontSize: 14),
                filled: true,
                fillColor: TmColors.grey100,
                border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide.none),
                enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide.none),
                focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide:
                        const BorderSide(color: TmColors.yellow, width: 1.5)),
                contentPadding: const EdgeInsets.all(14),
              ),
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!,
                  style: GoogleFonts.inter(
                      color: TmColors.error, fontSize: 13)),
            ],
            const SizedBox(height: 28),
            SizedBox(
              width: double.infinity,
              height: 52,
              child: ElevatedButton(
                onPressed: _loading ? null : _submit,
                style: ElevatedButton.styleFrom(
                  backgroundColor: TmColors.error,
                  foregroundColor: TmColors.white,
                  disabledBackgroundColor:
                      TmColors.error.withValues(alpha: 0.5),
                  shape: const StadiumBorder(),
                  elevation: 0,
                ),
                child: _loading
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                            color: TmColors.white, strokeWidth: 2))
                    : Text('Confirm Return',
                        style: GoogleFonts.inter(
                            color: TmColors.white, fontSize: 15)),
              ),
            ),
            const SizedBox(height: 40),
          ],
        ),
      ),
    );
  }

  Widget _reasonTile(String reason) {
    final selected = _selectedReason == reason;
    return GestureDetector(
      onTap: () => setState(() => _selectedReason = reason),
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: selected
              ? TmColors.black.withValues(alpha: 0.05)
              : TmColors.grey100,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(
              color: selected ? TmColors.black : TmColors.grey300),
        ),
        child: Row(
          children: [
            Icon(
              selected
                  ? Icons.radio_button_checked_rounded
                  : Icons.radio_button_unchecked_rounded,
              color: selected ? TmColors.black : TmColors.grey500,
              size: 20,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(reason,
                  style: GoogleFonts.inter(
                      color: TmColors.black, fontSize: 14)),
            ),
          ],
        ),
      ),
    );
  }
}
