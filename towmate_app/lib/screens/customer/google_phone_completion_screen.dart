import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../core/validators.dart';
import '../../core/security_utils.dart';
import '../../services/api_service.dart';

class GooglePhoneCompletionScreen extends StatefulWidget {
  const GooglePhoneCompletionScreen({
    super.key,
    required this.completionToken,
    required this.firstName,
  });

  final String completionToken;
  final String firstName;

  @override
  State<GooglePhoneCompletionScreen> createState() => _GooglePhoneCompletionScreenState();
}

class _GooglePhoneCompletionScreenState extends State<GooglePhoneCompletionScreen> {
  final _formKey = GlobalKey<FormState>();
  final _phoneController = TextEditingController();
  late final String _csrfToken;

  bool _isLoading = false;
  bool _submitted = false;
  bool _touched = false;
  String? _apiError;

  @override
  void initState() {
    super.initState();
    _csrfToken = CsrfTokenService.generate();
  }

  @override
  void dispose() {
    _phoneController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _submitted = true);
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isLoading = true;
      _apiError = null;
    });

    final phone = PhMobilePhone.toCanonical(
      InputSanitizer.sanitize(_phoneController.text),
    );

    final res = await ApiService.completeGoogleSignup(
      completionToken: widget.completionToken,
      phone: phone,
      csrfToken: _csrfToken,
    );

    if (!mounted) return;

    if (res['success'] == true) {
      Navigator.pushNamedAndRemoveUntil(context, '/home', (route) => false);
    } else {
      setState(() {
        _isLoading = false;
        _apiError = res['message'] as String? ?? 'Could not complete sign-up. Please try again.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final greetingName = widget.firstName.trim().isEmpty ? '' : ' ${widget.firstName.trim()}';

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
                const SizedBox(height: 40),
                Text(
                  'Almost there$greetingName',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 26,
                    fontWeight: FontWeight.w600,
                    letterSpacing: -0.6,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  'We need a Philippine mobile number to finish setting up your TowMate account.',
                  style: GoogleFonts.inter(
                    color: context.textSecondary,
                    fontSize: 14,
                    letterSpacing: 0.1,
                  ),
                ),
                const SizedBox(height: 28),
                Text(
                  'Phone number',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 14,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                const SizedBox(height: 8),
                TextFormField(
                  controller: _phoneController,
                  keyboardType: TextInputType.phone,
                  textInputAction: TextInputAction.done,
                  autovalidateMode: AutovalidateMode.onUserInteraction,
                  inputFormatters: PhMobilePhone.formatters,
                  onChanged: (_) {
                    if (!_touched) setState(() => _touched = true);
                  },
                  onFieldSubmitted: (_) => _submit(),
                  validator: (v) {
                    if (!_touched && !_submitted) return null;
                    return PhMobilePhone.validateLocal(v, showIncomplete: _submitted);
                  },
                  style: GoogleFonts.inter(color: context.textPrimary, fontSize: 15),
                  decoration: InputDecoration(
                    hintText: '917 123 4567',
                    hintStyle: GoogleFonts.inter(color: context.textSecondary, fontSize: 15),
                    filled: true,
                    fillColor: context.surface,
                    prefixIcon: Padding(
                      padding: const EdgeInsets.only(left: 16, right: 12),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text('+63', style: GoogleFonts.inter(color: context.textPrimary, fontSize: 15)),
                          const SizedBox(width: 10),
                          Container(width: 1, height: 18, color: context.divider),
                        ],
                      ),
                    ),
                    prefixIconConstraints: const BoxConstraints(minWidth: 0, minHeight: 0),
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
                if (_apiError != null) ...[
                  const SizedBox(height: 16),
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    decoration: BoxDecoration(
                      color: TmColors.error.withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Text(
                      _apiError!,
                      style: GoogleFonts.inter(color: TmColors.error, fontSize: 13, letterSpacing: 0.1),
                    ),
                  ),
                ],
                const SizedBox(height: 24),
                SizedBox(
                  width: double.infinity,
                  height: 52,
                  child: ElevatedButton(
                    onPressed: _isLoading ? null : _submit,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: TmColors.yellow,
                      foregroundColor: TmColors.black,
                      disabledBackgroundColor: TmColors.yellow.withValues(alpha: 0.6),
                      shape: const StadiumBorder(),
                      elevation: 0,
                    ),
                    child: _isLoading
                        ? const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(color: TmColors.black, strokeWidth: 2),
                          )
                        : Text(
                            'Continue',
                            style: GoogleFonts.inter(
                              color: TmColors.black,
                              fontSize: 15,
                              fontWeight: FontWeight.w600,
                              letterSpacing: 0.1,
                            ),
                          ),
                  ),
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
