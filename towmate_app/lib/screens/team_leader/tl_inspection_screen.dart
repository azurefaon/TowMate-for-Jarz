import 'dart:io';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:image_picker/image_picker.dart';
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
    'Tow equipment attached': false,
  };

  static const _damageOptions = [
    'Body / Exterior',
    'Tire / Wheel',
    'Glass',
    'Mechanical',
    'Interior',
    'Other',
  ];

  final Set<String> _damageCategories = {};
  final List<XFile> _damagePhotos = [];
  bool _uploadingPhotos = false;
  bool _loading = false;

  bool get _canProceed =>
      _checks['Vehicle condition noted']! &&
      _checks['Tow equipment attached']! &&
      (_damageCategories.isEmpty || _damagePhotos.isNotEmpty) &&
      !_uploadingPhotos;

  Future<void> _proceed() async {
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

  Future<void> _return() async {
    final ok = await Navigator.push<bool>(
      context,
      MaterialPageRoute(builder: (_) => TlReturnScreen(task: widget.task)),
    );
    if (ok == true && mounted) {
      widget.onUpdate(widget.task.copyWith(status: 'returned'));
    }
  }

  void _showPhotoSource() {
    showModalBottomSheet(
      context: context,
      backgroundColor: TmColors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (_) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 8),
            ListTile(
              title: Text('Take Photo',
                  style:
                      GoogleFonts.inter(color: TmColors.black, fontSize: 14)),
              onTap: () {
                Navigator.pop(context);
                _pickPhoto(ImageSource.camera);
              },
            ),
            ListTile(
              title: Text('Choose from Gallery',
                  style:
                      GoogleFonts.inter(color: TmColors.black, fontSize: 14)),
              onTap: () {
                Navigator.pop(context);
                _pickPhoto(ImageSource.gallery);
              },
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }

  Future<void> _pickPhoto(ImageSource source) async {
    final file =
        await ImagePicker().pickImage(source: source, imageQuality: 85);
    if (file == null || !mounted) return;
    setState(() {
      _damagePhotos.add(file);
      _uploadingPhotos = true;
    });
    final res = await TeamLeaderService.uploadPhoto(
        widget.task.bookingCode, file, 'inspection_damage');
    if (!mounted) return;
    setState(() {
      _uploadingPhotos = false;
      if (res['success'] != true) {
        _damagePhotos.removeLast();
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content:
              Text(res['message'] as String? ?? 'Upload failed.'),
          backgroundColor: TmColors.error,
        ));
      }
    });
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
            Text('Complete required items before loading the vehicle.',
                style:
                    GoogleFonts.inter(color: TmColors.grey500, fontSize: 12)),
            const SizedBox(height: 16),
            ..._checks.keys.map((k) => TlChecklistItem(
                  label: k,
                  checked: _checks[k]!,
                  onTap: () => setState(() => _checks[k] = !_checks[k]!),
                )),
            const SizedBox(height: 24),
            _damageSection(),
            const SizedBox(height: 24),
            _primaryBtn(
              'Proceed to Loading',
              Icons.arrow_forward_rounded,
              _canProceed && !_loading ? _proceed : null,
            ),
            const SizedBox(height: 12),
            _outlineBtn('Return Task', Icons.undo_rounded, _return),
          ],
        ),
      ),
    );
  }

  Widget _damageSection() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Text('Damage / Condition',
                style:
                    GoogleFonts.inter(color: TmColors.black, fontSize: 13)),
            const SizedBox(width: 6),
            Text('(select all that apply)',
                style: GoogleFonts.inter(
                    color: TmColors.grey500, fontSize: 11)),
          ],
        ),
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: _damageOptions.map((opt) {
            final selected = _damageCategories.contains(opt);
            return GestureDetector(
              onTap: () => setState(() {
                if (selected) {
                  _damageCategories.remove(opt);
                } else {
                  _damageCategories.add(opt);
                }
              }),
              child: Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                decoration: BoxDecoration(
                  color: selected ? TmColors.black : TmColors.white,
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(
                    color:
                        selected ? TmColors.black : TmColors.grey300,
                    width: selected ? 1.5 : 1,
                  ),
                ),
                child: Text(
                  opt,
                  style: GoogleFonts.inter(
                    color:
                        selected ? TmColors.white : TmColors.grey700,
                    fontSize: 12,
                  ),
                ),
              ),
            );
          }).toList(),
        ),
        if (_damageCategories.isEmpty) ...[
          const SizedBox(height: 10),
          Text('No damage noted — photos not required.',
              style: GoogleFonts.inter(
                  color: TmColors.grey500, fontSize: 11)),
        ] else ...[
          const SizedBox(height: 16),
          Row(
            children: [
              Text('Damage Photos',
                  style: GoogleFonts.inter(
                      color: TmColors.black, fontSize: 13)),
              const SizedBox(width: 6),
              Text('(at least 1 required)',
                  style: GoogleFonts.inter(
                      color: TmColors.error, fontSize: 11)),
            ],
          ),
          const SizedBox(height: 10),
          _photoGrid(),
        ],
      ],
    );
  }

  Widget _photoGrid() {
    final canAdd = _damagePhotos.length < 6;
    return GridView.count(
      crossAxisCount: 3,
      crossAxisSpacing: 8,
      mainAxisSpacing: 8,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      children: [
        ..._damagePhotos.asMap().entries.map((entry) {
          final i = entry.key;
          final f = entry.value;
          return Stack(
            fit: StackFit.expand,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: kIsWeb
                    ? Image.network(f.path, fit: BoxFit.cover)
                    : Image.file(File(f.path), fit: BoxFit.cover),
              ),
              Positioned(
                top: 4,
                right: 4,
                child: GestureDetector(
                  onTap: _uploadingPhotos
                      ? null
                      : () => setState(() => _damagePhotos.removeAt(i)),
                  child: Container(
                    width: 22,
                    height: 22,
                    decoration: const BoxDecoration(
                      color: Colors.black54,
                      shape: BoxShape.circle,
                    ),
                    child: const Icon(Icons.close,
                        color: Colors.white, size: 14),
                  ),
                ),
              ),
            ],
          );
        }),
        if (canAdd)
          GestureDetector(
            onTap: _uploadingPhotos ? null : _showPhotoSource,
            child: Container(
              decoration: BoxDecoration(
                color: TmColors.grey100,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: TmColors.grey300),
              ),
              child: _uploadingPhotos
                  ? const Center(
                      child: SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                            color: TmColors.yellow, strokeWidth: 2),
                      ),
                    )
                  : Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const Icon(Icons.add_a_photo_outlined,
                            color: TmColors.grey500, size: 22),
                        const SizedBox(height: 4),
                        Text('Add',
                            style: GoogleFonts.inter(
                                color: TmColors.grey500, fontSize: 11)),
                      ],
                    ),
            ),
          ),
      ],
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
              style:
                  GoogleFonts.inter(color: TmColors.grey700, fontSize: 13),
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
