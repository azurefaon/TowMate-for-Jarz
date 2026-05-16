import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';

class PasswordStrengthBar extends StatelessWidget {
  const PasswordStrengthBar({super.key, required this.password});
  final String password;

  int get _strength {
    if (password.isEmpty) return 0;
    int score = 0;
    if (password.length >= 8) score++;
    if (password.length >= 12) score++;
    if (RegExp(r'[A-Z]').hasMatch(password)) score++;
    if (RegExp(r'[0-9]').hasMatch(password)) score++;
    if (RegExp(r'[^A-Za-z0-9]').hasMatch(password)) score++;
    return score;
  }

  Color get _color {
    return switch (_strength) {
      <= 1 => TmColors.error,
      2 => Colors.orange,
      3 => Colors.amber,
      _ => TmColors.success,
    };
  }

  String get _label {
    return switch (_strength) {
      0 => '',
      1 => 'Very weak',
      2 => 'Weak',
      3 => 'Fair',
      4 => 'Strong',
      _ => 'Very strong',
    };
  }

  @override
  Widget build(BuildContext context) {
    if (password.isEmpty) return const SizedBox.shrink();
    final filled = (_strength / 5).clamp(0.0, 1.0);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: filled,
            backgroundColor: TmColors.grey300,
            valueColor: AlwaysStoppedAnimation<Color>(_color),
            minHeight: 4,
          ),
        ),
        if (_label.isNotEmpty) ...[
          const SizedBox(height: 4),
          Text(
            _label,
            style: GoogleFonts.inter(
              color: _color,
              fontSize: 11,
              letterSpacing: 0.2,
            ),
          ),
        ],
      ],
    );
  }
}
