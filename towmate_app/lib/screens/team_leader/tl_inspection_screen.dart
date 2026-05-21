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
  // 'good' | 'damage' | null (not yet chosen)
  String? _conditionChoice;

  bool _equipmentAttached = false;

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
  final TextEditingController _otherNoteCtrl = TextEditingController();
  bool _uploadingPhotos = false;
  bool _loading = false;

  @override
  void dispose() {
    _otherNoteCtrl.dispose();
    super.dispose();
  }

  bool get _canProceed {
    if (_conditionChoice == null) return false;
    if (!_equipmentAttached) return false;
    if (_uploadingPhotos) return false;
    if (_conditionChoice == 'damage') {
      if (_damageCategories.isEmpty) return false;
      if (_damagePhotos.isEmpty) return false;
      if (_damageCategories.contains('Other') &&
          _otherNoteCtrl.text.trim().isEmpty) return false;
    }
    return true;
  }

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

  void _selectCondition(String choice) {
    setState(() {
      _conditionChoice = choice;
      // Switching to good condition clears any damage selections
      if (choice == 'good') {
        _damageCategories.clear();
        _damagePhotos.clear();
        _otherNoteCtrl.clear();
      }
    });
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
          content: Text(res['message'] as String? ?? 'Upload failed.'),
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
            const SizedBox(height: 20),

            // ── Vehicle condition (mutually exclusive) ─────────────────
            Text('Vehicle Condition',
                style: GoogleFonts.inter(
                    color: TmColors.black,
                    fontSize: 13,
                    fontWeight: FontWeight.w600)),
            const SizedBox(height: 10),
            _conditionCard(
              label: 'Good Condition',
              sublabel: 'No visible damage — ready to tow.',
              value: 'good',
              icon: Icons.check_circle_outline_rounded,
              activeColor: TmColors.yellow,
            ),
            _conditionCard(
              label: 'Damage Noted',
              sublabel: 'Vehicle has existing damage to document.',
              value: 'damage',
              icon: Icons.warning_amber_rounded,
              activeColor: TmColors.error,
            ),

            const SizedBox(height: 16),

            // ── Equipment attached ─────────────────────────────────────
            TlChecklistItem(
              label: 'Tow equipment attached',
              checked: _equipmentAttached,
              onTap: () =>
                  setState(() => _equipmentAttached = !_equipmentAttached),
            ),

            // ── Damage section (only when damage selected) ─────────────
            if (_conditionChoice == 'damage') ...[
              const SizedBox(height: 8),
              _damageSection(),
            ],

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

  Widget _conditionCard({
    required String label,
    required String sublabel,
    required String value,
    required IconData icon,
    required Color activeColor,
  }) {
    final selected = _conditionChoice == value;
    return GestureDetector(
      onTap: () => _selectCondition(value),
      child: Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: selected
              ? activeColor.withValues(alpha: 0.08)
              : TmColors.grey100,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: selected ? activeColor : TmColors.grey300,
            width: selected ? 1.5 : 1,
          ),
        ),
        child: Row(
          children: [
            Icon(
              selected ? Icons.check_circle_rounded : Icons.radio_button_unchecked_rounded,
              color: selected ? activeColor : TmColors.grey500,
              size: 22,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label,
                      style: GoogleFonts.inter(
                          color: TmColors.black,
                          fontSize: 14,
                          fontWeight: FontWeight.w500)),
                  const SizedBox(height: 2),
                  Text(sublabel,
                      style: GoogleFonts.inter(
                          color: TmColors.grey500, fontSize: 11)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _damageSection() {
    final hasOther = _damageCategories.contains('Other');
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
                  if (opt == 'Other') _otherNoteCtrl.clear();
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
                    color: selected ? TmColors.black : TmColors.grey300,
                    width: selected ? 1.5 : 1,
                  ),
                ),
                child: Text(
                  opt,
                  style: GoogleFonts.inter(
                    color: selected ? TmColors.white : TmColors.grey700,
                    fontSize: 12,
                  ),
                ),
              ),
            );
          }).toList(),
        ),

        // Other notes field
        if (hasOther) ...[
          const SizedBox(height: 12),
          Text('Describe the damage',
              style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 13,
                  fontWeight: FontWeight.w500)),
          const SizedBox(height: 6),
          TextField(
            controller: _otherNoteCtrl,
            maxLines: 3,
            maxLength: 300,
            onChanged: (_) => setState(() {}),
            decoration: InputDecoration(
              hintText: 'e.g. cracked windshield, bent bumper…',
              hintStyle: GoogleFonts.inter(color: TmColors.grey500, fontSize: 13),
              filled: true,
              fillColor: TmColors.grey100,
              contentPadding:
                  const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: TmColors.grey300),
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: TmColors.grey300),
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide:
                    const BorderSide(color: TmColors.black, width: 1.5),
              ),
            ),
            style: GoogleFonts.inter(color: TmColors.black, fontSize: 13),
          ),
        ],

        // Photo requirement
        if (_damageCategories.isNotEmpty) ...[
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
        ] else ...[
          const SizedBox(height: 10),
          Text('Select at least one damage type above.',
              style: GoogleFonts.inter(
                  color: TmColors.grey500, fontSize: 11)),
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
        for (var i = 0; i < _damagePhotos.length; i++) ...[
          Stack(
            fit: StackFit.expand,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: kIsWeb
                    ? Image.network(_damagePhotos[i].path, fit: BoxFit.cover)
                    : Image.file(File(_damagePhotos[i].path),
                        fit: BoxFit.cover),
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
          ),
        ],
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
