import 'dart:async';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';
import '../services/google_auth_service.dart';
import '../services/google_web_button.dart';
import 'google_mark.dart';

export '../services/google_web_button.dart' show GoogleButtonText;

class GoogleSignInButton extends StatefulWidget {
  const GoogleSignInButton({
    super.key,
    required this.isLoading,
    required this.onPressed,
    this.onWebResult,
    this.label = 'Continue with Google',
    this.webText = GoogleButtonText.continueWith,
  });

  final bool isLoading;
  final VoidCallback? onPressed;
  final ValueChanged<GoogleAuthResult>? onWebResult;
  final String label;
  final GoogleButtonText webText;

  @override
  State<GoogleSignInButton> createState() => _GoogleSignInButtonState();
}

class _GoogleSignInButtonState extends State<GoogleSignInButton> {
  StreamSubscription<GoogleAuthResult>? _subscription;

  @override
  void initState() {
    super.initState();
    if (kIsWeb) {
      GoogleAuthService.ensureInitializedForWeb();
      _subscription = GoogleAuthService.webAuthResults.listen((result) {
        widget.onWebResult?.call(result);
      });
    }
  }

  @override
  void dispose() {
    _subscription?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      height: 52,
      child: widget.isLoading
          ? _LoadingShell()
          : (kIsWeb ? _webButton() : _nativeButton()),
    );
  }

  Widget _webButton() {
    return LayoutBuilder(
      builder: (context, constraints) {
        final width = constraints.maxWidth.isFinite
            ? constraints.maxWidth.clamp(1.0, 400.0)
            : 400.0;
        return Center(
          child: renderGoogleWebButton(
            minimumWidth: width,
            text: widget.webText,
          ),
        );
      },
    );
  }

  Widget _nativeButton() {
    return OutlinedButton(
      onPressed: widget.onPressed,
      style: OutlinedButton.styleFrom(
        backgroundColor: TmColors.white,
        foregroundColor: TmColors.black,
        disabledBackgroundColor: TmColors.white,
        side: const BorderSide(color: TmColors.grey300),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        elevation: 0,
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          const GoogleMark(size: 18),
          const SizedBox(width: 10),
          Flexible(
            child: Text(
              widget.label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: GoogleFonts.inter(
                color: TmColors.black,
                fontSize: 15,
                fontWeight: FontWeight.w600,
                letterSpacing: 0.1,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _LoadingShell extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: TmColors.white,
        border: Border.all(color: TmColors.grey300),
        borderRadius: BorderRadius.circular(14),
      ),
      child: const Center(
        child: SizedBox(
          width: 20,
          height: 20,
          child: CircularProgressIndicator(color: TmColors.black, strokeWidth: 2),
        ),
      ),
    );
  }
}

class OrContinueDivider extends StatelessWidget {
  const OrContinueDivider({super.key, this.label = 'or continue with'});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(child: Divider(color: context.divider, thickness: 1)),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: Text(
            label,
            style: GoogleFonts.inter(color: context.textTertiary, fontSize: 12.5),
          ),
        ),
        Expanded(child: Divider(color: context.divider, thickness: 1)),
      ],
    );
  }
}
