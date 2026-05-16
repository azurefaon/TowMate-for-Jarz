import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';

class TlChecklistItem extends StatelessWidget {
  const TlChecklistItem({
    super.key,
    required this.label,
    required this.checked,
    this.onTap,
    this.sublabel,
  });

  final String label;
  final bool checked;
  final VoidCallback? onTap;
  final String? sublabel;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: checked
              ? TmColors.yellow.withValues(alpha: 0.08)
              : TmColors.grey100,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: checked ? TmColors.yellow : TmColors.grey300,
          ),
        ),
        child: Row(
          children: [
            Icon(
              checked ? Icons.check_circle_rounded : Icons.radio_button_unchecked_rounded,
              color: checked ? TmColors.yellow : TmColors.grey500,
              size: 22,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    label,
                    style: GoogleFonts.inter(
                      color: TmColors.black,
                      fontSize: 14,
                    ),
                  ),
                  if (sublabel != null)
                    Text(
                      sublabel!,
                      style: GoogleFonts.inter(
                        color: TmColors.grey500,
                        fontSize: 12,
                      ),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
