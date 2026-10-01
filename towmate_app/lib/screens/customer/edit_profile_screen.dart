import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:image_picker/image_picker.dart';
import '../../core/theme.dart';
import '../../core/validators.dart';
import '../../services/api_service.dart';

class EditProfileScreen extends StatefulWidget {
  const EditProfileScreen({super.key});

  @override
  State<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends State<EditProfileScreen> {
  final _firstCtrl = TextEditingController();
  final _middleCtrl = TextEditingController();
  final _lastCtrl = TextEditingController();
  String? _firstName;
  String? _middleName;
  String? _lastName;
  String? _email;
  Uint8List? _profileImage;
  bool _loading = true;
  bool _uploadingPhoto = false;
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _firstCtrl.dispose();
    _middleCtrl.dispose();
    _lastCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    await ApiService.fetchAndCacheProfile();
    final firstName = await ApiService.getUserFirstName();
    final middleName = await ApiService.getUserMiddleName();
    final lastName = await ApiService.getUserLastName();
    final email = await ApiService.getUserEmail();
    final profileImage = await ApiService.fetchProfileImage();
    if (!mounted) return;
    setState(() {
      _firstCtrl.text = firstName ?? '';
      _middleCtrl.text = middleName ?? '';
      _lastCtrl.text = lastName ?? '';
      _firstName = firstName;
      _middleName = middleName;
      _lastName = lastName;
      _email = email;
      _profileImage = profileImage;
      _loading = false;
    });
  }

  String get _initials {
    final first = _firstCtrl.text.trim();
    final last = _lastCtrl.text.trim();
    if (first.isEmpty && last.isEmpty) return '?';
    if (first.isNotEmpty && last.isNotEmpty) {
      return '${first[0]}${last[0]}'.toUpperCase();
    }
    return (first.isNotEmpty ? first : last)[0].toUpperCase();
  }

  Future<void> _changePhoto() async {
    if (_uploadingPhoto) return;
    final picked = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      imageQuality: 85,
      maxWidth: 1600,
      maxHeight: 1600,
    );
    if (picked == null || !mounted) return;

