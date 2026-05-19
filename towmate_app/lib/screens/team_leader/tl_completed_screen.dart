import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/team_leader_service.dart';

class TlCompletedScreen extends StatefulWidget {
  const TlCompletedScreen({
    super.key,
    required this.task,
    this.onUpdate,
  });
  final TaskModel task;
  final void Function(TaskModel)? onUpdate;

  @override
  State<TlCompletedScreen> createState() => _TlCompletedScreenState();
}

class _TlCompletedScreenState extends State<TlCompletedScreen> {
  bool _fetchingNext = false;

  bool get _hasNextVehicle =>
      widget.task.groupCode != null &&
      widget.task.groupVehicleCount > 1 &&
      widget.task.groupPosition < widget.task.groupVehicleCount;

  Future<void> _getNextVehicle() async {
    setState(() => _fetchingNext = true);
    final next = await TeamLeaderService.getCurrentTask();
    if (!mounted) return;
    setState(() => _fetchingNext = false);
    if (next != null && next.status != 'completed') {
      widget.onUpdate?.call(next);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('No next vehicle task found. Check with dispatch.'),
        duration: Duration(seconds: 3),
      ));
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            const SizedBox(height: 32),
            Container(
              width: 80,
              height: 80,
              decoration: BoxDecoration(
                color: TmColors.success.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.check_rounded,
                  color: TmColors.success, size: 44),
            ),
            const SizedBox(height: 20),
            Text(
              'Vehicle ${widget.task.groupPosition} Complete',
              style: GoogleFonts.inter(
                  color: TmColors.black, fontSize: 22, letterSpacing: -0.5),
            ),
            const SizedBox(height: 6),
            Text('The towing service has been completed and verified.',
                textAlign: TextAlign.center,
                style:
                    GoogleFonts.inter(color: TmColors.grey500, fontSize: 13)),
            const SizedBox(height: 32),
            _summaryCard(),

            // ── Next vehicle section (group bookings) ────────────────
            if (_hasNextVehicle) ...[
              const SizedBox(height: 24),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  color: TmColors.yellow.withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(
                      color: TmColors.yellow.withValues(alpha: 0.4)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.directions_car_rounded,
                            color: TmColors.black, size: 18),
                        const SizedBox(width: 8),
                        Text(
                          'NEXT: Collect Vehicle ${widget.task.groupPosition + 1}',
                          style: GoogleFonts.inter(
                            color: TmColors.black,
                            fontSize: 13,
                            letterSpacing: -0.2,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'Return to the original pickup location to collect Vehicle ${widget.task.groupPosition + 1}.',
                      style: GoogleFonts.inter(
                          color: TmColors.grey700, fontSize: 12),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      widget.task.pickupAddress,
                      style: GoogleFonts.inter(
                        color: TmColors.black,
                        fontSize: 12,
                        letterSpacing: -0.1,
                      ),
                    ),
                    const SizedBox(height: 14),
                    SizedBox(
                      width: double.infinity,
                      height: 48,
                      child: ElevatedButton(
                        onPressed: _fetchingNext ? null : _getNextVehicle,
                        style: ElevatedButton.styleFrom(
                          backgroundColor: TmColors.yellow,
                          foregroundColor: TmColors.black,
                          disabledBackgroundColor:
                              TmColors.yellow.withValues(alpha: 0.5),
                          shape: const StadiumBorder(),
                          elevation: 0,
                        ),
                        child: _fetchingNext
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(
                                    color: TmColors.black, strokeWidth: 2))
                            : Text(
                                'Get Vehicle ${widget.task.groupPosition + 1}',
                                style: GoogleFonts.inter(
                                    color: TmColors.black, fontSize: 14),
                              ),
                      ),
                    ),
                  ],
                ),
              ),
            ],

            const SizedBox(height: 24),
            SizedBox(
              width: double.infinity,
              height: 52,
              child: ElevatedButton(
                onPressed: () =>
                    Navigator.pushReplacementNamed(context, '/tl-home'),
                style: ElevatedButton.styleFrom(
                  backgroundColor:
                      _hasNextVehicle ? TmColors.white : TmColors.yellow,
                  foregroundColor: TmColors.black,
                  shape: const StadiumBorder(),
                  elevation: 0,
                  side: _hasNextVehicle
                      ? const BorderSide(color: TmColors.grey300)
                      : BorderSide.none,
                ),
                child: Text('Back to Home',
                    style: GoogleFonts.inter(
                        color: TmColors.black, fontSize: 15)),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _summaryCard() {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: TmColors.white,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.05),
            blurRadius: 12,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _row('Booking', widget.task.bookingCode),
          _divider(),
          _row('Customer', widget.task.customerName),
          _divider(),
          _row('Pickup', widget.task.pickupAddress),
          _divider(),
          _row('Drop-off', widget.task.dropoffAddress),
          _divider(),
          _row('Distance',
              '${widget.task.distanceKm.toStringAsFixed(1)} km'),
        ],
      ),
    );
  }

  Widget _row(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 80,
            child: Text(label,
                style:
                    GoogleFonts.inter(color: TmColors.grey500, fontSize: 12)),
          ),
          Expanded(
            child: Text(value,
                style: GoogleFonts.inter(
                    color: TmColors.black, fontSize: 13),
                maxLines: 2,
                overflow: TextOverflow.ellipsis),
          ),
        ],
      ),
    );
  }

  Widget _divider() => const Divider(height: 1, color: TmColors.grey300);
}
