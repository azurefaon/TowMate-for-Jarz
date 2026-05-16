import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';

class TmButton extends StatelessWidget {
  const TmButton._({
    required this.label,
    required this.onPressed,
    required this.backgroundColor,
    required this.foregroundColor,
    this.border,
    this.fontSize = 15,
  });

  final String label;
  final VoidCallback? onPressed;
  final Color backgroundColor;
  final Color foregroundColor;
  final BorderSide? border;
  final double fontSize;

  factory TmButton.yellowPrimary(String label, VoidCallback? onPressed) =>
      TmButton._(
        label: label,
        onPressed: onPressed,
        backgroundColor: TmColors.yellow,
        foregroundColor: TmColors.black,
      );

  factory TmButton.ghost(String label, VoidCallback? onPressed) =>
      TmButton._(
        label: label,
        onPressed: onPressed,
        backgroundColor: Colors.transparent,
        foregroundColor: TmColors.white,
        border: const BorderSide(color: TmColors.grey700, width: 1.5),
      );

  factory TmButton.text(String label, VoidCallback? onPressed) =>
      TmButton._(
        label: label,
        onPressed: onPressed,
        backgroundColor: Colors.transparent,
        foregroundColor: TmColors.grey700,
        fontSize: 14,
      );

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      height: 52,
      child: OutlinedButton(
        onPressed: onPressed,
        style: OutlinedButton.styleFrom(
          backgroundColor: backgroundColor,
          foregroundColor: foregroundColor,
          side: border ?? BorderSide.none,
          shape: const StadiumBorder(),
          elevation: 0,
        ),
        child: Text(
          label,
          style: GoogleFonts.inter(
            color: foregroundColor,
            fontSize: fontSize,
            letterSpacing: 0.1,
          ),
        ),
      ),
    );
  }
}
