import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/app_prefs.dart';
import '../../core/validators.dart';
import '../../core/security_utils.dart';
import '../../services/api_service.dart';
import '../../services/google_auth_service.dart';
import '../../widgets/google_signin_button.dart';
import '../../widgets/password_strength_bar.dart';
import 'email_otp_screen.dart';
import 'google_phone_completion_screen.dart';

const _brand = Color(0xFFF5A623);
const _buttonGradientEnd = Color(0xFFE8960D);
const _fieldBorder = Color(0xFFD1D5DB);
const _fieldBorderFocused = Color(0xFF262626);
const _textSecondary = Color(0xFF9CA3AF);
const _iconMuted = Color(0xFFBBBEC8);
const _textPrimary = Color(0xFF111111);
const _towDark = Color(0xFF1A1040);
const _errorRed = Color(0xFFE53935);

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
  final _emailFieldKey = GlobalKey<FormFieldState<String>>();

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

  void _onEmailBlur() {
    _touch('email');
    _emailFieldKey.currentState?.validate();
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
    if (loggedIn) {
      await AppPrefs.restoreAuthenticatedTheme();
      Navigator.pushReplacementNamed(context, '/home');
    }
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
            res['message'] as String? ??
            'Failed to send OTP. Please try again.';
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
      Navigator.pushReplacementNamed(context, '/home');
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
              padding: const EdgeInsets.fromLTRB(24, 16, 24, 18),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Center(child: _BrandMark()),
                  const SizedBox(height: 22),
                  TextButton(
                    onPressed: _goToLogin,
                    style: TextButton.styleFrom(
                      padding: EdgeInsets.zero,
                      minimumSize: const Size(44, 28),
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      alignment: Alignment.centerLeft,
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(
                          Icons.arrow_back_ios_new_rounded,
                          size: 13,
                          color: _textSecondary,
                        ),
                        const SizedBox(width: 6),
                        Text(
                          'Back to sign in',
                          style: GoogleFonts.inter(
                            color: _textSecondary,
                            fontSize: 13,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 14),
                  Text(
                    'Create your account',
                    style: GoogleFonts.inter(
                      color: _textPrimary,
                      fontSize: 24,
                      fontWeight: FontWeight.w700,
                      letterSpacing: -0.4,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    'Request and track towing services with TowMate',
                    style: GoogleFonts.inter(
                      color: _textSecondary,
                      fontSize: 13,
                    ),
                  ),
                  const SizedBox(height: 20),
                  Form(
                    key: _formKey,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        LayoutBuilder(
                          builder: (context, constraints) {
                            final stack = constraints.maxWidth < 300;
                            final firstField = _Field(
                              controller: _firstNameController,
                              label: 'FIRST NAME',
                              hint: 'Enter your first name',
                              validator: (v) => _gated(
                                'firstName',
                                () => Validators.name(v, 'First name'),
                              ),
                              textInputAction: TextInputAction.next,
                              onChanged: (_) => _touch('firstName'),
                            );
                            final lastField = _Field(
                              controller: _lastNameController,
                              label: 'LAST NAME',
                              hint: 'Enter your last name',
                              validator: (v) => _gated(
                                'lastName',
                                () => Validators.name(v, 'Last name'),
                              ),
                              textInputAction: TextInputAction.next,
                              onChanged: (_) => _touch('lastName'),
                            );
                            if (stack) {
                              return Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  firstField,
                                  const SizedBox(height: 12),
                                  lastField,
                                ],
                              );
                            }
                            return Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Expanded(child: firstField),
                                const SizedBox(width: 10),
                                Expanded(child: lastField),
                              ],
                            );
                          },
                        ),
                        const SizedBox(height: 12),
                        _Field(
                          fieldKey: _emailFieldKey,
                          controller: _emailController,
                          label: 'EMAIL',
                          hint: 'Enter your Gmail address',
                          keyboardType: TextInputType.emailAddress,
                          validator: (v) =>
                              _gated('email', () => Validators.email(v)),
                          textInputAction: TextInputAction.next,
                          onBlur: _onEmailBlur,
                        ),
                        const SizedBox(height: 12),
                        _PhoneField(
                          controller: _phoneController,
                          validator: (v) => _gated(
                            'phone',
                            () => PhMobilePhone.validateLocal(
                              v,
                              showIncomplete: _submitted,
                            ),
                          ),
                          onChanged: (_) => _touch('phone'),
                        ),
                        const SizedBox(height: 12),
                        _Field(
                          controller: _passwordController,
                          label: 'PASSWORD',
                          hint: 'Enter your password',
                          obscureText: true,
                          validator: (v) =>
                              _gated('password', () => Validators.password(v)),
                          textInputAction: TextInputAction.next,
                          onChanged: (v) {
                            _touch('password');
                            setState(() => _passwordValue = v);
                          },
                        ),
                        const SizedBox(height: 6),
                        PasswordStrengthBar(password: _passwordValue),
                        const SizedBox(height: 12),
                        _Field(
                          controller: _confirmPasswordController,
                          label: 'CONFIRM PASSWORD',
                          hint: 'Confirm your password',
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
                          const SizedBox(height: 12),
                          _InlineBanner(message: _apiError!),
                        ],
                      ],
                    ),
                  ),
                  const SizedBox(height: 14),
                  _GradientButton(
                    label: 'Create account',
                    isLoading: _isLoading,
                    onPressed: _isLoading ? null : _submit,
                  ),
                  const _AuthDivider(label: 'or sign up with'),
                  Center(
                    child: GoogleSignInButton(
                      isLoading: _isGoogleLoading,
                      onPressed: _onGoogleSignIn,
                      onWebResult: _onWebGoogleResult,
                      label: 'Sign up with Google',
                      webText: GoogleButtonText.signUp,
                      iconOnly: true,
                    ),
                  ),
                  const SizedBox(height: 20),
                  Center(
                    child: Wrap(
                      alignment: WrapAlignment.center,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text(
                          'Already have an account? ',
                          style: GoogleFonts.inter(
                            color: _textSecondary,
                            fontSize: 13.5,
                          ),
                        ),
                        GestureDetector(
                          onTap: _goToLogin,
                          child: Text(
                            'Sign in',
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

class _Field extends StatefulWidget {
  const _Field({
    required this.controller,
    required this.label,
    required this.hint,
    this.obscureText = false,
    this.keyboardType,
    this.validator,
    this.textInputAction,
    this.onChanged,
    this.onFieldSubmitted,
    this.onBlur,
    this.fieldKey,
  });

  final TextEditingController controller;
  final String label;
  final String hint;
  final bool obscureText;
  final TextInputType? keyboardType;
  final String? Function(String?)? validator;
  final TextInputAction? textInputAction;
  final void Function(String)? onChanged;
  final void Function(String)? onFieldSubmitted;
  final VoidCallback? onBlur;
  final GlobalKey<FormFieldState<String>>? fieldKey;

  @override
  State<_Field> createState() => _FieldState();
}

class _FieldState extends State<_Field> {
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
    final hasFocus = _focusNode.hasFocus;
    if (_focused != hasFocus) {
      final wasFocused = _focused;
      setState(() => _focused = hasFocus);
      if (wasFocused && !hasFocus) {
        widget.onBlur?.call();
      }
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
    return FormField<String>(
      key: widget.fieldKey,
      initialValue: widget.controller.text,
      validator: widget.validator,
      autovalidateMode: AutovalidateMode.onUserInteraction,
      builder: (field) {
        final borderColor = field.hasError
            ? _errorRed
            : (_focused ? _fieldBorderFocused : _fieldBorder);
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              widget.label,
              style: GoogleFonts.inter(
                color: _textPrimary,
                fontSize: 11,
                fontWeight: FontWeight.w600,
                letterSpacing: 1.0,
              ),
            ),
            const SizedBox(height: 6),
            AnimatedContainer(
              duration: const Duration(milliseconds: 150),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: borderColor, width: 1.5),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: widget.controller,
                      focusNode: _focusNode,
                      obscureText: _obscure,
                      keyboardType: widget.keyboardType,
                      textInputAction: widget.textInputAction,
                      onChanged: (v) {
                        field.didChange(v);
                        widget.onChanged?.call(v);
                      },
                      onSubmitted: widget.onFieldSubmitted,
                      autocorrect: !widget.obscureText,
                      enableSuggestions: !widget.obscureText,
                      autofillHints: widget.obscureText ? const [] : null,
                      cursorColor: _fieldBorderFocused,
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
                                onTap: () =>
                                    setState(() => _obscure = !_obscure),
                                child: Icon(
                                  _obscure
                                      ? Icons.visibility_outlined
                                      : Icons.visibility_off_outlined,
                                  color: _obscure
                                      ? _textSecondary
                                      : _fieldBorderFocused,
                                  size: 18,
                                ),
                              )
                            : null,
                        contentPadding: const EdgeInsets.only(
                          left: 16,
                          top: 15,
                          bottom: 15,
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 14),
                ],
              ),
            ),
            if (field.hasError) ...[
              const SizedBox(height: 4),
              Text(
                field.errorText!,
                style: GoogleFonts.inter(color: _errorRed, fontSize: 11.5),
              ),
            ],
          ],
        );
      },
    );
  }
}

