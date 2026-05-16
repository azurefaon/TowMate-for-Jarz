import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';

class ResetPasswordScreen extends StatefulWidget {
  const ResetPasswordScreen({
    super.key,
    required this.email,
    required this.resetToken,
  });
  final String email;
  final String resetToken;

  @override
  State<ResetPasswordScreen> createState() => _ResetPasswordScreenState();
}

class _ResetPasswordScreenState extends State<ResetPasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _passwordCtrl = TextEditingController();
  final _confirmCtrl = TextEditingController();
  bool _loading = false;
  String? _error;
  bool _obscurePassword = true;
  bool _obscureConfirm = true;

  @override
  void dispose() {
    _passwordCtrl.dispose();
    _confirmCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() { _loading = true; _error = null; });

    final res = await ApiService.resetPassword(
      email: widget.email,
      resetToken: widget.resetToken,
      password: _passwordCtrl.text,
      passwordConfirmation: _confirmCtrl.text,
    );
    if (!mounted) return;

    if (res['success'] == true) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Password reset successfully! Please log in.'),
        backgroundColor: TmColors.success,
      ));
      Navigator.pushNamedAndRemoveUntil(context, '/login', (_) => false);
    } else {
      setState(() {
        _loading = false;
        _error = res['message'] as String? ?? 'Something went wrong.';
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
          icon: const Icon(Icons.arrow_back_rounded, color: TmColors.black),
          onPressed: () => Navigator.pop(context),
        ),
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 28),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const SizedBox(height: 24),
                Text('New Password',
                    style: GoogleFonts.inter(
                        color: TmColors.black,
                        fontSize: 26,
                        letterSpacing: -0.6)),
                const SizedBox(height: 8),
                Text(
                  'Create a new password for your account.',
                  style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 14),
                ),
                const SizedBox(height: 36),

                _buildField(
                  controller: _passwordCtrl,
                  label: 'New Password',
                  hint: 'At least 8 characters',
                  obscure: _obscurePassword,
                  onToggle: () => setState(() => _obscurePassword = !_obscurePassword),
                  validator: (v) {
                    if (v == null || v.isEmpty) return 'Password is required';
                    if (v.length < 8) return 'Minimum 8 characters';
                    return null;
                  },
                  textInputAction: TextInputAction.next,
                ),
                const SizedBox(height: 20),

                _buildField(
                  controller: _confirmCtrl,
                  label: 'Confirm Password',
                  hint: 'Re-enter your password',
                  obscure: _obscureConfirm,
                  onToggle: () => setState(() => _obscureConfirm = !_obscureConfirm),
                  validator: (v) {
                    if (v == null || v.isEmpty) return 'Please confirm your password';
                    if (v != _passwordCtrl.text) return 'Passwords do not match';
                    return null;
                  },
                  textInputAction: TextInputAction.done,
                  onFieldSubmitted: (_) => _submit(),
                ),

                if (_error != null) ...[
                  const SizedBox(height: 16),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: TmColors.error.withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(12),
                      border: const Border(
                          left: BorderSide(color: TmColors.error, width: 3)),
                    ),
                    child: Text(_error!,
                        style: GoogleFonts.inter(
                            color: TmColors.error, fontSize: 13)),
                  ),
                ],

                const SizedBox(height: 28),
                SizedBox(
                  width: double.infinity,
                  height: 56,
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
                                color: TmColors.black, strokeWidth: 2))
                        : Text('Reset Password',
                            style: GoogleFonts.inter(
                                color: TmColors.black,
                                fontSize: 16,
                                letterSpacing: 0.2)),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildField({
    required TextEditingController controller,
    required String label,
    required String hint,
    required bool obscure,
    required VoidCallback onToggle,
    required String? Function(String?) validator,
    TextInputAction? textInputAction,
    void Function(String)? onFieldSubmitted,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label,
            style: GoogleFonts.inter(color: TmColors.black, fontSize: 14)),
        const SizedBox(height: 8),
        Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(30),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.07),
                blurRadius: 14,
                offset: const Offset(0, 4),
              ),
            ],
          ),
          child: TextFormField(
            controller: controller,
            obscureText: obscure,
            validator: validator,
            textInputAction: textInputAction,
            onFieldSubmitted: onFieldSubmitted,
            style: GoogleFonts.inter(color: TmColors.black, fontSize: 14),
            decoration: InputDecoration(
              hintText: hint,
              hintStyle:
                  GoogleFonts.inter(color: TmColors.grey500, fontSize: 14),
              filled: true,
              fillColor: TmColors.grey100,
              prefixIcon: const Icon(Icons.lock_outline_rounded,
                  color: TmColors.yellow, size: 20),
              suffixIcon: GestureDetector(
                onTap: onToggle,
                child: Icon(
                  obscure
                      ? Icons.visibility_outlined
                      : Icons.visibility_off_outlined,
                  color: TmColors.grey500,
                  size: 20,
                ),
              ),
              border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(30),
                  borderSide: BorderSide.none),
              enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(30),
                  borderSide: BorderSide.none),
              focusedBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(30),
                  borderSide:
                      const BorderSide(color: TmColors.yellow, width: 1.5)),
              errorBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(30),
                  borderSide:
                      const BorderSide(color: TmColors.error, width: 1.5)),
              focusedErrorBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(30),
                  borderSide:
                      const BorderSide(color: TmColors.error, width: 2)),
              contentPadding:
                  const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
              errorStyle:
                  GoogleFonts.inter(color: TmColors.error, fontSize: 12),
            ),
          ),
        ),
      ],
    );
  }
}
