import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/app_prefs.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';
import '../../services/team_leader_service.dart';
import '../../services/tl_presence_controller.dart';
import '../../widgets/skeleton_box.dart';
import '../../widgets/tl_bottom_nav.dart';

class TlProfileScreen extends StatefulWidget {
  const TlProfileScreen({super.key});

  @override
  State<TlProfileScreen> createState() => _TlProfileScreenState();
}

class _TlProfileScreenState extends State<TlProfileScreen> {
  String? _name;
  String? _firstName;
  String? _lastName;
  String? _email;
  String? _phone;
  bool _loading = true;

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
    if (!mounted) return;
    setState(() {
      _name = name;
      _firstName = firstName;
      _lastName = lastName;
      _email = email;
      _phone = phone;
      _loading = false;
    });
  }

  String get _initials {
    final n = (_name ?? '').trim();
    if (n.isEmpty) return '?';
    final parts = n.split(' ').where((p) => p.isNotEmpty).toList();
    if (parts.length >= 2) return '${parts[0][0]}${parts[1][0]}'.toUpperCase();
    return n[0].toUpperCase();
  }

  void _snack(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg, style: GoogleFonts.inter(color: TmColors.black, fontSize: 14)),
        backgroundColor: TmColors.yellow,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        margin: const EdgeInsets.all(16),
      ),
    );
  }

  Future<void> _editName() async {
    final firstCtrl = TextEditingController(text: _firstName);
    final lastCtrl = TextEditingController(text: _lastName);
    final result = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: ctx.card,
        title: Text('Edit Name', style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 16)),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: firstCtrl,
              autofocus: true,
              style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 15),
              decoration: InputDecoration(
                hintText: 'First name',
                hintStyle: GoogleFonts.inter(color: ctx.textSecondary, fontSize: 15),
                enabledBorder: UnderlineInputBorder(borderSide: BorderSide(color: ctx.divider)),
                focusedBorder: const UnderlineInputBorder(borderSide: BorderSide(color: TmColors.yellow, width: 1.5)),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: lastCtrl,
              style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 15),
              decoration: InputDecoration(
                hintText: 'Last name',
                hintStyle: GoogleFonts.inter(color: ctx.textSecondary, fontSize: 15),
                enabledBorder: UnderlineInputBorder(borderSide: BorderSide(color: ctx.divider)),
                focusedBorder: const UnderlineInputBorder(borderSide: BorderSide(color: TmColors.yellow, width: 1.5)),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text('Cancel', style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 14)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text('Save', style: GoogleFonts.inter(color: ctx.textPrimary, fontWeight: FontWeight.w600, fontSize: 14)),
          ),
        ],
      ),
    );

    if (result != true) return;
    final first = firstCtrl.text.trim();
    final last = lastCtrl.text.trim();
    if (first.isEmpty || last.isEmpty) return;
    if (first == _firstName && last == _lastName) return;

    final res = await ApiService.updateProfile(firstName: first, lastName: last, phone: _phone);
    if (!mounted) return;
    if (res['success'] == true) {
      setState(() {
        _firstName = first;
        _lastName = last;
        _name = '$first $last';
      });
      _snack('Name updated.');
    } else {
      _snack(res['message'] as String? ?? 'Failed to update name.');
    }
  }

  Future<void> _editPhone() async {
    final controller = TextEditingController(text: _phone);
    final result = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: ctx.card,
        title: Text('Edit Phone', style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 16)),
        content: TextField(
          controller: controller,
          autofocus: true,
          keyboardType: TextInputType.phone,
          style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 15),
          decoration: InputDecoration(
            enabledBorder: UnderlineInputBorder(borderSide: BorderSide(color: ctx.divider)),
            focusedBorder: const UnderlineInputBorder(borderSide: BorderSide(color: TmColors.yellow, width: 1.5)),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text('Cancel', style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 14)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, controller.text.trim()),
            child: Text('Save', style: GoogleFonts.inter(color: ctx.textPrimary, fontWeight: FontWeight.w600, fontSize: 14)),
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
      _snack('Phone updated.');
    } else {
      _snack(res['message'] as String? ?? 'Failed to update phone.');
    }
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
          title: Text('Change Password', style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 16)),
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
                    child: Text(error!, style: GoogleFonts.inter(color: TmColors.error, fontSize: 12)),
                  ),
                  const SizedBox(height: 12),
                ],
                TextField(
                  controller: currentCtrl,
                  obscureText: true,
                  style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14),
                  decoration: InputDecoration(
                    labelText: 'Current password',
                    labelStyle: GoogleFonts.inter(color: ctx.textSecondary, fontSize: 13),
                    enabledBorder: UnderlineInputBorder(borderSide: BorderSide(color: ctx.divider)),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: newCtrl,
                  obscureText: true,
                  style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14),
                  decoration: InputDecoration(
                    labelText: 'New password',
                    labelStyle: GoogleFonts.inter(color: ctx.textSecondary, fontSize: 13),
                    enabledBorder: UnderlineInputBorder(borderSide: BorderSide(color: ctx.divider)),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: confirmCtrl,
                  obscureText: true,
                  style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14),
                  decoration: InputDecoration(
                    labelText: 'Confirm new password',
                    labelStyle: GoogleFonts.inter(color: ctx.textSecondary, fontSize: 13),
                    enabledBorder: UnderlineInputBorder(borderSide: BorderSide(color: ctx.divider)),
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: saving ? null : () => Navigator.pop(ctx),
              child: Text('Cancel', style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 14)),
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
                      if (nw.length < 8) {
                        setS(() => error = 'New password must be at least 8 characters.');
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
                      final res = await TeamLeaderService.changePassword(
                        currentPassword: cur,
                        newPassword: nw,
                        confirmPassword: conf,
                      );
                      if (!ctx.mounted) return;
                      if (res['success'] == true) {
                        Navigator.pop(ctx);
                        if (mounted) _snack('Password changed.');
                      } else {
                        setS(() {
                          saving = false;
                          error = res['message'] as String? ?? 'Failed to change password.';
                        });
                      }
                    },
              child: Text(
                saving ? 'Saving…' : 'Save',
                style: GoogleFonts.inter(color: ctx.textPrimary, fontWeight: FontWeight.w600, fontSize: 14),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _logout() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: ctx.card,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        title: Text('Log out?', style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 17, letterSpacing: -0.3)),
        content: Text(
          'You will need to sign in again to access your account.',
          style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 14, height: 1.5),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text('Cancel', style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 14)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text('Log out', style: GoogleFonts.inter(color: TmColors.error, fontSize: 14)),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    await TeamLeaderService.goOffline();
    TlPresenceController.stop();
    await ApiService.clearSession();
    AppPrefs.useGuestTheme();
    if (!mounted) return;
    Navigator.of(context).pushNamedAndRemoveUntil('/login', (_) => false);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      bottomNavigationBar: const TlBottomNav(currentRoute: '/tl-profile'),
      body: SafeArea(
        child: Column(
          children: [
            _header(context),
            Expanded(
              child: _loading
                  ? const _ProfileSkeleton()
                  : SingleChildScrollView(
                      padding: const EdgeInsets.only(bottom: 28),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Padding(
                            padding: const EdgeInsets.fromLTRB(24, 28, 24, 0),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.center,
                              children: [
                                CircleAvatar(
                                  radius: 34,
                                  backgroundColor: TmColors.yellow,
                                  child: Text(
                                    _initials,
                                    style: GoogleFonts.inter(
                                      color: TmColors.black,
                                      fontSize: 22,
                                      fontWeight: FontWeight.w500,
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 16),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        _name ?? '—',
                                        maxLines: 2,
                                        overflow: TextOverflow.ellipsis,
                                        style: GoogleFonts.inter(
                                          color: context.textPrimary,
                                          fontSize: 20,
                                          fontWeight: FontWeight.w600,
                                          letterSpacing: -0.4,
                                        ),
                                      ),
                                      const SizedBox(height: 3),
                                      Text(
                                        'Team Leader',
                                        style: GoogleFonts.inter(
                                          color: context.textTertiary,
                                          fontSize: 13,
                                          fontWeight: FontWeight.w500,
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
                            child: _sectionLabel(context, 'Account Information'),
                          ),
                          const SizedBox(height: 12),
                          _row(context, 'Name', _name ?? '—', _editName),
                          _row(context, 'Email', _email ?? '—', null),
                          _row(context, 'Phone', _phone ?? '—', _editPhone),
                          _row(context, 'Password', '••••••••', _changePassword),

                          Padding(
                            padding: const EdgeInsets.fromLTRB(24, 32, 24, 0),
                            child: _sectionLabel(context, 'Appearance'),
                          ),
                          const SizedBox(height: 12),
                          ValueListenableBuilder<ThemeMode>(
                            valueListenable: AppPrefs.themeModeNotifier,
                            builder: (ctx, mode, _) {
                              final dark = mode == ThemeMode.dark;
                              return Container(
                                padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
                                decoration: BoxDecoration(
                                  border: Border(bottom: BorderSide(color: ctx.divider, width: 0.5)),
                                ),
                                child: Row(
                                  children: [
                                    SizedBox(
                                      width: 100,
                                      child: Text(
                                        'Dark Mode',
                                        style: GoogleFonts.inter(color: ctx.textSecondary, fontSize: 13),
                                      ),
                                    ),
                                    Expanded(
                                      child: Text(
                                        dark ? 'On' : 'Off',
                                        style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14),
                                      ),
                                    ),
                                    Switch(
                                      value: dark,
                                      onChanged: (val) async {
                                        AppPrefs.themeModeNotifier.value = val ? ThemeMode.dark : ThemeMode.light;
                                        await AppPrefs.setDarkMode(val);
                                      },
                                      activeThumbColor: TmColors.yellow,
                                    ),
                                  ],
                                ),
                              );
                            },
                          ),

                          const SizedBox(height: 36),
                          Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 24),
                            child: InkWell(
                              onTap: _logout,
                              child: Row(
                                children: [
                                  const Icon(Icons.logout_rounded, color: TmColors.error, size: 20),
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

  Widget _header(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: context.divider, width: 0.5)),
      ),
      child: Center(
        child: RichText(
          text: TextSpan(
            style: GoogleFonts.inter(
              fontSize: 20,
              fontWeight: FontWeight.w700,
              letterSpacing: -0.5,
            ),
            children: [
              TextSpan(text: 'Tow', style: TextStyle(color: context.textPrimary)),
              const TextSpan(text: 'Mate', style: TextStyle(color: TmColors.yellow)),
            ],
          ),
        ),
      ),
    );
  }

  Widget _sectionLabel(BuildContext context, String text) {
    return Text(
      text,
      style: GoogleFonts.inter(
        color: context.textTertiary,
        fontSize: 12.5,
        fontWeight: FontWeight.w600,
        letterSpacing: 0.1,
      ),
    );
  }

  Widget _row(BuildContext context, String label, String value, VoidCallback? onTap) {
    return InkWell(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
        decoration: BoxDecoration(
          border: Border(bottom: BorderSide(color: context.divider, width: 0.5)),
        ),
        child: Row(
          children: [
            SizedBox(
              width: 88,
              child: Text(
                label,
                style: GoogleFonts.inter(color: context.textSecondary, fontSize: 13),
              ),
            ),
            Expanded(
              child: Text(
                value,
                style: GoogleFonts.inter(color: context.textPrimary, fontSize: 14, fontWeight: FontWeight.w600),
              ),
            ),
            if (onTap != null)
              Icon(Icons.chevron_right_rounded, color: context.textTertiary, size: 20),
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
            padding: const EdgeInsets.fromLTRB(24, 28, 24, 0),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                const SkeletonBox(
                  width: 68,
                  height: 68,
                  borderRadius: BorderRadius.all(Radius.circular(34)),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const SkeletonBox(width: 140, height: 18),
                      const SizedBox(height: 8),
                      const SkeletonBox(width: 90, height: 13),
                    ],
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 32, 24, 0),
            child: const SkeletonBox(width: 140, height: 12.5),
          ),
          const SizedBox(height: 12),
          const _RowSkeleton(),
          const _RowSkeleton(),
          const _RowSkeleton(),
          const _RowSkeleton(),
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 32, 24, 0),
            child: const SkeletonBox(width: 100, height: 12.5),
          ),
          const SizedBox(height: 12),
          const _RowSkeleton(),
        ],
      ),
    );
  }
}

class _RowSkeleton extends StatelessWidget {
  const _RowSkeleton();

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
