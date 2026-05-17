import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/app_prefs.dart';
import '../../core/theme.dart';
import '../../main.dart' show themeModeNotifier;
import '../../services/api_service.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  String? _name;
  String? _email;
  String? _phone;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final name  = await ApiService.getUserName();
    final email = await ApiService.getUserEmail();
    final phone = await ApiService.getUserPhone();
    if (!mounted) return;
    setState(() {
      _name  = name;
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

  Future<void> _editName() async {
    final controller = TextEditingController(text: _name);
    final result = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: ctx.card,
        title: Text(
          'Edit Name',
          style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 16, letterSpacing: -0.2),
        ),
        content: TextField(
          controller: controller,
          autofocus: true,
          style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 15),
          decoration: InputDecoration(
            hintText: 'Your full name',
            hintStyle: GoogleFonts.inter(color: ctx.textSecondary, fontSize: 15),
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
            child: Text('Save', style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14)),
          ),
        ],
      ),
    );

    if (result == null || result.isEmpty || result == _name) return;
    final res = await ApiService.updateProfile(name: result);
    if (!mounted) return;
    if (res['success'] == true) {
      setState(() => _name = result);
      ScaffoldMessenger.of(context).showSnackBar(_snack('Name updated.'));
    } else {
      ScaffoldMessenger.of(context).showSnackBar(_snack(res['message'] ?? 'Failed to update name.'));
    }
  }

  Future<void> _changePassword() async {
    final currentCtrl = TextEditingController();
    final newCtrl     = TextEditingController();
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
            style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 16, letterSpacing: -0.2),
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
                    child: Text(error!, style: GoogleFonts.inter(color: TmColors.error, fontSize: 12)),
                  ),
                  const SizedBox(height: 12),
                ],
                _PwField(label: 'Current password', controller: currentCtrl),
                const SizedBox(height: 12),
                _PwField(label: 'New password', controller: newCtrl),
                const SizedBox(height: 12),
                _PwField(label: 'Confirm new password', controller: confirmCtrl),
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
                      final cur  = currentCtrl.text;
                      final nw   = newCtrl.text;
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
                      setS(() { saving = true; error = null; });
                      final res = await ApiService.changePassword(
                        currentPassword: cur,
                        newPassword: nw,
                      );
                      if (!ctx.mounted) return;
                      if (res['success'] == true) {
                        Navigator.pop(ctx);
                        if (mounted) ScaffoldMessenger.of(context).showSnackBar(_snack('Password changed.'));
                      } else {
                        setS(() { saving = false; error = res['message'] ?? 'Failed to change password.'; });
                      }
                    },
              child: Text(saving ? 'Saving…' : 'Save',
                  style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14)),
            ),
          ],
        ),
      ),
    );
  }

  SnackBar _snack(String msg) => SnackBar(
        content: Text(msg, style: GoogleFonts.inter(color: TmColors.black, fontSize: 14)),
        backgroundColor: TmColors.yellow,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        margin: const EdgeInsets.all(16),
      );

  @override
  Widget build(BuildContext context) {
    final isDark = context.isDark;

    return Scaffold(
      backgroundColor: context.bg,
      body: SafeArea(
        child: Column(
          children: [
            // Top bar
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
              decoration: BoxDecoration(
                border: Border(bottom: BorderSide(color: context.divider, width: 0.5)),
              ),
              child: Row(
                children: [
                  IconButton(
                    icon: Icon(Icons.arrow_back_rounded, color: context.textTertiary),
                    onPressed: () => Navigator.pop(context),
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Center(
                      child: Text(
                        'TowMate',
                        style: GoogleFonts.inter(color: TmColors.yellow, fontSize: 22, letterSpacing: -0.8),
                      ),
                    ),
                  ),
                  const SizedBox(width: 40),
                ],
              ),
            ),

            Expanded(
              child: _loading
                  ? Center(
                      child: SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(color: TmColors.yellow, strokeWidth: 2),
                      ),
                    )
                  : SingleChildScrollView(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          // ── Avatar + name header ────────────────────────
                          Container(
                            width: double.infinity,
                            color: isDark ? TmColors.dark800 : TmColors.black,
                            padding: const EdgeInsets.fromLTRB(24, 40, 24, 40),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Container(
                                  width: 64,
                                  height: 64,
                                  decoration: BoxDecoration(
                                    color: TmColors.yellow,
                                    borderRadius: BorderRadius.circular(32),
                                  ),
                                  child: Center(
                                    child: Text(
                                      _initials,
                                      style: GoogleFonts.inter(color: TmColors.black, fontSize: 24, letterSpacing: -0.5),
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 16),
                                Text(
                                  _name ?? '—',
                                  style: GoogleFonts.inter(color: TmColors.white, fontSize: 22, letterSpacing: -0.6),
                                ),
                                if (_email != null && _email!.isNotEmpty) ...[
                                  const SizedBox(height: 4),
                                  Text(_email!, style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 13, letterSpacing: 0.1)),
                                ],
                                if (_phone != null && _phone!.isNotEmpty) ...[
                                  const SizedBox(height: 2),
                                  Text(_phone!, style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 13, letterSpacing: 0.1)),
                                ],
                              ],
                            ),
                          ),

                          // ── Account Settings ────────────────────────────
                          Padding(
                            padding: const EdgeInsets.fromLTRB(24, 32, 24, 0),
                            child: Text(
                              'ACCOUNT SETTINGS',
                              style: GoogleFonts.inter(color: context.textSecondary, fontSize: 11, letterSpacing: 0.8),
                            ),
                          ),
                          const SizedBox(height: 12),
                          _SettingsRow(label: 'Name', value: _name ?? '—', onTap: _editName),
                          _SettingsRow(label: 'Password', value: '••••••••', onTap: _changePassword),

                          // ── Appearance ──────────────────────────────────
                          Padding(
                            padding: const EdgeInsets.fromLTRB(24, 32, 24, 0),
                            child: Text(
                              'APPEARANCE',
                              style: GoogleFonts.inter(color: context.textSecondary, fontSize: 11, letterSpacing: 0.8),
                            ),
                          ),
                          const SizedBox(height: 12),
                          ValueListenableBuilder<ThemeMode>(
                            valueListenable: themeModeNotifier,
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
                                      width: 88,
                                      child: Text(
                                        'Dark Mode',
                                        style: GoogleFonts.inter(color: ctx.textSecondary, fontSize: 13, letterSpacing: 0.1),
                                      ),
                                    ),
                                    Expanded(
                                      child: Text(
                                        dark ? 'On' : 'Off',
                                        style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14, letterSpacing: 0.1),
                                      ),
                                    ),
                                    Switch(
                                      value: dark,
                                      onChanged: (val) async {
                                        themeModeNotifier.value = val ? ThemeMode.dark : ThemeMode.light;
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
  const _SettingsRow({required this.label, required this.value, required this.onTap});
  final String label;
  final String value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
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
              child: Text(label,
                  style: GoogleFonts.inter(color: context.textSecondary, fontSize: 13, letterSpacing: 0.1)),
            ),
            Expanded(
              child: Text(value,
                  style: GoogleFonts.inter(color: context.textPrimary, fontSize: 14, letterSpacing: 0.1)),
            ),
            Icon(Icons.chevron_right_rounded, color: context.textSecondary, size: 20),
          ],
        ),
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
        labelStyle: GoogleFonts.inter(color: context.textSecondary, fontSize: 13),
        enabledBorder: UnderlineInputBorder(borderSide: BorderSide(color: context.divider)),
        focusedBorder: const UnderlineInputBorder(borderSide: BorderSide(color: TmColors.yellow, width: 1.5)),
        suffixIcon: GestureDetector(
          onTap: () => setState(() => _obscure = !_obscure),
          child: Icon(
            _obscure ? Icons.visibility_off_outlined : Icons.visibility_outlined,
            color: context.textSecondary,
            size: 18,
          ),
        ),
      ),
    );
  }
}
