import 'dart:io';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';

import 'package:google_fonts/google_fonts.dart';
import 'package:image_picker/image_picker.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/team_leader_service.dart';
import '../../widgets/tl_task_detail_card.dart';
import '../../widgets/tl_checklist_item.dart';
import 'tl_return_screen.dart';

class TlArrivedPickupScreen extends StatefulWidget {
  const TlArrivedPickupScreen(
      {super.key, required this.task, required this.onUpdate});
  final TaskModel task;
  final void Function(TaskModel) onUpdate;

  @override
  State<TlArrivedPickupScreen> createState() => _TlArrivedPickupScreenState();
}

class _TlArrivedPickupScreenState extends State<TlArrivedPickupScreen> {
  bool _photoTaken = false;
  bool _customerPresent = false;
  bool _vehicleAccessible = false;
  bool _loading = false;
  XFile? _photo;

  Future<void> _showPhotoSource() async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      backgroundColor: TmColors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(12)),
      ),
      builder: (_) => Padding(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 36),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 32, height: 4,
              decoration: BoxDecoration(
                color: TmColors.grey300,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            const SizedBox(height: 8),
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text('Take Photo',
                  style: GoogleFonts.inter(color: TmColors.grey700, fontSize: 15)),
              onTap: () => Navigator.pop(context, ImageSource.camera),
            ),
            Container(height: 0.5, color: TmColors.grey300),
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text('Choose from Gallery',
                  style: GoogleFonts.inter(color: TmColors.grey700, fontSize: 15)),
              onTap: () => Navigator.pop(context, ImageSource.gallery),
            ),
          ],
        ),
      ),
    );
    if (source != null) await _pickPhoto(source);
  }

  Future<void> _pickPhoto(ImageSource source) async {
    final xfile = await ImagePicker().pickImage(source: source, imageQuality: 75);
    if (xfile == null || !mounted) return;

    setState(() => _loading = true);
    final res = await TeamLeaderService.uploadPhoto(widget.task.bookingCode, xfile, 'arrival');
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (res['success'] == true) {
        _photo = xfile;
        _photoTaken = true;
      }
    });
    if (res['success'] != true) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(res['message'] as String? ?? 'Photo upload failed.'),
          backgroundColor: TmColors.error));
    }
  }

  bool get _canProceed =>
      _photoTaken && _customerPresent && _vehicleAccessible;

  Future<void> _proceed() async {
    setState(() => _loading = true);
    final res =
        await TeamLeaderService.updateStatus(widget.task.bookingCode, 'in_progress');
    if (!mounted) return;
    if (res['success'] == true) {
      widget.onUpdate(widget.task.copyWith(status: 'in_progress'));
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
            TlTaskDetailCard(task: widget.task),
            const SizedBox(height: 24),
            Text('Arrival Checklist',
                style: GoogleFonts.inter(
                    color: TmColors.black, fontSize: 15, letterSpacing: -0.2)),
            const SizedBox(height: 12),
            TlChecklistItem(
              label: 'Take arrival photo',
              sublabel: _photoTaken ? 'Photo uploaded' : 'Tap to add photo',
              checked: _photoTaken,
              onTap: _loading ? null : _showPhotoSource,
            ),
            TlChecklistItem(
              label: 'Customer is present',
              checked: _customerPresent,
              onTap: () =>
                  setState(() => _customerPresent = !_customerPresent),
            ),
            TlChecklistItem(
              label: 'Vehicle is accessible',
              checked: _vehicleAccessible,
              onTap: () =>
                  setState(() => _vehicleAccessible = !_vehicleAccessible),
            ),
            if (_photo != null) ...[
              const SizedBox(height: 12),
              ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: kIsWeb
                    ? Image.network(_photo!.path,
                        height: 160, width: double.infinity, fit: BoxFit.cover)
                    : Image.file(File(_photo!.path),
                        height: 160, width: double.infinity, fit: BoxFit.cover),
              ),
            ],
            const SizedBox(height: 24),
            _primaryBtn('Begin Inspection', Icons.search_rounded,
                _canProceed && !_loading ? _proceed : null),
            const SizedBox(height: 12),
            _outlineBtn('Return Task', Icons.undo_rounded, _return),
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
