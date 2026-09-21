import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/app_prefs.dart';
import '../../core/validators.dart';
import '../../core/security_utils.dart';
import '../../services/api_service.dart';
import '../../services/google_auth_service.dart';
import '../../services/tl_presence_controller.dart';
import '../../widgets/google_signin_button.dart';
import 'forgot_password_screen.dart';
import 'google_phone_completion_screen.dart';

const _brand = Color(0xFFF5A623);
const _buttonGradientEnd = Color(0xFFE8960D);
const _fieldBgNormal = Color(0xFFF7F8FA);
const _fieldBgFocused = Color(0xFFFFFDF7);
const _fieldBorder = Color(0xFFECEEF2);
const _textSecondary = Color(0xFF9CA3AF);
const _iconMuted = Color(0xFFBBBEC8);
const _textPrimary = Color(0xFF111111);
const _towDark = Color(0xFF1A1040);
const _errorRed = Color(0xFFE53935);

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
    await AppPrefs.restoreAuthenticatedTheme();
    final role = await ApiService.getUserRole();
    if (!mounted) return;
    if (role == 'Team Leader') TlPresenceController.start();
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
      await AppPrefs.restoreAuthenticatedTheme();
      final role = res['role'] as String? ?? 'Customer';
      final mustChange = res['must_change_password'] == true;
      final route = role == 'Team Leader'
          ? (mustChange ? '/tl-force-password' : '/tl-home')
          : '/home';
      if (role == 'Team Leader') TlPresenceController.start();
      Navigator.pushReplacementNamed(context, route);
    } else {
      RateLimiter.recordFailure();
      final locked = RateLimiter.isLocked;
      if (locked) _startCooldownTimer();
      setState(() {
        _isLoading = false;
        _apiError =
            res['message'] as String? ??
            'Invalid credentials. Please try again.';
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

    if (result.outcome == GoogleAuthOutcome.unavailable ||
        result.idToken == null) {
      setState(() {
        _isGoogleLoading = false;
        _apiError =
            'Google sign-in is unavailable right now. Please try again.';
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
      await AppPrefs.restoreAuthenticatedTheme();
      final role = res['role'] as String? ?? 'Customer';
      if (role == 'Team Leader') TlPresenceController.start();
      Navigator.pushReplacementNamed(
        context,
        role == 'Team Leader' ? '/tl-home' : '/home',
      );
      return;
    }

    setState(() {
      _isGoogleLoading = false;
      _apiError =
          res['message'] as String? ??
          'Google sign-in failed. Please try again.';
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.white,
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 440),
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(24, 20, 24, 20),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Center(child: _BrandMark()),
                    const SizedBox(height: 30),
                    Text(
                      'Welcome back',
                      style: GoogleFonts.inter(
                        color: _textPrimary,
                        fontSize: 26,
                        fontWeight: FontWeight.w700,
                        letterSpacing: -0.4,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      'Sign in to continue with TowMate',
                      style: GoogleFonts.inter(
                        color: _textSecondary,
                        fontSize: 13.5,
                      ),
                    ),
                    const SizedBox(height: 24),
                    _AuthField(
                      controller: _emailController,
                      label: 'EMAIL',
                      hint: 'example@gmail.com',
                      keyboardType: TextInputType.emailAddress,
                      validator: Validators.email,
                      textInputAction: TextInputAction.next,
                    ),
                    const SizedBox(height: 14),
                    _AuthField(
                      controller: _passwordController,
                      label: 'PASSWORD',
                      hint: '••••••••',
                      obscureText: true,
                      autofillEnabled: false,
                      validator: (v) => v == null || v.isEmpty
                          ? 'Password is required'
                          : null,
                      textInputAction: TextInputAction.done,
                      onFieldSubmitted: (_) => _submit(),
                    ),
                    const SizedBox(height: 8),
                    Align(
                      alignment: Alignment.centerRight,
                      child: TextButton(
                        onPressed: _onForgotPassword,
                        style: TextButton.styleFrom(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 4,
                            vertical: 6,
                          ),
                          minimumSize: const Size(44, 32),
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                        ),
                        child: Text(
                          'Forgot password?',
                          style: GoogleFonts.inter(
                            color: _brand,
                            fontSize: 12.5,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ),
                    if (_apiError != null) ...[
                      const SizedBox(height: 8),
                      _InlineBanner(message: _apiError!, isError: true),
                    ],
                    if (_rateLocked) ...[
                      const SizedBox(height: 8),
                      _InlineBanner(
                        message:
                            'Too many attempts. Try again in ${_remainingCooldown.inSeconds}s.',
                        isError: false,
                      ),
                    ],
                    const SizedBox(height: 10),
                    _GradientButton(
                      label: 'Sign in',
                      isLoading: _isLoading,
                      onPressed: _isLoading ? null : _submit,
                    ),
                    const _AuthDivider(label: 'or continue with'),
                    Center(
                      child: GoogleSignInButton(
                        isLoading: _isGoogleLoading,
                        onPressed: _onGoogleSignIn,
                        onWebResult: _onWebGoogleResult,
                        iconOnly: true,
                      ),
                    ),
                    const SizedBox(height: 26),
                    Center(
                      child: Wrap(
                        alignment: WrapAlignment.center,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          Text(
                            "Don't have an account? ",
                            style: GoogleFonts.inter(
                              color: _textSecondary,
                              fontSize: 13.5,
                            ),
                          ),
                          GestureDetector(
                            onTap: () =>
                                Navigator.pushNamed(context, '/signup'),
                            child: Text(
                              'Create Account',
                              style: GoogleFonts.inter(
                                color: _brand,
                                fontSize: 13.5,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _BrandMark extends StatelessWidget {
  const _BrandMark();

  @override
  Widget build(BuildContext context) {
    return RichText(
      text: TextSpan(
        children: [
          TextSpan(
            text: 'Tow',
            style: GoogleFonts.inter(
              color: _towDark,
              fontSize: 22,
              fontWeight: FontWeight.w700,
              letterSpacing: -0.3,
            ),
          ),
          TextSpan(
            text: 'Mate',
            style: GoogleFonts.inter(
              color: _brand,
              fontSize: 22,
              fontWeight: FontWeight.w700,
              letterSpacing: -0.3,
            ),
          ),
        ],
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
  final _focusNode = FocusNode();
  bool _focused = false;

  @override
  void initState() {
    super.initState();
    _obscure = widget.obscureText;
    _focusNode.addListener(_onFocusChange);
  }

  void _onFocusChange() {
    if (_focused != _focusNode.hasFocus) {
      setState(() => _focused = _focusNode.hasFocus);
    }
  }

  @override
  void dispose() {
    _focusNode.removeListener(_onFocusChange);
    _focusNode.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          widget.label,
          style: GoogleFonts.inter(
            color: _focused ? _brand : _textSecondary,
            fontSize: 11,
            fontWeight: FontWeight.w600,
            letterSpacing: 1.0,
          ),
        ),
        const SizedBox(height: 6),
        AnimatedContainer(
          duration: const Duration(milliseconds: 150),
          decoration: BoxDecoration(
            color: _focused ? _fieldBgFocused : _fieldBgNormal,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(
              color: _focused ? _brand : _fieldBorder,
              width: 1.5,
            ),
            boxShadow: _focused
                ? [
                    BoxShadow(
                      color: _brand.withValues(alpha: 0.1),
                      blurRadius: 0,
                      spreadRadius: 4,
                    ),
                  ]
                : null,
          ),
          child: Row(
            children: [
              Expanded(
                child: TextFormField(
                  controller: widget.controller,
                  focusNode: _focusNode,
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
                  cursorColor: _brand,
                  style: GoogleFonts.inter(
                    color: _textPrimary,
                    fontSize: 14,
                    fontWeight: FontWeight.w500,
                  ),
                  decoration: InputDecoration(
                    hintText: widget.hint,
                    hintStyle: GoogleFonts.inter(
                      color: _iconMuted,
                      fontSize: 14,
                      fontWeight: FontWeight.w500,
                    ),
                    filled: false,
                    isDense: true,
                    border: InputBorder.none,
                    enabledBorder: InputBorder.none,
                    focusedBorder: InputBorder.none,
                    errorBorder: InputBorder.none,
                    focusedErrorBorder: InputBorder.none,
                    suffixIcon: widget.obscureText
                        ? GestureDetector(
                            onTap: () => setState(() => _obscure = !_obscure),
                            child: Icon(
                              _obscure
                                  ? Icons.visibility_outlined
                                  : Icons.visibility_off_outlined,
                              color: _obscure ? _textSecondary : _brand,
                              size: 18,
                            ),
                          )
                        : null,
                    contentPadding: const EdgeInsets.only(
                      left: 16,
                      top: 15,
                      bottom: 15,
                    ),
                    errorStyle: GoogleFonts.inter(
                      color: _errorRed,
                      fontSize: 11.5,
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 14),
            ],
          ),
        ),
      ],
    );
  }
}

class _AuthDivider extends StatelessWidget {
  const _AuthDivider({required this.label});
  final String label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 20),
      child: Row(
        children: [
          const Expanded(child: Divider(color: _fieldBorder, thickness: 1)),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Text(
              label,
              style: GoogleFonts.inter(color: _iconMuted, fontSize: 12),
            ),
          ),
          const Expanded(child: Divider(color: _fieldBorder, thickness: 1)),
        ],
      ),
    );
  }
}

class _GradientButton extends StatelessWidget {
  const _GradientButton({
    required this.label,
    required this.isLoading,
    required this.onPressed,
  });

  final String label;
  final bool isLoading;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      height: 52,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [_brand, _buttonGradientEnd],
        ),
        boxShadow: [
          BoxShadow(
            color: _brand.withValues(alpha: 0.32),
            blurRadius: 20,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: onPressed,
          child: Center(
            child: isLoading
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(
                      color: Colors.white,
                      strokeWidth: 2,
                    ),
                  )
                : Text(
                    label,
                    style: GoogleFonts.inter(
                      color: Colors.white,
                      fontSize: 15,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 0.1,
                    ),
                  ),
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
    final color = isError ? _errorRed : _textSecondary;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
      decoration: BoxDecoration(
        color: isError ? _errorRed.withValues(alpha: 0.08) : _fieldBgNormal,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
        message,
        style: GoogleFonts.inter(
          color: color,
          fontSize: 12.5,
          letterSpacing: 0.1,
        ),
      ),
    );
  }
}