class _PhoneField extends StatefulWidget {
  const _PhoneField({required this.controller, this.validator, this.onChanged});

  final TextEditingController controller;
  final String? Function(String?)? validator;
  final void Function(String)? onChanged;

  @override
  State<_PhoneField> createState() => _PhoneFieldState();
}

class _PhoneFieldState extends State<_PhoneField> {
  final _focusNode = FocusNode();
  bool _focused = false;

  @override
  void initState() {
    super.initState();
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
    return FormField<String>(
      initialValue: widget.controller.text,
      validator: widget.validator,
      autovalidateMode: AutovalidateMode.onUserInteraction,
      builder: (field) {
        final borderColor = field.hasError
            ? _errorRed
            : (_focused ? _fieldBorderFocused : _fieldBorder);
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'PHONE NUMBER',
              style: GoogleFonts.inter(
                color: _textPrimary,
                fontSize: 11,
                fontWeight: FontWeight.w600,
                letterSpacing: 1.0,
              ),
            ),
            const SizedBox(height: 6),
            AnimatedContainer(
              duration: const Duration(milliseconds: 150),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: borderColor, width: 1.5),
              ),
              child: Row(
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 14,
                      vertical: 15,
                    ),
                    decoration: const BoxDecoration(
                      border: Border(
                        right: BorderSide(color: _fieldBorder, width: 1.5),
                      ),
                    ),
                    child: Text(
                      '+63',
                      style: GoogleFonts.inter(
                        color: _textPrimary,
                        fontSize: 14,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                  Expanded(
                    child: TextField(
                      controller: widget.controller,
                      focusNode: _focusNode,
                      keyboardType: TextInputType.phone,
                      textInputAction: TextInputAction.next,
                      onChanged: (v) {
                        field.didChange(v);
                        widget.onChanged?.call(v);
                      },
                      inputFormatters: PhMobilePhone.formatters,
                      cursorColor: _fieldBorderFocused,
                      style: GoogleFonts.inter(
                        color: _textPrimary,
                        fontSize: 14,
                        fontWeight: FontWeight.w500,
                      ),
                      decoration: InputDecoration(
                        hintText: 'Enter your phone number',
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
                        contentPadding: const EdgeInsets.symmetric(
                          horizontal: 14,
                          vertical: 15,
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            if (field.hasError) ...[
              const SizedBox(height: 4),
              Text(
                field.errorText!,
                style: GoogleFonts.inter(color: _errorRed, fontSize: 11.5),
              ),
            ],
          ],
        );
      },
    );
  }
}

class _AuthDivider extends StatelessWidget {
  const _AuthDivider({required this.label});
  final String label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 18),
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
  const _InlineBanner({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
      decoration: BoxDecoration(
        color: _errorRed.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
        message,
        style: GoogleFonts.inter(
          color: _errorRed,
          fontSize: 12.5,
          letterSpacing: 0.1,
        ),
      ),
    );
  }
}
