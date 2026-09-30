import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';

/// LOCAL/DEMO-only control. Deliberately unlike the real yellow action so it
/// is obvious in a presentation that this is a simulator, not the real
/// operational workflow. Only rendered when the backend says the task is the
/// demo fixture (see TaskModel.demoArrivalAvailable).
class TlDemoSimulatorButton extends StatelessWidget {
  const TlDemoSimulatorButton({super.key, required this.label, required this.onPressed});

  final String label;
  final VoidCallback? onPressed;

  static const _ink = Color(0xFF6D28D9);

  @override
  Widget build(BuildContext context) {
    return Container(
      key: const Key('tl_demo_simulator'),
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
      decoration: BoxDecoration(
        color: _ink.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: _ink.withValues(alpha: 0.5), width: 1.5),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(color: _ink, borderRadius: BorderRadius.circular(6)),
                child: Text(
                  'DEMO ONLY',
                  style: GoogleFonts.inter(
                    color: Colors.white,
                    fontSize: 10.5,
                    fontWeight: FontWeight.w800,
                    letterSpacing: 0.8,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  'Simulator - skips the GPS check',
                  style: GoogleFonts.inter(color: context.textSecondary, fontSize: 12),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          SizedBox(
            width: double.infinity,
            height: 48,
            child: OutlinedButton(
              onPressed: onPressed,
              style: OutlinedButton.styleFrom(
                foregroundColor: _ink,
                side: const BorderSide(color: _ink, width: 1.5),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              ),
              child: Text(
                label,
                textAlign: TextAlign.center,
                style: GoogleFonts.inter(fontSize: 14, fontWeight: FontWeight.w700),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
