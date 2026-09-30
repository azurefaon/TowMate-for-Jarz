import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/status_style.dart';

/// Solid status pill. The only widget that should draw a status chip; colours
/// come from [StatusStyle] and follow the current theme brightness.
///
/// [label] is the audience-facing wording (callers keep their existing
/// wording, e.g. "Unit assigned" for customers vs "Assigned" for Team
/// Leaders). It defaults to a title-cased version of [status].
class StatusBadge extends StatelessWidget {
  const StatusBadge({super.key, required this.status, this.label, this.compact = false});

  /// A badge with no lifecycle meaning (e.g. an aggregate "3 active" label).
  const StatusBadge.neutral({super.key, required String this.label, this.compact = false}) : status = '';

  final String status;
  final String? label;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final brightness = Theme.of(context).brightness;
    final style = StatusStyle.of(status);
    final text = label ?? StatusStyle.fallbackLabel(status);

    return Container(
      padding: compact
          ? const EdgeInsets.symmetric(horizontal: 10, vertical: 4)
          : const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: style.backgroundFor(brightness),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        text,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: GoogleFonts.inter(
          color: style.foregroundFor(brightness),
          fontSize: compact ? 11 : 11.5,
          fontWeight: FontWeight.w700,
          letterSpacing: 0.2,
        ),
      ),
    );
  }
}
