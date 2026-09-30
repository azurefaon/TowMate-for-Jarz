import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';
import '../models/task_model.dart';

/// Read-only summary of the team and truck assigned to a task. Renders
/// nothing when the task has no assigned Unit, and omits any row whose value
/// is unavailable.
class TlAssignedTeamCard extends StatelessWidget {
  const TlAssignedTeamCard({super.key, required this.team});

  final AssignedTeam? team;

  @override
  Widget build(BuildContext context) {
    final t = team;
    if (t == null) return const SizedBox.shrink();

    final rows = <(String, String)>[
      if (t.teamLeaderName != null) ('Team Leader', t.teamLeaderName!),
      if (t.driverName != null) ('Driver', t.driverName!),
      if (t.crewNames.isNotEmpty) ('Crew', t.crewNames.join(', ')),
      if (t.unitName != null) ('Truck', t.unitName!),
      if (t.plateNumber != null) ('Plate Number', t.plateNumber!),
      if (t.truckTypeName != null) ('Truck Type', t.truckTypeName!),
    ];

    if (rows.isEmpty) return const SizedBox.shrink();

    return Container(
      key: const Key('tl_assigned_team_card'),
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: context.card,
        borderRadius: BorderRadius.circular(23),
        border: Border.all(color: context.divider),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'ASSIGNED TEAM',
            style: GoogleFonts.inter(
              color: context.textTertiary,
              fontSize: 11,
              fontWeight: FontWeight.w700,
              letterSpacing: 1.0,
            ),
          ),
          const SizedBox(height: 10),
          for (final row in rows)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(
                    width: 110,
                    child: Text(
                      row.$1,
                      style: GoogleFonts.inter(
                        color: context.textTertiary,
                        fontSize: 13,
                      ),
                    ),
                  ),
                  Expanded(
                    child: Text(
                      row.$2,
                      style: GoogleFonts.inter(
                        color: context.textPrimary,
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
