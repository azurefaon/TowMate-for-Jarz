import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/app_prefs.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';
import '../../widgets/terms_agreement_checkbox.dart';
import 'privacy_policy_screen.dart';
import 'terms_of_use_screen.dart';

class TermsAcceptanceScreen extends StatefulWidget {
  const TermsAcceptanceScreen({super.key});

  @override
  State<TermsAcceptanceScreen> createState() => _TermsAcceptanceScreenState();
}

class _TermsAcceptanceScreenState extends State<TermsAcceptanceScreen> {
  bool _agreed = false;
  bool _isLoading = false;
  bool _isLoggingOut = false;
  String? _error;

  void _openTerms() {
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const TermsOfUseScreen()),
    );
  }

  void _openPrivacy() {
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const PrivacyPolicyScreen()),
    );
  }

  Future<void> _continue() async {
    if (!_agreed || _isLoading) return;
    setState(() {
      _isLoading = true;
      _error = null;
    });

    final res = await ApiService.acceptTerms();
    if (!mounted) return;

    if (res['success'] == true) {
      await AppPrefs.restoreAuthenticatedTheme();
      if (!mounted) return;
      Navigator.pushNamedAndRemoveUntil(context, '/home', (_) => false);
    } else {
      setState(() {
        _isLoading = false;
        _error =
            res['message'] as String? ??
            'Could not save your acceptance. Please try again.';
      });
    }
  }

  Future<void> _logout() async {
    if (_isLoggingOut) return;
    setState(() => _isLoggingOut = true);
    await ApiService.clearSession();
    AppPrefs.useGuestTheme();
    if (!mounted) return;
    Navigator.pushNamedAndRemoveUntil(context, '/login', (_) => false);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 440),
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(24, 40, 24, 24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Terms & Privacy',
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 24,
                      fontWeight: FontWeight.w700,
                      letterSpacing: -0.4,
                    ),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    'Before continuing, please review and accept the current '
                    'Terms of Use and Privacy Policy.',
                    style: GoogleFonts.inter(
                      color: context.textSecondary,
                      fontSize: 14,
                      height: 1.5,
                    ),
                  ),
                  const SizedBox(height: 28),
                  _DocumentLinkButton(
                    label: 'Read Terms of Use',
                    onTap: _openTerms,
                  ),
                  const SizedBox(height: 10),
                  _DocumentLinkButton(
                    label: 'Read Privacy Policy',
                    onTap: _openPrivacy,
                  ),
                  const SizedBox(height: 24),
                  TermsAgreementCheckbox(
                    value: _agreed,
                    onChanged: (v) => setState(() => _agreed = v),
                    onTermsTap: _openTerms,
                    onPrivacyTap: _openPrivacy,
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 16),
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.symmetric(
                        horizontal: 14,
                        vertical: 11,
                      ),
                      decoration: BoxDecoration(
                        color: TmColors.error.withValues(alpha: 0.08),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Text(
                        _error!,
                        style: GoogleFonts.inter(
                          color: TmColors.error,
                          fontSize: 12.5,
                        ),
                      ),
                    ),
                  ],
                  const SizedBox(height: 28),
                  SizedBox(
                    width: double.infinity,
                    height: 52,
                    child: ElevatedButton(
                      onPressed: (_agreed && !_isLoading) ? _continue : null,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: TmColors.yellow,
                        foregroundColor: TmColors.black,
                        disabledBackgroundColor: TmColors.grey300,
                        disabledForegroundColor: TmColors.grey700,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(16),
                        ),
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
                              'Continue',
                              style: GoogleFonts.inter(
                                fontSize: 15,
                                fontWeight: FontWeight.w700,
                                letterSpacing: 0.1,
                              ),
                            ),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Center(
                    child: TextButton(
                      onPressed: _isLoggingOut ? null : _logout,
                      child: Text(
                        'Log out instead',
                        style: GoogleFonts.inter(
                          color: context.textSecondary,
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
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

class _DocumentLinkButton extends StatelessWidget {
  const _DocumentLinkButton({required this.label, required this.onTap});

  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          border: Border.all(color: context.divider),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              label,
              style: GoogleFonts.inter(
                color: context.textPrimary,
                fontSize: 14,
                fontWeight: FontWeight.w600,
              ),
            ),
            Icon(
              Icons.chevron_right,
              color: context.textTertiary,
              size: 20,
            ),
          ],
        ),
      ),
    );
  }
}