    setState(() => _uploadingPhoto = true);
    final result = await ApiService.updateProfileImage(picked);
    if (!mounted) return;
    if (result['success'] == true) {
      final uploadedImage = await ApiService.fetchProfileImage();
      if (!mounted) return;
      setState(() {
        if (uploadedImage != null) _profileImage = uploadedImage;
        _uploadingPhoto = false;
      });
      ScaffoldMessenger.of(context).showSnackBar(_snack('Photo updated.'));
    } else {
      setState(() => _uploadingPhoto = false);
      ScaffoldMessenger.of(context).showSnackBar(
        _snack(result['message'] as String? ?? 'Could not update photo.'),
      );
    }
  }

  Future<void> _save() async {
    if (_saving) return;
    final firstName = _firstCtrl.text.trim();
    final middleName = _middleCtrl.text.trim();
    final lastName = _lastCtrl.text.trim();
    final nameError =
        Validators.name(firstName, 'First name') ??
        Validators.name(lastName, 'Last name');
    if (nameError != null) {
      setState(() => _error = nameError);
      return;
    }
    if (firstName == _firstName &&
        middleName == (_middleName ?? '') &&
        lastName == _lastName) {
      Navigator.pop(context);
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });
    final res = await ApiService.updateProfile(
      firstName: firstName,
      middleName: middleName,
      lastName: lastName,
    );
    if (!mounted) return;
    if (res['success'] == true) {
      setState(() {
        _firstName = firstName;
        _middleName = middleName.isEmpty ? null : middleName;
        _lastName = lastName;
        _saving = false;
      });
      ScaffoldMessenger.of(context).showSnackBar(_snack('Profile updated.'));
      Navigator.pop(context);
    } else {
      setState(() {
        _saving = false;
        _error = res['message'] as String? ?? 'Failed to update profile.';
      });
    }
  }

  Widget _nameField(String label, TextEditingController controller) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: GoogleFonts.inter(
            color: context.textSecondary,
            fontSize: 12,
            letterSpacing: 0.2,
          ),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: controller,
          textCapitalization: TextCapitalization.words,
          onChanged: (_) => setState(() {}),
          style: GoogleFonts.inter(color: context.textPrimary, fontSize: 15),
          decoration: InputDecoration(
            enabledBorder: UnderlineInputBorder(
              borderSide: BorderSide(color: context.divider),
            ),
            focusedBorder: const UnderlineInputBorder(
              borderSide: BorderSide(color: TmColors.yellow, width: 1.5),
            ),
          ),
        ),
      ],
    );
  }

  SnackBar _snack(String msg) => SnackBar(

    content: Text(
      msg,
      style: GoogleFonts.inter(color: TmColors.black, fontSize: 14),
    ),
    backgroundColor: TmColors.yellow,
    behavior: SnackBarBehavior.floating,
    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
    margin: const EdgeInsets.all(16),
  );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      body: SafeArea(
        child: Column(
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
              decoration: BoxDecoration(
                border: Border(
                  bottom: BorderSide(color: context.divider, width: 0.5),
                ),
              ),
              child: Row(
                children: [
                  IconButton(
                    icon: Icon(
                      Icons.arrow_back_rounded,
                      color: context.textTertiary,
                    ),
                    onPressed: () => Navigator.pop(context),
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Edit Profile',
                      style: GoogleFonts.inter(
                        color: context.textPrimary,
                        fontSize: 17,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -0.2,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            Expanded(
              child: _loading
                  ? const Center(
                      child: CircularProgressIndicator(
                        color: TmColors.yellow,
                      ),
                    )
                  : SingleChildScrollView(
                      padding: const EdgeInsets.fromLTRB(24, 32, 24, 24),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.center,
                        children: [
                          Stack(
                            clipBehavior: Clip.none,
                            children: [
                              CircleAvatar(
                                radius: 48,
                                backgroundColor: TmColors.yellow,
                                backgroundImage: _profileImage == null
                                    ? null
                                    : MemoryImage(_profileImage!),
                                child: _profileImage == null
                                    ? Text(
                                        _initials,
                                        style: GoogleFonts.inter(
                                          color: TmColors.black,
                                          fontSize: 28,
                                          fontWeight: FontWeight.w500,
                                        ),
                                      )
                                    : null,
                              ),
                              Positioned(
                                right: -2,
                                bottom: -2,
                                child: Tooltip(
                                  message: 'Change photo',
                                  child: InkWell(
                                    onTap: _uploadingPhoto
                                        ? null
                                        : _changePhoto,
                                    borderRadius: BorderRadius.circular(19),
                                    child: Container(
                                      width: 38,
                                      height: 38,
                                      decoration: BoxDecoration(
                                        color: context.card,
                                        shape: BoxShape.circle,
                                        border: Border.all(
                                          color: context.divider,
                                        ),
                                      ),
                                      child: _uploadingPhoto
                                          ? const Padding(
                                              padding: EdgeInsets.all(10),
                                              child:
                                                  CircularProgressIndicator(
                                                    color: TmColors.yellow,
                                                    strokeWidth: 2,
                                                  ),
                                            )
                                          : Icon(
                                              Icons.camera_alt_outlined,
                                              color: context.textPrimary,
                                              size: 18,
                                            ),
                                    ),
                                  ),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 32),
                          _nameField('First Name', _firstCtrl),
                          const SizedBox(height: 24),
                          _nameField('Middle Name (optional)', _middleCtrl),
                          const SizedBox(height: 24),
                          _nameField('Last Name', _lastCtrl),
                          const SizedBox(height: 24),
                          Align(
                            alignment: Alignment.centerLeft,
                            child: Text(
                              'Email',
                              style: GoogleFonts.inter(
                                color: context.textSecondary,
                                fontSize: 12,
                                letterSpacing: 0.2,
                              ),
                            ),
                          ),
                          const SizedBox(height: 8),
                          Align(
                            alignment: Alignment.centerLeft,
                            child: Text(
                              _email ?? '—',
                              style: GoogleFonts.inter(
                                color: context.textTertiary,
                                fontSize: 15,
                              ),
                            ),
                          ),
                          if (_error != null) ...[
                            const SizedBox(height: 16),
                            Container(
                              width: double.infinity,
                              padding: const EdgeInsets.all(10),
                              decoration: BoxDecoration(
                                color: TmColors.error.withValues(alpha: 0.08),
                                borderRadius: BorderRadius.circular(12),
                                border: const Border(
                                  left: BorderSide(
                                    color: TmColors.error,
                                    width: 3,
                                  ),
                                ),
                              ),
                              child: Text(
                                _error!,
                                style: GoogleFonts.inter(
                                  color: TmColors.error,
                                  fontSize: 13,
                                ),
                              ),
                            ),
                          ],
                          const SizedBox(height: 32),
                          SizedBox(
                            width: double.infinity,
                            height: 56,
                            child: ElevatedButton(
                              onPressed: _saving ? null : _save,
                              style: ElevatedButton.styleFrom(
                                backgroundColor: TmColors.yellow,
                                foregroundColor: TmColors.black,
                                disabledBackgroundColor:
                                    TmColors.yellow.withValues(alpha: 0.6),
                                shape: const StadiumBorder(),
                                elevation: 0,
                              ),
                              child: _saving
                                  ? const SizedBox(
                                      width: 20,
                                      height: 20,
                                      child: CircularProgressIndicator(
                                        color: TmColors.black,
                                        strokeWidth: 2,
                                      ),
                                    )
                                  : Text(
                                      'Save Changes',
                                      style: GoogleFonts.inter(
                                        color: TmColors.black,
                                        fontSize: 16,
                                        letterSpacing: 0.2,
                                      ),
                                    ),
                            ),
                          ),
                        ],
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}
