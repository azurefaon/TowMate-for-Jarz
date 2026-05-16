import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../core/validators.dart';
import '../../core/security_utils.dart';
import '../../services/api_service.dart';
import '../../widgets/tm_button.dart';
import '../../widgets/password_strength_bar.dart';
import 'email_otp_screen.dart';

class SignupScreen extends StatefulWidget {
  const SignupScreen({super.key});

  @override
  State<SignupScreen> createState() => _SignupScreenState();
}

class _SignupScreenState extends State<SignupScreen> {
  final _formKey = GlobalKey<FormState>();
  final _firstNameController = TextEditingController();
  final _lastNameController = TextEditingController();
  final _emailController = TextEditingController();
  final _phoneController = TextEditingController();
  final _passwordController = TextEditingController();
  final _confirmPasswordController = TextEditingController();

  bool _isLoading = false;
  String? _apiError;
  String _passwordValue = '';
  bool _success = false;
  late String _csrfToken;

  @override
  void initState() {
    super.initState();
    _csrfToken = CsrfTokenService.generate();
    _redirectIfLoggedIn();
  }

  Future<void> _redirectIfLoggedIn() async {
    final loggedIn = await ApiService.isLoggedIn();
    if (!mounted) return;
    if (loggedIn) Navigator.pushReplacementNamed(context, '/home');
  }

  @override
  void dispose() {
    _firstNameController.dispose();
    _lastNameController.dispose();
    _emailController.dispose();
    _phoneController.dispose();
    _passwordController.clear();
    _confirmPasswordController.clear();
    _passwordController.dispose();
    _confirmPasswordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isLoading = true;
      _apiError = null;
    });

    final email = InputSanitizer.sanitize(_emailController.text);
    final res = await ApiService.sendRegistrationOtp(email);

    if (!mounted) return;

    if (res['success'] == true) {
      setState(() => _isLoading = false);
      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => EmailOtpScreen(
            email: email,
            firstName: InputSanitizer.sanitize(_firstNameController.text),
            lastName: InputSanitizer.sanitize(_lastNameController.text),
            phone: '+63${InputSanitizer.sanitize(_phoneController.text)}',
            password: _passwordController.text,
            confirmPassword: _confirmPasswordController.text,
            csrfToken: _csrfToken,
          ),
        ),
      );
    } else {
      setState(() {
        _isLoading = false;
        _apiError =
            res['message'] as String? ?? 'Failed to send OTP. Please try again.';
      });
    }
  }

  void _goToLogin() {
    if (Navigator.canPop(context)) {
      Navigator.pop(context);
    } else {
      Navigator.pushReplacementNamed(context, '/login');
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_success) return _SuccessView(onGoToLogin: _goToLogin);

    return Scaffold(
      backgroundColor: TmColors.white,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 48),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              TmButton.text('← Back to login', _goToLogin),
              const SizedBox(height: 32),
              Text(
                'Create account',
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 28,
                  letterSpacing: -0.8,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'Fill in your details below',
                style: GoogleFonts.inter(
                  color: TmColors.grey500,
                  fontSize: 15,
                  letterSpacing: 0.1,
                ),
              ),
              const SizedBox(height: 40),
              Form(
                key: _formKey,
                autovalidateMode: AutovalidateMode.onUserInteraction,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: _Field(
                            controller: _firstNameController,
                            label: 'First name',
                            icon: Icons.person_outline_rounded,
                            validator: (v) => Validators.name(v, 'First name'),
                            textInputAction: TextInputAction.next,
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: _Field(
                            controller: _lastNameController,
                            label: 'Last name',
                            icon: Icons.person_outline_rounded,
                            validator: (v) => Validators.name(v, 'Last name'),
                            textInputAction: TextInputAction.next,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),
                    _Field(
                      controller: _emailController,
                      label: 'Email',
                      icon: Icons.mail_outline_rounded,
                      keyboardType: TextInputType.emailAddress,
                      validator: Validators.email,
                      textInputAction: TextInputAction.next,
                    ),
                    const SizedBox(height: 16),
                    _Field(
                      controller: _phoneController,
                      label: 'Phone number',
                      icon: Icons.phone_outlined,
                      keyboardType: TextInputType.phone,
                      prefixText: '+63 ',
                      hintText: '9XXXXXXXXX',
                      validator: (v) {
                        if (v == null || v.trim().isEmpty) {
                          return 'Phone number is required';
                        }
                        if (!RegExp(r'^9\d{9}$').hasMatch(v.trim())) {
                          return 'Enter 10 digits starting with 9 (e.g. 9171234567)';
                        }
                        return null;
                      },
                      textInputAction: TextInputAction.next,
                    ),
                    const SizedBox(height: 16),
                    _Field(
                      controller: _passwordController,
                      label: 'Password',
                      icon: Icons.lock_outline_rounded,
                      obscureText: true,
                      validator: Validators.password,
                      textInputAction: TextInputAction.next,
                      onChanged: (v) => setState(() => _passwordValue = v),
                    ),
                    const SizedBox(height: 8),
                    PasswordStrengthBar(password: _passwordValue),
                    const SizedBox(height: 16),
                    _Field(
                      controller: _confirmPasswordController,
                      label: 'Confirm password',
                      icon: Icons.lock_outline_rounded,
                      obscureText: true,
                      validator: (v) =>
                          Validators.confirmPassword(v, _passwordValue),
                      textInputAction: TextInputAction.done,
                      onFieldSubmitted: (_) => _submit(),
                    ),
                    if (_apiError != null) ...[
                      const SizedBox(height: 16),
                      _ErrorBanner(message: _apiError!),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 28),
              SizedBox(
                width: double.infinity,
                height: 56,
                child: ElevatedButton(
                  onPressed: _isLoading ? null : _submit,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: TmColors.yellow,
                    foregroundColor: TmColors.black,
                    disabledBackgroundColor:
                        TmColors.yellow.withValues(alpha: 0.6),
                    shape: const StadiumBorder(),
                    elevation: 0,
                  ),
                  child: _isLoading
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                            color: TmColors.black,
                            strokeWidth: 2,
                          ),
                        )
                      : Text(
                          'Create account',
                          style: GoogleFonts.inter(
                            color: TmColors.black,
                            fontSize: 16,
                            letterSpacing: 0.2,
                          ),
                        ),
                ),
              ),
              const SizedBox(height: 24),
              Wrap(
                alignment: WrapAlignment.center,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Text(
                    'Already have an account?',
                    style: GoogleFonts.inter(
                      color: TmColors.grey700,
                      fontSize: 14,
                    ),
                  ),
                  const SizedBox(width: 4),
                  TmButton.text('Sign in', _goToLogin),
                ],
              ),
              const SizedBox(height: 24),
            ],
          ),
        ),
      ),
    );
  }
}

