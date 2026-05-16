import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';
import 'reset_password_screen.dart';

class ResetOtpScreen extends StatefulWidget {
  const ResetOtpScreen({super.key, required this.email});
  final String email;

  @override
  State<ResetOtpScreen> createState() => _ResetOtpScreenState();
}

class _ResetOtpScreenState extends State<ResetOtpScreen> {
  final List<TextEditingController> _controllers =
      List.generate(6, (_) => TextEditingController());
  final List<FocusNode> _focusNodes = List.generate(6, (_) => FocusNode());

  bool _loading = false;
  bool _resending = false;
  String? _error;
  int _secondsLeft = 600; // 10 minutes
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _startTimer();
  }

  void _startTimer() {
    _timer?.cancel();
    setState(() => _secondsLeft = 600);
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      if (_secondsLeft <= 0) {
        _timer?.cancel();
        setState(() {});
      } else {
        setState(() => _secondsLeft--);
      }
    });
  }

  String get _timerLabel {
    final m = _secondsLeft ~/ 60;
    final s = _secondsLeft % 60;
    return '${m.toString().padLeft(2, '0')}:${s.toString().padLeft(2, '0')}';
  }

  String get _otp => _controllers.map((c) => c.text).join();

  @override
  void dispose() {
    _timer?.cancel();
    for (final c in _controllers) {
      c.dispose();
    }
    for (final f in _focusNodes) {
      f.dispose();
    }
    super.dispose();
  }

  Future<void> _verify() async {
    if (_otp.length < 6) {
      setState(() => _error = 'Please enter the complete 6-digit OTP.');
      return;
    }
    setState(() { _loading = true; _error = null; });

    final res = await ApiService.verifyResetOtp(widget.email, _otp);
    if (!mounted) return;

    if (res['success'] == true) {
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(
          builder: (_) => ResetPasswordScreen(
            email: widget.email,
            resetToken: res['reset_token'] as String,
          ),
        ),
      );
    } else {
      setState(() {
        _loading = false;
        _error = res['message'] as String? ?? 'Invalid OTP.';
      });
    }
  }

  Future<void> _resend() async {
    setState(() { _resending = true; _error = null; });
    final res = await ApiService.sendResetOtp(widget.email);
    if (!mounted) return;
    setState(() => _resending = false);
    if (res['success'] == true) {
      for (final c in _controllers) {
        c.clear();
      }
      _focusNodes[0].requestFocus();
      _startTimer();
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('OTP resent to your email.'),
        backgroundColor: TmColors.success,
      ));
    } else {
      setState(() => _error = res['message'] as String? ?? 'Failed to resend.');
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
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SizedBox(height: 24),
              Text('Enter OTP',
                  style: GoogleFonts.inter(
                      color: TmColors.black,
                      fontSize: 26,
                      letterSpacing: -0.6)),
              const SizedBox(height: 8),
              RichText(
                text: TextSpan(
                  style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 14),
                  children: [
                    const TextSpan(text: 'We sent a 6-digit code to '),
                    TextSpan(
                      text: widget.email,
                      style: GoogleFonts.inter(
                          color: TmColors.black, fontSize: 14),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 36),

              // OTP boxes
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: List.generate(6, (i) => _OtpBox(
                  controller: _controllers[i],
                  focusNode: _focusNodes[i],
                  onChanged: (val) {
                    if (val.length == 1 && i < 5) {
                      _focusNodes[i + 1].requestFocus();
                    }
                    setState(() => _error = null);
                  },
                  onBackspace: () {
                    if (_controllers[i].text.isEmpty && i > 0) {
                      _focusNodes[i - 1].requestFocus();
                      _controllers[i - 1].clear();
                    }
                  },
                )),
              ),
              const SizedBox(height: 16),

              // Timer
              Center(
                child: _secondsLeft > 0
                    ? Text(
                        'OTP expires in $_timerLabel',
                        style: GoogleFonts.inter(
                            color: TmColors.grey500, fontSize: 13),
                      )
                    : Text(
                        'OTP expired.',
                        style: GoogleFonts.inter(
                            color: TmColors.error, fontSize: 13),
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
                  onPressed: (_loading || _secondsLeft == 0) ? null : _verify,
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
                      : Text('Verify OTP',
                          style: GoogleFonts.inter(
                              color: TmColors.black,
                              fontSize: 16,
                              letterSpacing: 0.2)),
                ),
              ),
              const SizedBox(height: 20),

              // Resend
              Center(
                child: _resending
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                            color: TmColors.yellow, strokeWidth: 2))
                    : GestureDetector(
                        onTap: _resend,
                        child: Text.rich(
                          TextSpan(
                            children: [
                              TextSpan(
                                text: "Didn't receive it? ",
                                style: GoogleFonts.inter(
                                    color: TmColors.grey500, fontSize: 14),
                              ),
                              TextSpan(
                                text: 'Resend',
                                style: GoogleFonts.inter(
                                  color: TmColors.yellow,
                                  fontSize: 14,
                                  decoration: TextDecoration.underline,
                                  decorationColor: TmColors.yellow,
                                ),
                              ),
                            ],
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

class _OtpBox extends StatelessWidget {
  const _OtpBox({
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
      width: 46,
      height: 56,
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
          keyboardType: TextInputType.number,
          textAlign: TextAlign.center,
          maxLength: 1,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          style: GoogleFonts.inter(
              color: TmColors.black, fontSize: 22),
          onChanged: onChanged,
          decoration: InputDecoration(
            counterText: '',
            filled: true,
            fillColor: TmColors.grey100,
            border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
                borderSide: BorderSide.none),
            enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
                borderSide: const BorderSide(color: TmColors.grey300)),
            focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
                borderSide:
                    const BorderSide(color: TmColors.yellow, width: 2)),
          ),
        ),
      ),
    );
  }
}
