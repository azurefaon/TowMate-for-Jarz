import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';
import 'tm_button.dart';

Future<void> showAuthGateSheet(
  BuildContext context, {
  String title = 'Sign in to request towing',
  String message =
      'Create an account or sign in to continue with your request.',
}) {
  return showModalBottomSheet(
    context: context,
    backgroundColor: Colors.transparent,
    isScrollControlled: true,
    builder: (sheetContext) => _AuthGateSheet(title: title, message: message),
  );
}

class _AuthGateSheet extends StatelessWidget {
  const _AuthGateSheet({required this.title, required this.message});

  final String title;
  final String message;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Container(
        margin: const EdgeInsets.fromLTRB(12, 0, 12, 12),
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 20),
        decoration: BoxDecoration(
          color: context.card,
          borderRadius: BorderRadius.circular(20),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Center(
              child: Container(
                width: 36,
                height: 4,
                margin: const EdgeInsets.only(bottom: 18),
                decoration: BoxDecoration(
                  color: context.divider,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
            Text(
              title,
              style: GoogleFonts.inter(
                color: context.textPrimary,
                fontSize: 18,
                fontWeight: FontWeight.w600,
                letterSpacing: -0.3,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              message,
              style: GoogleFonts.inter(
                color: context.textSecondary,
                fontSize: 13.5,
                height: 1.5,
              ),
            ),
            const SizedBox(height: 20),
            TmButton.yellowPrimary('Login', () {
              Navigator.pop(context);
              Navigator.pushNamed(context, '/login');
            }),
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              height: 52,
              child: OutlinedButton(
                onPressed: () {
                  Navigator.pop(context);
                  Navigator.pushNamed(context, '/signup');
                },
                style: OutlinedButton.styleFrom(
                  backgroundColor: Colors.transparent,
                  foregroundColor: context.textPrimary,
                  side: BorderSide(color: context.divider, width: 1.5),
                  shape: const StadiumBorder(),
                  elevation: 0,
                ),
                child: Text(
                  'Create Account',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 15,
                    letterSpacing: 0.1,
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
