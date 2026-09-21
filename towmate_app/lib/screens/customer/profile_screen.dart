import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:image_picker/image_picker.dart';
import '../../core/app_prefs.dart';
import '../../core/theme.dart';
import '../../core/validators.dart';
import '../../services/api_service.dart';
import '../../widgets/skeleton_box.dart';
import '../../widgets/tm_bottom_nav.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  String? _name;
  String? _firstName;
  String? _lastName;
  String? _email;
  String? _phone;
  String? _authProvider;
  Uint8List? _profileImage;
  bool _loading = true;
  bool _uploadingPhoto = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    await ApiService.fetchAndCacheProfile();
    final name = await ApiService.getUserName();
    final firstName = await ApiService.getUserFirstName();
    final lastName = await ApiService.getUserLastName();
    final email = await ApiService.getUserEmail();
    final phone = await ApiService.getUserPhone();
    final authProvider = await ApiService.getUserAuthProvider();
    final profileImage = await ApiService.fetchProfileImage();
    if (!mounted) return;
    setState(() {
      _name = name;
      _firstName = firstName;
      _lastName = lastName;
      _email = email;
      _phone = phone;
      _authProvider = authProvider;
      _profileImage = profileImage;
      _loading = false;
    });
  }

  bool get _isGoogleAccount => _authProvider == 'google';

  String get _initials {
    final n = (_name ?? '').trim();
    if (n.isEmpty) return '?';
    final parts = n.split(' ').where((p) => p.isNotEmpty).toList();
    if (parts.length >= 2) return '${parts[0][0]}${parts[1][0]}'.toUpperCase();
    return n[0].toUpperCase();
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

    final pickedBytes = await picked.readAsBytes();
    if (!mounted) return;
    setState(() => _uploadingPhoto = true);
    final result = await ApiService.updateProfileImage(picked);
    if (!mounted) return;
    if (result['success'] == true) {
      setState(() {
        _profileImage = pickedBytes;
        _uploadingPhoto = false;
      });
      final uploadedImage = await ApiService.fetchProfileImage();
      if (!mounted) return;
      if (uploadedImage != null) {
        setState(() => _profileImage = uploadedImage);
      }
      ScaffoldMessenger.of(context).showSnackBar(_snack('Photo updated.'));
    } else {
      setState(() => _uploadingPhoto = false);
      ScaffoldMessenger.of(context).showSnackBar(
        _snack(result['message'] as String? ?? 'Could not update photo.'),
      );
    }
  }

  Future<void> _logout() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: ctx.card,
        title: Text(
          'Log out?',
          style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 17),
        ),
        content: Text(
          'You will need to sign in again to access your account.',
          style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 14),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text(
              'Cancel',
              style: GoogleFonts.inter(color: ctx.textTertiary),
            ),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(
              'Log out',
              style: GoogleFonts.inter(color: TmColors.error),
            ),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    await ApiService.clearSession();
    AppPrefs.useGuestTheme();
    if (!mounted) return;
    Navigator.of(context).pushNamedAndRemoveUntil('/public-home', (_) => false);
  }

  Future<void> _editName() async {
    final firstCtrl = TextEditingController(text: _firstName);
    final lastCtrl = TextEditingController(text: _lastName);
    final result = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: ctx.card,
        title: Text(
          'Edit Name',
          style: GoogleFonts.inter(
            color: ctx.textPrimary,
            fontSize: 16,
            letterSpacing: -0.2,
          ),
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: firstCtrl,
              autofocus: true,
              style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 15),
              decoration: InputDecoration(
                hintText: 'First name',
                hintStyle: GoogleFonts.inter(
                  color: ctx.textSecondary,
                  fontSize: 15,
                ),
                enabledBorder: UnderlineInputBorder(
                  borderSide: BorderSide(color: ctx.divider),
                ),
                focusedBorder: const UnderlineInputBorder(
                  borderSide: BorderSide(color: TmColors.yellow, width: 1.5),
                ),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: lastCtrl,
              style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 15),
              decoration: InputDecoration(
                hintText: 'Last name',
                hintStyle: GoogleFonts.inter(
                  color: ctx.textSecondary,
                  fontSize: 15,
                ),
                enabledBorder: UnderlineInputBorder(
                  borderSide: BorderSide(color: ctx.divider),
                ),
                focusedBorder: const UnderlineInputBorder(
                  borderSide: BorderSide(color: TmColors.yellow, width: 1.5),
                ),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text(
              'Cancel',
              style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 14),
            ),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(
              'Save',
              style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14),
            ),
          ),
        ],
      ),
    );

    if (result != true) return;
    final first = firstCtrl.text.trim();
    final last = lastCtrl.text.trim();
    if (first.isEmpty || last.isEmpty) return;
    if (first == _firstName && last == _lastName) return;

    final res = await ApiService.updateProfile(
      firstName: first,
      lastName: last,
      phone: _phone,
    );
    if (!mounted) return;
    if (res['success'] == true) {
      setState(() {
        _firstName = first;
        _lastName = last;
        _name = '$first $last';
      });
      ScaffoldMessenger.of(context).showSnackBar(_snack('Name updated.'));
    } else {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(_snack(res['message'] ?? 'Failed to update name.'));
    }
  }

  Future<void> _editPhone() async {
    final controller = TextEditingController(text: _phone);
    final result = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: ctx.card,
        title: Text(
          'Edit Phone',
          style: GoogleFonts.inter(
            color: ctx.textPrimary,
            fontSize: 16,
            letterSpacing: -0.2,
          ),
        ),
        content: TextField(
          controller: controller,
          autofocus: true,
          keyboardType: TextInputType.phone,
          style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 15),
          decoration: InputDecoration(
            hintText: 'Your phone number',
            hintStyle: GoogleFonts.inter(
              color: ctx.textSecondary,
              fontSize: 15,
            ),
            enabledBorder: UnderlineInputBorder(
              borderSide: BorderSide(color: ctx.divider),
            ),
            focusedBorder: const UnderlineInputBorder(
              borderSide: BorderSide(color: TmColors.yellow, width: 1.5),
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text(
              'Cancel',
              style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 14),
            ),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, controller.text.trim()),
            child: Text(
              'Save',
              style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14),
            ),
          ),
        ],
      ),
    );

    if (result == null || result.isEmpty || result == _phone) return;
    final res = await ApiService.updateProfile(
      firstName: _firstName ?? '',
      lastName: _lastName ?? '',
      phone: result,
    );
    if (!mounted) return;
    if (res['success'] == true) {
      setState(() => _phone = result);
      ScaffoldMessenger.of(context).showSnackBar(_snack('Phone updated.'));
    } else {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(_snack(res['message'] ?? 'Failed to update phone.'));
    }
  }

  Future<void> _editEmail() async {
    final emailCtrl = TextEditingController();
    final otpCtrl = TextEditingController();
    bool otpSent = false;
    bool sending = false;
    String? error;

    final newEmail = await showDialog<String>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setS) => AlertDialog(
          backgroundColor: ctx.card,
          title: Text(
            'Change Email',
            style: GoogleFonts.inter(
              color: ctx.textPrimary,
              fontSize: 16,
              letterSpacing: -0.2,
            ),
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (error != null) ...[
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: TmColors.error.withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(
                      error!,
                      style: GoogleFonts.inter(
                        color: TmColors.error,
                        fontSize: 12,
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                ],
                TextField(
                  controller: emailCtrl,
                  enabled: !otpSent,
                  keyboardType: TextInputType.emailAddress,
                  style: GoogleFonts.inter(
                    color: ctx.textPrimary,
                    fontSize: 15,
                  ),
                  decoration: InputDecoration(
                    labelText: 'New email',
                    labelStyle: GoogleFonts.inter(
                      color: ctx.textSecondary,
                      fontSize: 13,
                    ),
                    enabledBorder: UnderlineInputBorder(
                      borderSide: BorderSide(color: ctx.divider),
                    ),
                    focusedBorder: const UnderlineInputBorder(
                      borderSide: BorderSide(
                        color: TmColors.yellow,
                        width: 1.5,
                      ),
                    ),
                  ),
                ),
                if (otpSent) ...[
                  const SizedBox(height: 12),
                  TextField(
                    controller: otpCtrl,
                    autofocus: true,
                    keyboardType: TextInputType.number,
                    maxLength: 6,
                    style: GoogleFonts.inter(
                      color: ctx.textPrimary,
                      fontSize: 15,
                    ),
                    decoration: InputDecoration(
                      labelText: '6-digit code',
                      labelStyle: GoogleFonts.inter(
                        color: ctx.textSecondary,
                        fontSize: 13,
                      ),
                      enabledBorder: UnderlineInputBorder(
                        borderSide: BorderSide(color: ctx.divider),
                      ),
                      focusedBorder: const UnderlineInputBorder(
                        borderSide: BorderSide(
                          color: TmColors.yellow,
                          width: 1.5,
                        ),
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: sending ? null : () => Navigator.pop(ctx),
              child: Text(
                'Cancel',
                style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 14),
              ),
            ),
            TextButton(
              onPressed: sending
                  ? null
                  : () async {
                      if (!otpSent) {
                        final email = emailCtrl.text.trim();
                        if (email.isEmpty || !email.contains('@')) {
                          setS(() => error = 'Enter a valid email address.');
                          return;
                        }
                        setS(() {
                          sending = true;
                          error = null;
                        });
                        final res = await ApiService.requestEmailChangeOtp(
                          email,
                        );
                        setS(() {
                          sending = false;
                          if (res['success'] == true) {
                            otpSent = true;
                          } else {
                            error = res['message'] ?? 'Failed to send code.';
                          }
                        });
                      } else {
                        final otp = otpCtrl.text.trim();
                        if (otp.length != 6) {
                          setS(() => error = 'Enter the 6-digit code.');
                          return;
                        }
                        setS(() {
                          sending = true;
                          error = null;
                        });
                        final res = await ApiService.confirmEmailChange(
                          emailCtrl.text.trim(),
                          otp,
                        );
                        if (!ctx.mounted) return;
                        if (res['success'] == true) {
                          Navigator.pop(ctx, emailCtrl.text.trim());
                        } else {
                          setS(() {
                            sending = false;
                            error =
                                res['message'] ?? 'Invalid or expired code.';
                          });
                        }
                      }
                    },
              child: Text(
                sending ? 'Please wait…' : (otpSent ? 'Confirm' : 'Send Code'),
                style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14),
              ),
            ),
          ],
        ),
      ),
    );

    if (newEmail == null || !mounted) return;
    setState(() => _email = newEmail);
    ScaffoldMessenger.of(context).showSnackBar(_snack('Email updated.'));
  }

  Future<void> _changePassword() async {
    final currentCtrl = TextEditingController();
    final newCtrl = TextEditingController();
    final confirmCtrl = TextEditingController();
    bool saving = false;
    String? error;

    await showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setS) => AlertDialog(
          backgroundColor: ctx.card,
          title: Text(
            'Change Password',
            style: GoogleFonts.inter(
              color: ctx.textPrimary,
              fontSize: 16,
              letterSpacing: -0.2,
            ),
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (error != null) ...[
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: TmColors.error.withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(
                      error!,
                      style: GoogleFonts.inter(
                        color: TmColors.error,
                        fontSize: 12,
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                ],
                _PwField(label: 'Current password', controller: currentCtrl),
                const SizedBox(height: 12),
                _PwField(label: 'New password', controller: newCtrl),
                const SizedBox(height: 12),
                _PwField(
                  label: 'Confirm new password',
                  controller: confirmCtrl,
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: saving ? null : () => Navigator.pop(ctx),
              child: Text(
                'Cancel',
                style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 14),
              ),
            ),
            TextButton(
              onPressed: saving
                  ? null
                  : () async {
                      final cur = currentCtrl.text;
                      final nw = newCtrl.text;
                      final conf = confirmCtrl.text;
                      if (cur.isEmpty || nw.isEmpty || conf.isEmpty) {
                        setS(() => error = 'All fields are required.');
                        return;
                      }
                      final passwordError = Validators.password(nw);
                      if (passwordError != null) {
                        setS(() => error = passwordError);
                        return;
                      }
                      if (nw != conf) {
                        setS(() => error = 'New passwords do not match.');
                        return;
                      }
                      setS(() {
                        saving = true;
                        error = null;
                      });
                      final res = await ApiService.changePassword(
                        currentPassword: cur,
                        newPassword: nw,
                      );
                      if (!ctx.mounted) return;
                      if (res['success'] == true) {
                        Navigator.pop(ctx);
                        if (mounted) {
                          ScaffoldMessenger.of(
                            context,
                          ).showSnackBar(_snack('Password changed.'));
                        }
                      } else {
                        setS(() {
                          saving = false;
                          error =
                              res['message'] ?? 'Failed to change password.';
                        });
                      }
                    },
              child: Text(
                saving ? 'Saving…' : 'Save',
                style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14),
              ),
            ),
          ],
        ),
      ),
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
      bottomNavigationBar: const TmBottomNav(currentRoute: '/profile'),
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
                    child: Center(
                      child: RichText(
                        text: TextSpan(
                          style: GoogleFonts.inter(
                            fontSize: 22,
                            letterSpacing: -0.8,
                            fontWeight: FontWeight.w600,
                          ),
                          children: [
                            TextSpan(
                              text: 'Tow',
                              style: TextStyle(color: context.textPrimary),
                            ),
                            const TextSpan(
                              text: 'Mate',
                              style: TextStyle(color: TmColors.yellow),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 40),
                ],
              ),
            ),

            Expanded(
              child: _loading
                  ? const _ProfileSkeleton()
                  : SingleChildScrollView(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Padding(
                            padding: const EdgeInsets.fromLTRB(24, 28, 24, 24),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.center,
                              children: [
                                Stack(
                                  clipBehavior: Clip.none,
                                  children: [
                                    CircleAvatar(
                                      radius: 42,
                                      backgroundColor: TmColors.yellow,
                                      backgroundImage: _profileImage == null
                                          ? null
                                          : MemoryImage(_profileImage!),
                                      child: _profileImage == null
                                          ? Text(
                                              _initials,
                                              style: GoogleFonts.inter(
                                                color: TmColors.black,
                                                fontSize: 26,
                                                fontWeight: FontWeight.w500,
                                              ),
                                            )
                                          : null,
                                    ),
                                    Positioned(
                                      right: -2,
                                      bottom: -2,
                                      child: InkWell(
                                        onTap: _uploadingPhoto
                                            ? null
                                            : _changePhoto,
                                        borderRadius: BorderRadius.circular(18),
                                        child: Container(
                                          width: 34,
                                          height: 34,
                                          decoration: BoxDecoration(
                                            color: context.card,
                                            shape: BoxShape.circle,
                                            border: Border.all(
                                              color: context.divider,
                                            ),
                                          ),
                                          child: _uploadingPhoto
                                              ? const Padding(
                                                  padding: EdgeInsets.all(9),
                                                  child:
                                                      CircularProgressIndicator(
                                                        color: TmColors.yellow,
                                                        strokeWidth: 2,
                                                      ),
                                                )
                                              : Icon(
                                                  Icons.camera_alt_outlined,
                                                  color: context.textPrimary,
                                                  size: 17,
                                                ),
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                                const SizedBox(width: 20),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        _name ?? '—',
                                        maxLines: 2,
                                        overflow: TextOverflow.ellipsis,
                                        style: GoogleFonts.inter(
                                          color: context.textPrimary,
                                          fontSize: 21,
                                          fontWeight: FontWeight.w500,
                                          letterSpacing: -0.5,
                                        ),
                                      ),
                                      if (_email != null &&
                                          _email!.isNotEmpty) ...[
                                        const SizedBox(height: 5),
                                        Text(
                                          _email!,
                                          maxLines: 2,
                                          overflow: TextOverflow.ellipsis,
                                          style: GoogleFonts.inter(
                                            color: context.textSecondary,
                                            fontSize: 13,
                                          ),
                                        ),
                                      ],
                                      if (_phone != null &&
                                          _phone!.isNotEmpty) ...[
                                        const SizedBox(height: 3),
                                        Text(
                                          _phone!,
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                          style: GoogleFonts.inter(
                                            color: context.textSecondary,
                                            fontSize: 13,
                                          ),
                                        ),
                                      ],
                                      const SizedBox(height: 8),
                                      InkWell(
                                        onTap: _uploadingPhoto
                                            ? null
                                            : _changePhoto,
                                        child: Text(
                                          _uploadingPhoto
                                              ? 'Uploading…'
                                              : 'Change photo',
                                          style: GoogleFonts.inter(
                                            color: TmColors.yellow,
                                            fontSize: 13,
                                            fontWeight: FontWeight.w500,
                                          ),
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              ],
                            ),
                          ),

                          Padding(
                            padding: const EdgeInsets.fromLTRB(24, 32, 24, 0),
                            child: Text(
                              'ACCOUNT SETTINGS',
                              style: GoogleFonts.inter(
                                color: context.textSecondary,
                                fontSize: 11,
                                letterSpacing: 0.8,
                              ),
                            ),
                          ),
                          const SizedBox(height: 12),
                          _SettingsRow(
                            label: 'Name',
                            value: _name ?? '—',
                            onTap: _editName,
                          ),
                          _SettingsRow(
                            label: 'Email',
                            value: _email ?? '—',
                            onTap: _isGoogleAccount ? null : _editEmail,
                            subtitle: _isGoogleAccount
                                ? 'Managed by Google'
                                : null,
                          ),
                          _SettingsRow(
                            label: 'Phone',
                            value: _phone ?? '—',
                            onTap: _editPhone,
                          ),
                          _isGoogleAccount
                              ? const _SettingsRow(
                                  label: 'Password',
                                  value: 'Signed in with Google',
                                )
                              : _SettingsRow(
                                  label: 'Password',
                                  value: '••••••••',
                                  onTap: _changePassword,
                                ),

                          Padding(
                            padding: const EdgeInsets.fromLTRB(24, 32, 24, 0),
                            child: Text(
                              'APPEARANCE',
                              style: GoogleFonts.inter(
                                color: context.textSecondary,
                                fontSize: 11,
                                letterSpacing: 0.8,
                              ),
                            ),
                          ),
                          const SizedBox(height: 12),
                          ValueListenableBuilder<ThemeMode>(
                            valueListenable: AppPrefs.themeModeNotifier,
                            builder: (ctx, mode, _) {
                              final dark = mode == ThemeMode.dark;
                              return Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 24,
                                  vertical: 16,
                                ),
                                decoration: BoxDecoration(
                                  border: Border(
                                    bottom: BorderSide(
                                      color: ctx.divider,
                                      width: 0.5,
                                    ),
                                  ),
                                ),
                                child: Row(
                                  children: [
                                    SizedBox(
                                      width: 88,
                                      child: Text(
                                        'Dark Mode',
                                        style: GoogleFonts.inter(
                                          color: ctx.textSecondary,
                                          fontSize: 13,
                                          letterSpacing: 0.1,
                                        ),
                                      ),
                                    ),
                                    Expanded(
                                      child: Text(
                                        dark ? 'On' : 'Off',
                                        style: GoogleFonts.inter(
                                          color: ctx.textPrimary,
                                          fontSize: 14,
                                          letterSpacing: 0.1,
                                        ),
                                      ),
                                    ),
                                    Switch(
                                      value: dark,
                                      onChanged: (val) async {
                                        AppPrefs.themeModeNotifier.value = val
                                            ? ThemeMode.dark
                                            : ThemeMode.light;
                                        await AppPrefs.setDarkMode(val);
                                      },
                                      activeThumbColor: TmColors.yellow,
                                    ),
                                  ],
                                ),
                              );
                            },
                          ),

                          const SizedBox(height: 40),
                          Padding(
                            padding: const EdgeInsets.fromLTRB(24, 0, 24, 28),
                            child: InkWell(
                              onTap: _logout,
                              child: Row(
                                children: [
                                  const Icon(
                                    Icons.logout_rounded,
                                    color: TmColors.error,
                                    size: 20,
                                  ),
                                  const SizedBox(width: 12),
                                  Text(
                                    'Log out',
                                    style: GoogleFonts.inter(
                                      color: TmColors.error,
                                      fontSize: 14,
                                      fontWeight: FontWeight.w500,
                                    ),
                                  ),
                                ],
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

class _SettingsRow extends StatelessWidget {
  const _SettingsRow({
    required this.label,
    required this.value,
    this.onTap,
    this.subtitle,
  });
  final String label;
  final String value;
  final VoidCallback? onTap;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
        decoration: BoxDecoration(
          border: Border(
            bottom: BorderSide(color: context.divider, width: 0.5),
          ),
        ),
        child: Row(
          children: [
            SizedBox(
              width: 88,
              child: Text(
                label,
                style: GoogleFonts.inter(
                  color: context.textSecondary,
                  fontSize: 13,
                  letterSpacing: 0.1,
                ),
              ),
            ),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    value,
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 14,
                      letterSpacing: 0.1,
                    ),
                  ),
                  if (subtitle != null) ...[
                    const SizedBox(height: 2),
                    Text(
                      subtitle!,
                      style: GoogleFonts.inter(
                        color: context.textSecondary,
                        fontSize: 11.5,
                        letterSpacing: 0.1,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            if (onTap != null)
              Icon(
                Icons.chevron_right_rounded,
                color: context.textSecondary,
                size: 20,
              ),
          ],
        ),
      ),
    );
  }
}

class _ProfileSkeleton extends StatelessWidget {
  const _ProfileSkeleton();

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      physics: const NeverScrollableScrollPhysics(),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 28, 24, 24),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                const SkeletonBox(
                  width: 84,
                  height: 84,
                  borderRadius: BorderRadius.all(Radius.circular(42)),
                ),
                const SizedBox(width: 20),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const SkeletonBox(width: 140, height: 21),
                      const SizedBox(height: 8),
                      const SkeletonBox(width: 170, height: 13),
                      const SizedBox(height: 6),
                      const SkeletonBox(width: 110, height: 13),
                      const SizedBox(height: 11),
                      const SkeletonBox(width: 84, height: 13),
                    ],
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 32, 24, 0),
            child: const SkeletonBox(width: 120, height: 11),
          ),
          const SizedBox(height: 12),
          const _SettingsRowSkeleton(),
          const _SettingsRowSkeleton(),
          const _SettingsRowSkeleton(),
          const _SettingsRowSkeleton(),
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 32, 24, 0),
            child: const SkeletonBox(width: 90, height: 11),
          ),
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
            decoration: BoxDecoration(
              border: Border(
                bottom: BorderSide(color: context.divider, width: 0.5),
              ),
            ),
            child: Row(
              children: [
                const SizedBox(
                  width: 88,
                  child: SkeletonBox(width: 66, height: 13),
                ),
                const Expanded(child: SkeletonBox(width: 28, height: 14)),
                SkeletonBox(
                  width: 40,
                  height: 22,
                  borderRadius: BorderRadius.circular(11),
                ),
              ],
            ),
          ),
          const SizedBox(height: 40),
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 28),
            child: Row(
              children: [
                SkeletonBox(
                  width: 20,
                  height: 20,
                  borderRadius: BorderRadius.circular(4),
                ),
                const SizedBox(width: 12),
                const SkeletonBox(width: 70, height: 14),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _SettingsRowSkeleton extends StatelessWidget {
  const _SettingsRowSkeleton();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: context.divider, width: 0.5)),
      ),
      child: Row(
        children: [
          const SizedBox(width: 88, child: SkeletonBox(width: 56, height: 13)),
          const Expanded(child: SkeletonBox(width: 100, height: 14)),
        ],
      ),
    );
  }
}

class _PwField extends StatefulWidget {
  const _PwField({required this.label, required this.controller});
  final String label;
  final TextEditingController controller;

  @override
  State<_PwField> createState() => _PwFieldState();
}

class _PwFieldState extends State<_PwField> {
  bool _obscure = true;

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: widget.controller,
      obscureText: _obscure,
      style: GoogleFonts.inter(color: context.textPrimary, fontSize: 14),
      decoration: InputDecoration(
        labelText: widget.label,
        labelStyle: GoogleFonts.inter(
          color: context.textSecondary,
          fontSize: 13,
        ),
        enabledBorder: UnderlineInputBorder(
          borderSide: BorderSide(color: context.divider),
        ),
        focusedBorder: const UnderlineInputBorder(
          borderSide: BorderSide(color: TmColors.yellow, width: 1.5),
        ),
        suffixIcon: GestureDetector(
          onTap: () => setState(() => _obscure = !_obscure),
          child: Icon(
            _obscure ? Icons.visibility_off : Icons.visibility,
            color: context.textSecondary,
            size: 18,
          ),
        ),
      ),
    );
  }
}
