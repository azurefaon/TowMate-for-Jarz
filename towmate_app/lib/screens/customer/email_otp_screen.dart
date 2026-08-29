import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';
import '../../widgets/tm_button.dart';

class EmailOtpScreen extends StatefulWidget {
  const EmailOtpScreen({
    super.key,
    required this.email,
    required this.firstName,
    required this.lastName,
    required this.phone,
    required this.password,
    required this.confirmPassword,
    required this.csrfToken,
  });

  final String email;
  final String firstName;
  final String lastName;
  final String phone;
  final String password;
  final String confirmPassword;
  final String csrfToken;

  @override
  State<EmailOtpScreen> createState() => _EmailOtpScreenState();
}

class _EmailOtpScreenState extends State<EmailOtpScreen> {
  final List<TextEditingController> _digitControllers =
      List.generate(6, (_) => TextEditingController());
  final List<FocusNode> _focusNodes = List.generate(6, (_) => FocusNode());

  bool _isVerifying = false;
  bool _isResending = false;
  String? _error;
  int _resendCooldown = 60;
  Timer? _cooldownTimer;

  @override
  void initState() {
    super.initState();
    _startCooldown();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _focusNodes[0].requestFocus();
    });
  }

  @override
  void dispose() {
    _cooldownTimer?.cancel();
    for (final c in _digitControllers) {
      c.dispose();
    }
    for (final f in _focusNodes) {
      f.dispose();
    }
    super.dispose();
  }

  void _startCooldown() {
    _resendCooldown = 60;
    _cooldownTimer?.cancel();
    _cooldownTimer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (_resendCooldown <= 1) {
        t.cancel();
        if (mounted) setState(() => _resendCooldown = 0);
      } else {
        if (mounted) setState(() => _resendCooldown--);
      }
    });
  }

  String get _otp =>
      _digitControllers.map((c) => c.text).join();

  Future<void> _verify() async {
    final otp = _otp;
    if (otp.length < 6) {
      setState(() => _error = 'Please enter the 6-digit code.');
      return;
    }

    setState(() {
      _isVerifying = true;
      _error = null;
    });

    final verifyRes = await ApiService.verifyRegistrationOtp(widget.email, otp);
    if (!mounted) return;

    if (verifyRes['success'] != true) {
      setState(() {
        _isVerifying = false;
        _error = verifyRes['message'] as String? ?? 'Invalid or expired code.';
      });
      return;
    }

    final signupRes = await ApiService.signup(
      firstName: widget.firstName,
      lastName: widget.lastName,
      email: widget.email,
      phone: widget.phone,
      password: widget.password,
      confirmPassword: widget.confirmPassword,
      csrfToken: widget.csrfToken,
    );
    if (!mounted) return;

    if (signupRes['success'] == true) {
      Navigator.pushNamedAndRemoveUntil(context, '/home', (_) => false);
    } else {
      setState(() {
        _isVerifying = false;
        _error = signupRes['message'] as String? ??
            'Account creation failed. Please try again.';
      });
    }
  }

  Future<void> _resend() async {
    if (_resendCooldown > 0) return;
    setState(() {
      _isResending = true;
      _error = null;
    });
    final res = await ApiService.sendRegistrationOtp(widget.email);
    if (!mounted) return;
    setState(() => _isResending = false);
    if (res['success'] == true) {
      _startCooldown();
      for (final c in _digitControllers) {
        c.clear();
      }
      _focusNodes[0].requestFocus();
    } else {
      setState(() {
        _error = res['message'] as String? ?? 'Failed to resend code.';
      });
    }
  }

  void _onDigitChanged(String value, int index) {
    if (value.length == 1 && index < 5) {
      _focusNodes[index + 1].requestFocus();
    } else if (value.isEmpty && index > 0) {
      _focusNodes[index - 1].requestFocus();
    }
    if (_otp.length == 6) _verify();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: TmColors.white,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 48),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              TmButton.text('← Back', () => Navigator.pop(context)),
              const SizedBox(height: 40),
              const Icon(
                Icons.mark_email_read_outlined,
                color: TmColors.yellow,
                size: 52,
              ),
              const SizedBox(height: 20),
              Text(
                'Check your email',
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 26,
                  letterSpacing: -0.6,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'We sent a 6-digit code to',
                style: GoogleFonts.inter(
                  color: TmColors.grey500,
                  fontSize: 15,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                widget.email,
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 15,
                  letterSpacing: 0.1,
                ),
              ),
              const SizedBox(height: 36),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: List.generate(6, (i) => _DigitBox(
                  controller: _digitControllers[i],
                  focusNode: _focusNodes[i],
                  onChanged: (v) => _onDigitChanged(v, i),
                  onBackspace: () {
                    if (_digitControllers[i].text.isEmpty && i > 0) {
                      _digitControllers[i - 1].clear();
                      _focusNodes[i - 1].requestFocus();
                    }
                  },
                )),
              ),
              if (_error != null) ...[
                const SizedBox(height: 16),
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: TmColors.error.withValues(alpha: 0.08),
                    borderRadius: BorderRadius.circular(12),
                    border: const Border(
                      left: BorderSide(color: TmColors.error, width: 3),
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
              const SizedBox(height: 28),
              SizedBox(
                width: double.infinity,
                height: 56,
                child: ElevatedButton(
                  onPressed: (_isVerifying || _otp.length < 6) ? null : _verify,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: TmColors.yellow,
                    foregroundColor: TmColors.black,
                    disabledBackgroundColor:
                        TmColors.yellow.withValues(alpha: 0.5),
                    shape: const StadiumBorder(),
                    elevation: 0,
                  ),
                  child: _isVerifying
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                            color: TmColors.black,
                            strokeWidth: 2,
                          ),
                        )
                      : Text(
                          'Verify & create account',
                          style: GoogleFonts.inter(
                            color: TmColors.black,
                            fontSize: 16,
                            letterSpacing: 0.2,
                          ),
                        ),
                ),
              ),
              const SizedBox(height: 20),
              Center(
                child: _isResending
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                          color: TmColors.yellow,
                          strokeWidth: 2,
                        ),
                      )
                    : _resendCooldown > 0
                        ? Text(
                            'Resend code in ${_resendCooldown}s',
                            style: GoogleFonts.inter(
                              color: TmColors.grey500,
                              fontSize: 14,
                            ),
                          )
                        : GestureDetector(
                            onTap: _resend,
                            child: Text(
                              'Resend code',
                              style: GoogleFonts.inter(
                                color: TmColors.yellow,
                                fontSize: 14,
                                decoration: TextDecoration.underline,
                                decorationColor: TmColors.yellow,
                              ),
                            ),
                          ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _DigitBox extends StatelessWidget {
  const _DigitBox({
    required this.controller,
    required this.focusNode,
    required this.onChanged,
    required this.onBackspace,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final void Function(String) onChanged;
  final VoidCallback onBackspace;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 44,
      height: 54,
      child: KeyboardListener(
        focusNode: FocusNode(),
        onKeyEvent: (event) {
          if (event is KeyDownEvent &&
              event.logicalKey == LogicalKeyboardKey.backspace &&
              controller.text.isEmpty) {
            onBackspace();
          }
        },
        child: TextFormField(
          controller: controller,
          focusNode: focusNode,
          textAlign: TextAlign.center,
          keyboardType: TextInputType.number,
          inputFormatters: [
            FilteringTextInputFormatter.digitsOnly,
            LengthLimitingTextInputFormatter(1),
          ],
          style: GoogleFonts.inter(
            color: TmColors.black,
            fontSize: 20,
            letterSpacing: 0,
          ),
          onChanged: onChanged,
          decoration: InputDecoration(
            filled: true,
            fillColor: TmColors.grey100,
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(12),
              borderSide: BorderSide.none,
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(12),
              borderSide: BorderSide.none,
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(12),
              borderSide: const BorderSide(color: TmColors.yellow, width: 2),
            ),
            contentPadding: EdgeInsets.zero,
          ),
        ),
      ),
    );
  }
}
