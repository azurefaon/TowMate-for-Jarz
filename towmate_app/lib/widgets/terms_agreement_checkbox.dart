import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';

class TermsAgreementCheckbox extends StatelessWidget {
  const TermsAgreementCheckbox({
    super.key,
    required this.value,
    required this.onChanged,
    required this.onTermsTap,
    required this.onPrivacyTap,
  });

  final bool value;
  final ValueChanged<bool> onChanged;
  final VoidCallback onTermsTap;
  final VoidCallback onPrivacyTap;

  @override
  Widget build(BuildContext context) {
    final bodyStyle = GoogleFonts.inter(
      color: context.textSecondary,
      fontSize: 13,
      letterSpacing: 0.1,
      height: 1.4,
    );
    final linkStyle = GoogleFonts.inter(
      color: context.textPrimary,
      fontSize: 13,
      fontWeight: FontWeight.w600,
      letterSpacing: 0.1,
      decoration: TextDecoration.underline,
    );

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 22,
          height: 22,
          child: Checkbox(
            value: value,
            onChanged: (v) => onChanged(v ?? false),
            visualDensity: VisualDensity.compact,
            materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
            side: BorderSide(color: context.divider, width: 1.5),
            activeColor: TmColors.black,
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Padding(
            padding: const EdgeInsets.only(top: 3),
            child: Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text('I agree to the ', style: bodyStyle),
                GestureDetector(
                  onTap: onTermsTap,
                  child: Text('Terms of Use', style: linkStyle),
                ),
                Text(' and ', style: bodyStyle),
                GestureDetector(
                  onTap: onPrivacyTap,
                  child: Text('Privacy Policy', style: linkStyle),
                ),
                Text('.', style: bodyStyle),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
