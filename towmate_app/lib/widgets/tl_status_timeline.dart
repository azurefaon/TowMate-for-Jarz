import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';
import '../core/tl_task_flow.dart';

class TlStatusTimeline extends StatelessWidget {
  const TlStatusTimeline({super.key, required this.currentStatus});

  final String currentStatus;

  // Each group merges one or more raw booking statuses into a single
  // TL-facing step, per the collapsed 6-step flow.
  static const _steps = [
    (['accepted', 'on_the_way'], 'Route'),
    (['arrived_pickup', 'in_progress', 'loading_vehicle'], 'Arrived'),
    (['on_job'], 'Towing'),
    (['arrived_dropoff'], 'Dropoff'),
    (['waiting_verification'], 'Pending Payment'),
    (['completed'], 'Completed'),
  ];

  @override
  Widget build(BuildContext context) {
    final step = TlTaskFlow.stepFor(currentStatus);
    if (step == null) return const SizedBox.shrink();
    final currentIdx = step - 1;
    final stepNum = step;
    final total = _steps.length;
    final label = _steps[currentIdx].$2;
    final progress = stepNum / total;

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
