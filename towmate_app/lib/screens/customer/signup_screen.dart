import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show TextInputFormatter;
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../core/validators.dart';
import '../../core/security_utils.dart';
import '../../services/api_service.dart';
import '../../services/google_auth_service.dart';
import '../../widgets/google_signin_button.dart';
import '../../widgets/password_strength_bar.dart';
import 'email_otp_screen.dart';
import 'google_phone_completion_screen.dart';

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
  bool _isGoogleLoading = false;
  String? _apiError;
  String _passwordValue = '';
  bool _submitted = false;
  final Set<String> _touched = {};
  late String _csrfToken;

  void _touch(String field) {
    if (_touched.add(field)) setState(() {});
  }

  String? _gated(String field, String? Function() validate) {
    if (!_submitted && !_touched.contains(field)) return null;
    return validate();
  }

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
    setState(() => _submitted = true);
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
      Navigator.pushReplacementNamed(context, '/home');
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
          padding: const EdgeInsets.fromLTRB(24, 18, 24, 18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.only(left: 4),
                child: TextButton(
                  onPressed: _goToLogin,
                  style: TextButton.styleFrom(
                    padding: EdgeInsets.zero,
                    minimumSize: const Size(44, 36),
                    tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    alignment: Alignment.centerLeft,
                  ),
                  child: Text(
                    '← Back to sign in',
                    style: GoogleFonts.inter(
                      color: context.textTertiary,
                      fontSize: 13.5,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              Text(
                'Create your account',
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 26,
                  fontWeight: FontWeight.w600,
                  letterSpacing: -0.6,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                'Request and track towing services with TowMate.',
                style: GoogleFonts.inter(
                  color: context.textSecondary,
                  fontSize: 14,
                  letterSpacing: 0.1,
                ),
              ),
              const SizedBox(height: 18),
              Form(
                key: _formKey,
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
                            validator: (v) =>
                                _gated('firstName', () => Validators.name(v, 'First name')),
                            textInputAction: TextInputAction.next,
                            onChanged: (_) => _touch('firstName'),
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: _Field(
                            controller: _lastNameController,
                            label: 'Last name',
                            validator: (v) =>
                                _gated('lastName', () => Validators.name(v, 'Last name')),
                            textInputAction: TextInputAction.next,
                            onChanged: (_) => _touch('lastName'),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 14),
                    _Field(
                      controller: _emailController,
                      label: 'Email',
                      keyboardType: TextInputType.emailAddress,
                      validator: (v) => _gated('email', () => Validators.email(v)),
                      textInputAction: TextInputAction.next,
                      onChanged: (_) => _touch('email'),
                    ),
                    const SizedBox(height: 14),
                    _Field(
                      controller: _phoneController,
                      label: 'Phone number',
                      keyboardType: TextInputType.phone,
                      fixedPrefix: '+63',
                      hintText: '917 123 4567',
                      inputFormatters: PhMobilePhone.formatters,
                      validator: (v) => _gated(
                        'phone',
                        () => PhMobilePhone.validateLocal(v, showIncomplete: _submitted),
                      ),
                      textInputAction: TextInputAction.next,
                      onChanged: (_) => _touch('phone'),
                    ),
                    const SizedBox(height: 14),
                    _Field(
                      controller: _passwordController,
                      label: 'Password',
                      obscureText: true,
                      validator: (v) => _gated('password', () => Validators.password(v)),
                      textInputAction: TextInputAction.next,
                      onChanged: (v) {
                        _touch('password');
                        setState(() => _passwordValue = v);
                      },
                    ),
                    const SizedBox(height: 6),
                    PasswordStrengthBar(password: _passwordValue),
                    const SizedBox(height: 14),
                    _Field(
                      controller: _confirmPasswordController,
                      label: 'Confirm password',
                      obscureText: true,
                      validator: (v) => _gated(
                        'confirmPassword',
                        () => Validators.confirmPassword(v, _passwordValue),
                      ),
                      textInputAction: TextInputAction.done,
                      onChanged: (_) => _touch('confirmPassword'),
                      onFieldSubmitted: (_) => _submit(),
                    ),
                    if (_apiError != null) ...[
                      const SizedBox(height: 16),
                      _InlineBanner(message: _apiError!),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 18),
              SizedBox(
                width: double.infinity,
                height: 52,
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
                            fontSize: 15,
                            fontWeight: FontWeight.w600,
                            letterSpacing: 0.1,
                          ),
                        ),
                ),
              ),
              const SizedBox(height: 18),
              const OrContinueDivider(label: 'or sign up with'),
              const SizedBox(height: 14),
              GoogleSignInButton(
                isLoading: _isGoogleLoading,
                onPressed: _onGoogleSignIn,
                onWebResult: _onWebGoogleResult,
                label: 'Sign up with Google',
                webText: GoogleButtonText.signUp,
              ),
              const SizedBox(height: 18),
              Wrap(
                alignment: WrapAlignment.center,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Text(
                    'Already have an account? ',
                    style: GoogleFonts.inter(
                      color: context.textSecondary,
                      fontSize: 14,
                    ),
                  ),
                  GestureDetector(
                    onTap: _goToLogin,
                    child: Text(
                      'Sign in',
                      style: GoogleFonts.inter(
                        color: context.textPrimary,
                        fontSize: 14,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
            ],
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
    this.obscureText = false,
    this.keyboardType,
    this.validator,
    this.textInputAction,
    this.onChanged,
    this.onFieldSubmitted,
    this.fixedPrefix,
    this.hintText,
    this.inputFormatters,
  });

  final TextEditingController controller;
  final String label;
  final bool obscureText;
  final TextInputType? keyboardType;
  final String? Function(String?)? validator;
  final TextInputAction? textInputAction;
  final void Function(String)? onChanged;
  final void Function(String)? onFieldSubmitted;
  final String? fixedPrefix;
  final String? hintText;
  final List<TextInputFormatter>? inputFormatters;

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
          autovalidateMode: AutovalidateMode.onUserInteraction,
          textInputAction: widget.textInputAction,
          onChanged: widget.onChanged,
          onFieldSubmitted: widget.onFieldSubmitted,
          inputFormatters: widget.inputFormatters,
          autocorrect: !widget.obscureText,
          enableSuggestions: !widget.obscureText,
          autofillHints: widget.obscureText ? const [] : null,
          style: GoogleFonts.inter(color: context.textPrimary, fontSize: 15),
          decoration: InputDecoration(
            hintText: widget.hintText ?? widget.label,
            hintStyle: GoogleFonts.inter(color: context.textSecondary, fontSize: 15),
            filled: true,
            fillColor: context.surface,
            prefixIcon: widget.fixedPrefix == null
                ? null
                : Padding(
                    padding: const EdgeInsets.only(left: 16, right: 12),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          widget.fixedPrefix!,
                          style: GoogleFonts.inter(color: context.textPrimary, fontSize: 15),
                        ),
                        const SizedBox(width: 10),
                        Container(width: 1, height: 18, color: context.divider),
                      ],
                    ),
                  ),
            prefixIconConstraints: widget.fixedPrefix == null
                ? null
                : const BoxConstraints(minWidth: 0, minHeight: 0),
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

class _InlineBanner extends StatelessWidget {
  const _InlineBanner({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: TmColors.error.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
        message,
        style: GoogleFonts.inter(color: TmColors.error, fontSize: 13, letterSpacing: 0.1),
      ),
    );
  }
}
