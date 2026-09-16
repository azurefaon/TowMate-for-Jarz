import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../core/validators.dart';
import '../../core/security_utils.dart';
import '../../services/api_service.dart';
import '../../services/google_auth_service.dart';
import '../../widgets/google_signin_button.dart';
import 'forgot_password_screen.dart';
import 'google_phone_completion_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();

  bool _isLoading = false;
  bool _isGoogleLoading = false;
  String? _apiError;
  bool _rateLocked = false;
  Duration _remainingCooldown = Duration.zero;
  Timer? _cooldownTimer;
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
    if (!loggedIn) return;
    final role = await ApiService.getUserRole();
    if (!mounted) return;
    Navigator.pushReplacementNamed(
      context,
      role == 'Team Leader' ? '/tl-home' : '/home',
    );
  }

  @override
  void dispose() {
    _cooldownTimer?.cancel();
    _passwordController.clear();
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (RateLimiter.isLocked) {
      setState(() => _rateLocked = true);
      return;
    }

    if (!_formKey.currentState!.validate()) return;

    final email = InputSanitizer.sanitize(_emailController.text);
    final password = _passwordController.text;

    setState(() {
      _isLoading = true;
      _apiError = null;
    });

    final res = await ApiService.login(email, password, _csrfToken);

    if (!mounted) return;

    if (res['success'] == true) {
      RateLimiter.reset();
      final role = res['role'] as String? ?? 'Customer';
      final mustChange = res['must_change_password'] == true;
      final route = role == 'Team Leader'
          ? (mustChange ? '/tl-force-password' : '/tl-home')
          : '/home';
      Navigator.pushReplacementNamed(context, route);
    } else {
      RateLimiter.recordFailure();
      final locked = RateLimiter.isLocked;
      if (locked) _startCooldownTimer();
      setState(() {
        _isLoading = false;
        _apiError = res['message'] as String? ?? 'Invalid credentials. Please try again.';
        _rateLocked = locked;
      });
    }
  }

  void _startCooldownTimer() {
    _cooldownTimer?.cancel();
    _cooldownTimer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      final remaining = RateLimiter.remainingCooldown;
      if (remaining == Duration.zero) {
        _cooldownTimer?.cancel();
        setState(() {
          _rateLocked = false;
          _remainingCooldown = Duration.zero;
        });
      } else {
        setState(() => _remainingCooldown = remaining);
      }
    });
    setState(() => _remainingCooldown = RateLimiter.remainingCooldown);
  }

  void _onForgotPassword() {
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const ForgotPasswordScreen()),
    );
  }

  Future<void> _onGoogleSignIn() async {
    if (_isGoogleLoading || _isLoading) return;

    setState(() {
      _isGoogleLoading = true;
      _apiError = null;
    });

    final result = await GoogleAuthService.signIn();
    await _handleGoogleResult(result);
  }

  void _onWebGoogleResult(GoogleAuthResult result) {
    if (_isLoading) return;
    setState(() {
      _isGoogleLoading = true;
      _apiError = null;
    });
    _handleGoogleResult(result);
  }

  Future<void> _handleGoogleResult(GoogleAuthResult result) async {
    if (!mounted) return;

    if (result.outcome == GoogleAuthOutcome.cancelled) {
      setState(() => _isGoogleLoading = false);
      return;
    }

    if (result.outcome == GoogleAuthOutcome.unavailable || result.idToken == null) {
      setState(() {
        _isGoogleLoading = false;
        _apiError = 'Google sign-in is unavailable right now. Please try again.';
      });
      return;
    }

    final res = await ApiService.loginWithGoogle(result.idToken!, _csrfToken);

    if (!mounted) return;

    if (res['success'] == true && res['needsPhone'] == true) {
      setState(() => _isGoogleLoading = false);
      Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => GooglePhoneCompletionScreen(
            completionToken: res['completionToken'] as String,
            firstName: res['firstName'] as String? ?? '',
          ),
        ),
      );
      return;
    }

    if (res['success'] == true) {
      final role = res['role'] as String? ?? 'Customer';
      Navigator.pushReplacementNamed(context, role == 'Team Leader' ? '/tl-home' : '/home');
      return;
    }

    setState(() {
      _isGoogleLoading = false;
      _apiError = res['message'] as String? ?? 'Google sign-in failed. Please try again.';
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const SizedBox(height: 28),
                Center(
                  child: Text(
                    'TowMate',
                    style: GoogleFonts.inter(
                      color: TmColors.yellow,
                      fontSize: 20,
                      fontWeight: FontWeight.w600,
                      letterSpacing: -0.5,
                    ),
                  ),
                ),
                const SizedBox(height: 32),
                Text(
                  'Welcome back',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 28,
                    fontWeight: FontWeight.w600,
                    letterSpacing: -0.6,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  'Sign in to continue with TowMate.',
                  style: GoogleFonts.inter(
                    color: context.textSecondary,
                    fontSize: 14,
                    letterSpacing: 0.1,
                  ),
                ),
                const SizedBox(height: 24),
                _AuthField(
                  controller: _emailController,
                  label: 'Email',
                  hint: 'Enter your email',
                  keyboardType: TextInputType.emailAddress,
                  validator: Validators.email,
                  textInputAction: TextInputAction.next,
                ),
                const SizedBox(height: 16),
                _AuthField(
                  controller: _passwordController,
                  label: 'Password',
                  hint: 'Enter your password',
                  obscureText: true,
                  autofillEnabled: false,
                  validator: (v) =>
                      v == null || v.isEmpty ? 'Password is required' : null,
                  textInputAction: TextInputAction.done,
                  onFieldSubmitted: (_) => _submit(),
                ),
                Align(
                  alignment: Alignment.centerRight,
                  child: TextButton(
                    onPressed: _onForgotPassword,
                    style: TextButton.styleFrom(
                      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 10),
                      minimumSize: const Size(44, 40),
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    ),
                    child: Text(
                      'Forgot password?',
                      style: GoogleFonts.inter(
                        color: TmColors.yellow,
                        fontSize: 13,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ),
                ),
                if (_apiError != null) ...[
                  const SizedBox(height: 16),
                  _InlineBanner(message: _apiError!, isError: true),
                ],
                if (_rateLocked) ...[
                  const SizedBox(height: 16),
                  _InlineBanner(
                    message:
                        'Too many attempts. Try again in ${_remainingCooldown.inSeconds}s.',
                    isError: false,
                  ),
                ],
                const SizedBox(height: 24),
                _PrimaryButton(
                  label: 'Sign in',
                  isLoading: _isLoading,
                  onPressed: _isLoading ? null : _submit,
                ),
                const SizedBox(height: 24),
                const OrContinueDivider(),
                const SizedBox(height: 16),
                GoogleSignInButton(
                  isLoading: _isGoogleLoading,
                  onPressed: _onGoogleSignIn,
                  onWebResult: _onWebGoogleResult,
                ),
                const SizedBox(height: 24),
                Wrap(
                  alignment: WrapAlignment.center,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Text(
                      "Don't have an account? ",
                      style: GoogleFonts.inter(
                        color: context.textSecondary,
                        fontSize: 14,
                      ),
                    ),
                    GestureDetector(
                      onTap: () => Navigator.pushNamed(context, '/signup'),
                      child: Text(
                        'Create Account',
                        style: GoogleFonts.inter(
                          color: context.textPrimary,
                          fontSize: 14,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 40),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _AuthField extends StatefulWidget {
  const _AuthField({
    required this.controller,
    required this.label,
    required this.hint,
    this.obscureText = false,
    this.autofillEnabled = true,
    this.keyboardType,
    this.validator,
    this.textInputAction,
    this.onFieldSubmitted,
  });

  final TextEditingController controller;
  final String label;
  final String hint;
  final bool obscureText;
  final bool autofillEnabled;
  final TextInputType? keyboardType;
  final String? Function(String?)? validator;
  final TextInputAction? textInputAction;
  final void Function(String)? onFieldSubmitted;

  @override
  State<_AuthField> createState() => _AuthFieldState();
}

class _AuthFieldState extends State<_AuthField> {
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
            color: context.textPrimary,
            fontSize: 14,
            fontWeight: FontWeight.w500,
          ),
        ),
        const SizedBox(height: 8),
        TextFormField(
          controller: widget.controller,
          obscureText: _obscure,
          keyboardType: widget.keyboardType,
          validator: widget.validator,
          textInputAction: widget.textInputAction,
          onFieldSubmitted: widget.onFieldSubmitted,
          autocorrect: !widget.obscureText,
          enableSuggestions: !widget.obscureText,
          autofillHints: widget.autofillEnabled && !widget.obscureText
              ? const [AutofillHints.email]
              : const [],
          style: GoogleFonts.inter(color: context.textPrimary, fontSize: 15),
          decoration: InputDecoration(
            hintText: widget.hint,
            hintStyle: GoogleFonts.inter(color: context.textSecondary, fontSize: 15),
            filled: true,
            fillColor: context.surface,
            suffixIcon: widget.obscureText
                ? GestureDetector(
                    onTap: () => setState(() => _obscure = !_obscure),
                    child: Icon(
                      _obscure ? Icons.visibility : Icons.visibility_off,
                      color: context.textTertiary,
                      size: 20,
                    ),
                  )
                : null,
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: BorderSide(color: context.divider),
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: BorderSide(color: context.divider),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: BorderSide(color: context.textTertiary, width: 1.5),
            ),
            errorBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: const BorderSide(color: TmColors.error, width: 1.5),
            ),
            focusedErrorBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: const BorderSide(color: TmColors.error, width: 1.5),
            ),
            contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 15),
            errorStyle: GoogleFonts.inter(color: TmColors.error, fontSize: 12),
          ),
        ),
      ],
    );
  }
}

class _PrimaryButton extends StatelessWidget {
  const _PrimaryButton({
    required this.label,
    required this.isLoading,
    required this.onPressed,
  });

  final String label;
  final bool isLoading;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      height: 52,
      child: ElevatedButton(
        onPressed: onPressed,
        style: ElevatedButton.styleFrom(
          backgroundColor: TmColors.yellow,
          foregroundColor: TmColors.black,
          disabledBackgroundColor: TmColors.yellow.withValues(alpha: 0.6),
          shape: const StadiumBorder(),
          elevation: 0,
        ),
        child: isLoading
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(color: TmColors.black, strokeWidth: 2),
              )
            : Text(
                label,
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 15,
                  fontWeight: FontWeight.w600,
                  letterSpacing: 0.1,
                ),
              ),
      ),
    );
  }
}

class _InlineBanner extends StatelessWidget {
  const _InlineBanner({required this.message, required this.isError});
  final String message;
  final bool isError;

  @override
  Widget build(BuildContext context) {
    final color = isError ? TmColors.error : context.textTertiary;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: isError ? TmColors.error.withValues(alpha: 0.08) : context.surface,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
        message,
        style: GoogleFonts.inter(color: color, fontSize: 13, letterSpacing: 0.1),
      ),
    );
  }
}
