import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';
import '../../services/team_leader_service.dart';
import '../../widgets/tl_drawer.dart';

class TlProfileScreen extends StatefulWidget {
  const TlProfileScreen({super.key});

  @override
  State<TlProfileScreen> createState() => _TlProfileScreenState();
}

class _TlProfileScreenState extends State<TlProfileScreen> {
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
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
        backgroundColor: TmColors.white,
        title: Text('Edit Name', style: GoogleFonts.inter(color: TmColors.black, fontSize: 16)),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: firstCtrl,
              autofocus: true,
              style: GoogleFonts.inter(color: TmColors.black, fontSize: 15),
              decoration: const InputDecoration(
                hintText: 'First name',
                enabledBorder: UnderlineInputBorder(borderSide: BorderSide(color: TmColors.black)),
                focusedBorder: UnderlineInputBorder(borderSide: BorderSide(color: TmColors.yellow, width: 1.5)),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: lastCtrl,
              style: GoogleFonts.inter(color: TmColors.black, fontSize: 15),
              decoration: const InputDecoration(
                hintText: 'Last name',
                enabledBorder: UnderlineInputBorder(borderSide: BorderSide(color: TmColors.black)),
                focusedBorder: UnderlineInputBorder(borderSide: BorderSide(color: TmColors.yellow, width: 1.5)),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text('Cancel', style: GoogleFonts.inter(color: TmColors.black, fontSize: 14)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text('Save', style: GoogleFonts.inter(color: TmColors.black, fontWeight: FontWeight.w600, fontSize: 14)),
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
        backgroundColor: TmColors.white,
        title: Text('Edit Phone', style: GoogleFonts.inter(color: TmColors.black, fontSize: 16)),
        content: TextField(
          controller: controller,
          autofocus: true,
          keyboardType: TextInputType.phone,
          style: GoogleFonts.inter(color: TmColors.black, fontSize: 15),
          decoration: const InputDecoration(
            enabledBorder: UnderlineInputBorder(borderSide: BorderSide(color: TmColors.black)),
            focusedBorder: UnderlineInputBorder(borderSide: BorderSide(color: TmColors.yellow, width: 1.5)),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text('Cancel', style: GoogleFonts.inter(color: TmColors.black, fontSize: 14)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, controller.text.trim()),
            child: Text('Save', style: GoogleFonts.inter(color: TmColors.black, fontWeight: FontWeight.w600, fontSize: 14)),
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
          backgroundColor: TmColors.white,
          title: Text('Change Password', style: GoogleFonts.inter(color: TmColors.black, fontSize: 16)),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (error != null) ...[
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(border: Border.all(color: TmColors.error)),
                    child: Text(error!, style: GoogleFonts.inter(color: TmColors.error, fontSize: 12)),
                  ),
                  const SizedBox(height: 12),
                ],
                TextField(
                  controller: currentCtrl,
                  obscureText: true,
                  style: GoogleFonts.inter(color: TmColors.black, fontSize: 14),
                  decoration: InputDecoration(
                    labelText: 'Current password',
                    labelStyle: GoogleFonts.inter(color: TmColors.black, fontSize: 13),
                    enabledBorder: const UnderlineInputBorder(borderSide: BorderSide(color: TmColors.black)),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: newCtrl,
                  obscureText: true,
                  style: GoogleFonts.inter(color: TmColors.black, fontSize: 14),
                  decoration: InputDecoration(
                    labelText: 'New password',
                    labelStyle: GoogleFonts.inter(color: TmColors.black, fontSize: 13),
                    enabledBorder: const UnderlineInputBorder(borderSide: BorderSide(color: TmColors.black)),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: confirmCtrl,
                  obscureText: true,
                  style: GoogleFonts.inter(color: TmColors.black, fontSize: 14),
                  decoration: InputDecoration(
                    labelText: 'Confirm new password',
                    labelStyle: GoogleFonts.inter(color: TmColors.black, fontSize: 13),
                    enabledBorder: const UnderlineInputBorder(borderSide: BorderSide(color: TmColors.black)),
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: saving ? null : () => Navigator.pop(ctx),
              child: Text('Cancel', style: GoogleFonts.inter(color: TmColors.black, fontSize: 14)),
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
                style: GoogleFonts.inter(color: TmColors.black, fontWeight: FontWeight.w600, fontSize: 14),
              ),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: TmColors.white,
      drawer: TlDrawer(currentRoute: '/tl-profile', name: _name),
      appBar: AppBar(
        backgroundColor: TmColors.white,
        elevation: 0,
        automaticallyImplyLeading: false,
        leading: IconButton(
          icon: const Icon(Icons.menu_rounded, color: TmColors.black),
          onPressed: () => _scaffoldKey.currentState?.openDrawer(),
          tooltip: 'Menu',
        ),
        title: Text(
          'Profile',
          style: GoogleFonts.inter(color: TmColors.black, fontSize: 18, fontWeight: FontWeight.w600),
        ),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator(color: TmColors.yellow))
          : SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: double.infinity,
                    color: TmColors.black,
                    padding: const EdgeInsets.fromLTRB(24, 32, 24, 32),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Container(
                          width: 60,
                          height: 60,
                          decoration: BoxDecoration(
                            color: TmColors.yellow,
                            borderRadius: BorderRadius.circular(30),
                          ),
                          child: Center(
                            child: Text(
                              _initials,
                              style: GoogleFonts.inter(color: TmColors.black, fontSize: 22, fontWeight: FontWeight.w700),
                            ),
                          ),
                        ),
                        const SizedBox(height: 14),
                        Text(
                          _name ?? '—',
                          style: GoogleFonts.inter(color: TmColors.white, fontSize: 20, fontWeight: FontWeight.w600),
                        ),
                      ],
                    ),
                  ),
                  _row('Name', _name ?? '—', _editName),
                  _row('Email', _email ?? '—', null),
                  _row('Phone', _phone ?? '—', _editPhone),
                  _row('Password', '••••••••', _changePassword),
                  const SizedBox(height: 24),
                ],
              ),
            ),
    );
  }

  Widget _row(String label, String value, VoidCallback? onTap) {
    return InkWell(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 16),
        decoration: const BoxDecoration(
          border: Border(bottom: BorderSide(color: TmColors.black, width: 1)),
        ),
        child: Row(
          children: [
            SizedBox(
              width: 80,
              child: Text(label, style: GoogleFonts.inter(color: TmColors.black, fontSize: 13)),
            ),
            Expanded(
              child: Text(value, style: GoogleFonts.inter(color: TmColors.black, fontSize: 14, fontWeight: FontWeight.w600)),
            ),
            if (onTap != null)
              const Icon(Icons.chevron_right_rounded, color: TmColors.black, size: 20),
          ],
        ),
      ),
    );
  }
}
