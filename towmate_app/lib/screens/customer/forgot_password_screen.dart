import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';
import 'reset_otp_screen.dart';

class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailCtrl = TextEditingController();
  bool _loading = false;
  String? _error;

  @override
  void dispose() {
    _emailCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() { _loading = true; _error = null; });

    final res = await ApiService.sendResetOtp(_emailCtrl.text.trim().toLowerCase());
    if (!mounted) return;

    if (res['success'] == true) {
      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => ResetOtpScreen(email: _emailCtrl.text.trim().toLowerCase()),
        ),
      );
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
                Text('Forgot Password',
                    style: GoogleFonts.inter(
                        color: TmColors.black,
                        fontSize: 26,
                        letterSpacing: -0.6)),
                const SizedBox(height: 8),
                Text(
                  'Enter your email address and we\'ll send you a 6-digit OTP to reset your password.',
                  style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 14),
                ),
                const SizedBox(height: 36),

                Text('Email Address',
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
                    controller: _emailCtrl,
                    keyboardType: TextInputType.emailAddress,
                    textInputAction: TextInputAction.done,
                    onFieldSubmitted: (_) => _submit(),
                    style: GoogleFonts.inter(color: TmColors.black, fontSize: 14),
                    validator: (v) {
                      if (v == null || v.trim().isEmpty) return 'Email is required';
                      if (!v.contains('@')) return 'Enter a valid email';
                      return null;
                    },
                    decoration: InputDecoration(
                      hintText: 'Enter your email',
                      hintStyle: GoogleFonts.inter(color: TmColors.grey500, fontSize: 14),
                      filled: true,
                      fillColor: TmColors.grey100,
                      prefixIcon: const Icon(Icons.mail_outline_rounded,
                          color: TmColors.yellow, size: 20),
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
                      contentPadding: const EdgeInsets.symmetric(
                          horizontal: 20, vertical: 16),
                      errorStyle:
                          GoogleFonts.inter(color: TmColors.error, fontSize: 12),
                    ),
                  ),
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
                        : Text('Send OTP',
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
}
