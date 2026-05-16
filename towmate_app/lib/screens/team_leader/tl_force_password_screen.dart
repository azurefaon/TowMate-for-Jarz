import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/team_leader_service.dart';

class TlForcePasswordScreen extends StatefulWidget {
  const TlForcePasswordScreen({super.key});

  @override
  State<TlForcePasswordScreen> createState() => _TlForcePasswordScreenState();
}

class _TlForcePasswordScreenState extends State<TlForcePasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _currentCtrl = TextEditingController();
  final _newCtrl = TextEditingController();
  final _confirmCtrl = TextEditingController();

  bool _loading = false;
  String? _error;

  @override
  void dispose() {
    _currentCtrl.dispose();
    _newCtrl.dispose();
    _confirmCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() { _loading = true; _error = null; });

    final res = await TeamLeaderService.changePassword(
      currentPassword: _currentCtrl.text,
      newPassword: _newCtrl.text,
      confirmPassword: _confirmCtrl.text,
    );

    if (!mounted) return;
    if (res['success'] == true) {
      Navigator.pushReplacementNamed(context, '/tl-home');
    } else {
      setState(() {
        _loading = false;
        _error = res['message'] as String? ?? 'Password change failed.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: TmColors.white,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 28),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const SizedBox(height: 64),
                RichText(
                  text: TextSpan(children: [
                    TextSpan(
                      text: 'Tow',
                      style: GoogleFonts.inter(
                          color: TmColors.black, fontSize: 28, letterSpacing: -0.5),
                    ),
                    TextSpan(
                      text: 'Mate',
                      style: GoogleFonts.inter(
                          color: TmColors.yellow, fontSize: 28, letterSpacing: -0.5),
                    ),
                  ]),
                ),
                const SizedBox(height: 6),
                Text(
                  'Set your new password',
                  style: GoogleFonts.inter(
                      color: TmColors.grey500, fontSize: 14),
                ),
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  decoration: BoxDecoration(
                    color: TmColors.yellow.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.info_outline_rounded,
                          color: TmColors.yellow, size: 16),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          'Your account requires a password change before you can continue.',
                          style: GoogleFonts.inter(
                              color: TmColors.grey700, fontSize: 12),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 36),
                _Field(
                  controller: _currentCtrl,
                  label: 'Current Password',
                  hint: 'Enter your current password',
                  obscure: true,
                  validator: (v) => v == null || v.isEmpty ? 'Required' : null,
                ),
                const SizedBox(height: 20),
                _Field(
                  controller: _newCtrl,
                  label: 'New Password',
                  hint: 'Min. 8 characters',
                  obscure: true,
                  validator: (v) {
                    if (v == null || v.isEmpty) return 'Required';
                    if (v.length < 8) return 'At least 8 characters';
                    if (v == _currentCtrl.text) return 'Must differ from current password';
                    return null;
                  },
                ),
                const SizedBox(height: 20),
                _Field(
                  controller: _confirmCtrl,
                  label: 'Confirm New Password',
                  hint: 'Re-enter new password',
                  obscure: true,
                  textInputAction: TextInputAction.done,
                  onFieldSubmitted: (_) => _submit(),
                  validator: (v) {
                    if (v == null || v.isEmpty) return 'Required';
                    if (v != _newCtrl.text) return 'Passwords do not match';
                    return null;
                  },
                ),
                const SizedBox(height: 16),
                if (_error != null) ...[
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: TmColors.error.withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(10),
                      border: const Border(
                          left: BorderSide(color: TmColors.error, width: 3)),
                    ),
                    child: Text(_error!,
                        style: GoogleFonts.inter(
                            color: TmColors.error, fontSize: 13)),
                  ),
                  const SizedBox(height: 16),
                ],
                SizedBox(
                  width: double.infinity,
                  height: 52,
                  child: ElevatedButton(
                    onPressed: _loading ? null : _submit,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: TmColors.yellow,
                      foregroundColor: TmColors.black,
                      disabledBackgroundColor:
                          TmColors.yellow.withValues(alpha: 0.6),
                      shape: const StadiumBorder(),
                      elevation: 0,
                    ),
                    child: _loading
                        ? const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(
                                color: TmColors.black, strokeWidth: 2),
                          )
                        : Text('Set New Password',
                            style: GoogleFonts.inter(
                                color: TmColors.black, fontSize: 15)),
                  ),
                ),
                const SizedBox(height: 48),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Field extends StatefulWidget {
  const _Field({
    required this.controller,
    required this.label,
    required this.hint,
    required this.obscure,
    this.validator,
    this.textInputAction,
    this.onFieldSubmitted,
  });

  final TextEditingController controller;
  final String label;
  final String hint;
  final bool obscure;
  final String? Function(String?)? validator;
  final TextInputAction? textInputAction;
  final void Function(String)? onFieldSubmitted;

  @override
  State<_Field> createState() => _FieldState();
}

class _FieldState extends State<_Field> {
  late bool _hide;

  @override
  void initState() {
    super.initState();
    _hide = widget.obscure;
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(widget.label,
            style: GoogleFonts.inter(color: TmColors.black, fontSize: 13)),
        const SizedBox(height: 8),
        TextFormField(
          controller: widget.controller,
          obscureText: _hide,
          validator: widget.validator,
          textInputAction: widget.textInputAction,
          onFieldSubmitted: widget.onFieldSubmitted,
          autocorrect: false,
          enableSuggestions: false,
          style: GoogleFonts.inter(color: TmColors.black, fontSize: 14),
          decoration: InputDecoration(
            hintText: widget.hint,
            hintStyle:
                GoogleFonts.inter(color: TmColors.grey500, fontSize: 14),
            filled: true,
            fillColor: TmColors.grey100,
            suffixIcon: widget.obscure
                ? GestureDetector(
                    onTap: () => setState(() => _hide = !_hide),
                    child: Icon(
                      _hide
                          ? Icons.visibility_outlined
                          : Icons.visibility_off_outlined,
                      color: TmColors.grey500,
                      size: 20,
                    ),
                  )
                : null,
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(30),
              borderSide: BorderSide.none,
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(30),
              borderSide: BorderSide.none,
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(30),
              borderSide:
                  const BorderSide(color: TmColors.yellow, width: 1.5),
            ),
            errorBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(30),
              borderSide:
                  const BorderSide(color: TmColors.error, width: 1.5),
            ),
            focusedErrorBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(30),
              borderSide: const BorderSide(color: TmColors.error, width: 2),
            ),
            contentPadding:
                const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
          ),
        ),
      ],
    );
  }
}