// ─── Pill-shaped form field (matches login screen style) ───────────────────

class _Field extends StatefulWidget {
  const _Field({
    required this.controller,
    required this.label,
    required this.icon,
    this.obscureText = false,
    this.keyboardType,
    this.validator,
    this.textInputAction,
    this.onChanged,
    this.onFieldSubmitted,
    this.prefixText,
    this.hintText,
  });

  final TextEditingController controller;
  final String label;
  final IconData icon;
  final bool obscureText;
  final TextInputType? keyboardType;
  final String? Function(String?)? validator;
  final TextInputAction? textInputAction;
  final void Function(String)? onChanged;
  final void Function(String)? onFieldSubmitted;
  final String? prefixText;
  final String? hintText;

  @override
  State<_Field> createState() => _FieldState();
}

class _FieldState extends State<_Field> {
  late bool _obscure;

  @override
  void initState() {
    super.initState();
    _obscure = widget.obscureText;
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          widget.label,
          style: GoogleFonts.inter(
            color: TmColors.black,
            fontSize: 14,
            letterSpacing: 0.1,
          ),
        ),
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
            controller: widget.controller,
            obscureText: _obscure,
            keyboardType: widget.keyboardType,
            validator: widget.validator,
            textInputAction: widget.textInputAction,
            onChanged: widget.onChanged,
            onFieldSubmitted: widget.onFieldSubmitted,
            autocorrect: !widget.obscureText,
            enableSuggestions: !widget.obscureText,
            autofillHints: widget.obscureText ? const [] : null,
            style: GoogleFonts.inter(color: TmColors.black, fontSize: 14),
            decoration: InputDecoration(
              hintText: widget.hintText ?? widget.label,
              hintStyle: GoogleFonts.inter(
                color: TmColors.grey500,
                fontSize: 14,
              ),
              filled: true,
              fillColor: TmColors.grey100,
              prefixIcon: Icon(widget.icon, color: TmColors.yellow, size: 20),
              prefixText: widget.prefixText,
              prefixStyle: GoogleFonts.inter(
                color: TmColors.black,
                fontSize: 14,
              ),
              suffixIcon: widget.obscureText
                  ? GestureDetector(
                      onTap: () => setState(() => _obscure = !_obscure),
                      child: Icon(
                        _obscure
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
                borderSide: const BorderSide(color: TmColors.yellow, width: 1.5),
              ),
              errorBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(30),
                borderSide: const BorderSide(color: TmColors.error, width: 1.5),
              ),
              focusedErrorBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(30),
                borderSide: const BorderSide(color: TmColors.error, width: 2),
              ),
              contentPadding:
                  const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
              errorStyle: GoogleFonts.inter(color: TmColors.error, fontSize: 12),
            ),
          ),
        ),
      ],
    );
  }
}

// ─── Success view ──────────────────────────────────────────────────────────

class _SuccessView extends StatelessWidget {
  const _SuccessView({required this.onGoToLogin});
  final VoidCallback onGoToLogin;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: TmColors.white,
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 32),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(
                Icons.check_circle_outline,
                color: TmColors.success,
                size: 64,
              ),
              const SizedBox(height: 24),
              Text(
                'Account created!',
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 26,
                  letterSpacing: -0.6,
                ),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 8),
              Text(
                'You can now sign in with your credentials.',
                style: GoogleFonts.inter(
                  color: TmColors.grey700,
                  fontSize: 15,
                  letterSpacing: 0.1,
                ),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 40),
              SizedBox(
                width: double.infinity,
                height: 56,
                child: ElevatedButton(
                  onPressed: onGoToLogin,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: TmColors.yellow,
                    foregroundColor: TmColors.black,
                    shape: const StadiumBorder(),
                    elevation: 0,
                  ),
                  child: Text(
                    'Go to login',
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
    );
  }
}

// ─── Error banner ──────────────────────────────────────────────────────────

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: TmColors.error.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(12),
        border: const Border(left: BorderSide(color: TmColors.error, width: 3)),
      ),
      child: Text(
        message,
        style: GoogleFonts.inter(
          color: TmColors.error,
          fontSize: 13,
          letterSpacing: 0.1,
        ),
      ),
    );
  }
}
