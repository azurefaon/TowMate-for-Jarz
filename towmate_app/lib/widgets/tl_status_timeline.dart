import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';

class TlStatusTimeline extends StatelessWidget {
  const TlStatusTimeline({super.key, required this.currentStatus});

  final String currentStatus;

  static const _steps = [
    ('accepted', 'Accepted'),
    ('on_the_way', 'En Route'),
    ('arrived_pickup', 'At Pickup'),
    ('in_progress', 'Inspecting'),
    ('loading_vehicle', 'Loading'),
    ('on_job', 'Transporting'),
    ('arrived_dropoff', 'At Drop-off'),
    ('waiting_verification', 'Verifying'),
    ('completed', 'Done'),
  ];

  @override
  Widget build(BuildContext context) {
    final currentIdx = _steps.indexWhere((s) => s.$1 == currentStatus);
    final stepNum = currentIdx < 0 ? 1 : currentIdx + 1;
    final total = _steps.length;
    final label = currentIdx < 0 ? '' : _steps[currentIdx].$2;
    final progress = currentIdx < 0 ? 0.0 : stepNum / total;

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                label,
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 13,
                  letterSpacing: -0.1,
                ),
              ),
              Text(
                '$stepNum / $total',
                style: GoogleFonts.inter(
                  color: TmColors.grey500,
                  fontSize: 12,
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          ClipRRect(
            borderRadius: BorderRadius.circular(2),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 3,
              backgroundColor: TmColors.grey300,
              valueColor: const AlwaysStoppedAnimation<Color>(TmColors.yellow),
            ),
          ),
        ],
      ),
    );
  }
}
