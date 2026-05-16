import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../core/validators.dart';
import '../../core/security_utils.dart';
import '../../services/api_service.dart';
import 'forgot_password_screen.dart';

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
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                const SizedBox(height: 72),

                // ── Two-tone title ────────────────────────
                RichText(
                  text: TextSpan(
                    children: [
                      TextSpan(
                        text: 'Tow',
                        style: GoogleFonts.inter(
                          color: TmColors.black,
                          fontSize: 34,
                          letterSpacing: -0.8,
                        ),
                      ),
                      TextSpan(
                        text: 'Mate',
                        style: GoogleFonts.inter(
                          color: TmColors.yellow,
                          fontSize: 34,
                          letterSpacing: -0.8,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  'Your Tow, Our Mission.',
                  style: GoogleFonts.inter(
                    color: TmColors.grey500,
                    fontSize: 14,
                    letterSpacing: 0.2,
                  ),
                ),
                const SizedBox(height: 48),

                // ── Email field ───────────────────────────
                _LabeledField(
                  controller: _emailController,
                  label: 'Email Address',
                  hint: 'Enter your email',
                  prefixIconData: Icons.mail_outline_rounded,
                  keyboardType: TextInputType.emailAddress,
                  validator: Validators.email,
                  textInputAction: TextInputAction.next,
                ),
                const SizedBox(height: 20),

                // ── Password field ────────────────────────
                _LabeledField(
                  controller: _passwordController,
                  label: 'Password',
                  hint: 'Enter your password',
                  prefixIconData: Icons.lock_outline_rounded,
                  obscureText: true,
                  autofillEnabled: false,
                  validator: (v) =>
                      v == null || v.isEmpty ? 'Password is required' : null,
                  textInputAction: TextInputAction.done,
                  onFieldSubmitted: (_) => _submit(),
                ),
                const SizedBox(height: 10),

                // ── Forgot password ───────────────────────
                Align(
                  alignment: Alignment.centerRight,
                  child: GestureDetector(
                    onTap: _onForgotPassword,
                    child: Text(
                      'Forgot Password?',
                      style: GoogleFonts.inter(
                        color: TmColors.yellow,
                        fontSize: 13,
                        letterSpacing: 0.1,
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 20),

                // ── Error banners ─────────────────────────
                if (_apiError != null) ...[
                  _ErrorBanner(message: _apiError!),
                  const SizedBox(height: 12),
                ],
                if (_rateLocked) ...[
                  _RateLockBanner(remaining: _remainingCooldown),
                  const SizedBox(height: 12),
                ],

                // ── Login button ──────────────────────────
                _LoginButton(
                  isLoading: _isLoading,
                  onPressed: _isLoading ? null : _submit,
                ),
                const SizedBox(height: 32),

                // ── Or divider ────────────────────────────
                const _OrDivider(),
                const SizedBox(height: 28),

                // ── Sign up link ──────────────────────────
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Text(
                      "Don't have an account?  ",
                      style: GoogleFonts.inter(
                        color: TmColors.grey700,
                        fontSize: 14,
                      ),
                    ),
                    GestureDetector(
                      onTap: () =>
                          Navigator.pushNamed(context, '/signup'),
                      child: Text(
                        'Sign up',
                        style: GoogleFonts.inter(
                          color: TmColors.yellow,
                          fontSize: 14,
                          decoration: TextDecoration.underline,
                          decorationColor: TmColors.yellow,
                        ),
                      ),
                    ),
                  ],
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

// ─── Labeled pill field ────────────────────────────────────────────────────

class _LabeledField extends StatefulWidget {
  const _LabeledField({
    required this.controller,
    required this.label,
    required this.hint,
    required this.prefixIconData,
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
  final IconData prefixIconData;
  final bool obscureText;
  final bool autofillEnabled;
  final TextInputType? keyboardType;
  final String? Function(String?)? validator;
  final TextInputAction? textInputAction;
  final void Function(String)? onFieldSubmitted;

  @override
  State<_LabeledField> createState() => _LabeledFieldState();
}

class _LabeledFieldState extends State<_LabeledField> {
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
            onFieldSubmitted: widget.onFieldSubmitted,
            autocorrect: !widget.obscureText,
            enableSuggestions: !widget.obscureText,
            autofillHints: widget.autofillEnabled && !widget.obscureText
                ? const [AutofillHints.email]
                : const [],
            style: GoogleFonts.inter(
              color: TmColors.black,
              fontSize: 14,
            ),
            decoration: InputDecoration(
              hintText: widget.hint,
              hintStyle: GoogleFonts.inter(
                color: TmColors.grey500,
                fontSize: 14,
              ),
              filled: true,
              fillColor: TmColors.grey100,
              prefixIcon: Icon(
                widget.prefixIconData,
                color: TmColors.yellow,
                size: 20,
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
              contentPadding: const EdgeInsets.symmetric(
                  horizontal: 20, vertical: 16),
              errorStyle: GoogleFonts.inter(
                color: TmColors.error,
                fontSize: 12,
              ),
            ),
          ),
        ),
      ],
    );
  }
}

// ─── Yellow pill login button ───────────────────────────────────────────────

class _LoginButton extends StatelessWidget {
  const _LoginButton({
    required this.isLoading,
    required this.onPressed,
  });

  final bool isLoading;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      height: 56,
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
                child: CircularProgressIndicator(
                  color: TmColors.black,
                  strokeWidth: 2,
                ),
              )
            : Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Icon(
                    Icons.arrow_forward_rounded,
                    color: TmColors.black,
                    size: 20,
                  ),
                  const SizedBox(width: 8),
                  Text(
                    'Login',
                    style: GoogleFonts.inter(
                      color: TmColors.black,
                      fontSize: 16,
                      letterSpacing: 0.2,
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}

// ─── Or divider ────────────────────────────────────────────────────────────

class _OrDivider extends StatelessWidget {
  const _OrDivider();

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        const Expanded(child: Divider(color: TmColors.grey300)),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Text(
            'or',
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 13,
              letterSpacing: 0.2,
            ),
          ),
        ),
        const Expanded(child: Divider(color: TmColors.grey300)),
      ],
    );
  }
}

// ─── Error / rate lock banners ─────────────────────────────────────────────

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
        border: const Border(
          left: BorderSide(color: TmColors.error, width: 3),
        ),
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

class _RateLockBanner extends StatelessWidget {
  const _RateLockBanner({required this.remaining});
  final Duration remaining;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: TmColors.grey100,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: TmColors.grey300),
      ),
      child: Text(
        'Too many attempts. Try again in ${remaining.inSeconds}s.',
        style: GoogleFonts.inter(
          color: TmColors.grey700,
          fontSize: 13,
          letterSpacing: 0.1,
        ),
      ),
    );
  }
}
