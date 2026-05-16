import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';
import '../models/task_model.dart';

class TlTaskDetailCard extends StatelessWidget {
  const TlTaskDetailCard({super.key, required this.task});

  final TaskModel task;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: TmColors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: TmColors.grey300),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _topSection(),
          _divider(),
          _customerSection(),
          _divider(),
          _routeSection(),
          _divider(),
          _metaRow(),
          if (task.notes != null && task.notes!.isNotEmpty) ...[
            _divider(),
            _notesRow(),
          ],
        ],
      ),
    );
  }

  Widget _topSection() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  task.bookingCode,
                  style: GoogleFonts.inter(
                    color: TmColors.grey500,
                    fontSize: 12,
                    letterSpacing: 0.3,
                  ),
                ),
                const SizedBox(height: 4),
                if (task.finalTotal > 0)
                  Text(
                    '₱${task.finalTotal.toStringAsFixed(0)}',
                    style: GoogleFonts.inter(
                      color: TmColors.black,
                      fontSize: 26,
                      letterSpacing: -0.5,
                    ),
                  ),
              ],
            ),
          ),
          _vehiclePhoto(),
        ],
      ),
    );
  }

  Widget _vehiclePhoto() {
    if (task.vehicleImageUrl != null) {
      return ClipRRect(
        borderRadius: BorderRadius.circular(8),
        child: Image.network(
          task.vehicleImageUrl!,
          width: 72,
          height: 56,
          fit: BoxFit.cover,
          errorBuilder: (_, _, _) => _vehiclePlaceholder(),
        ),
      );
    }
    if (task.vehicleInfo != null) return _vehiclePlaceholder();
    return const SizedBox.shrink();
  }

  Widget _vehiclePlaceholder() {
    return Container(
      width: 72,
      height: 56,
      decoration: BoxDecoration(
        color: TmColors.grey100,
        borderRadius: BorderRadius.circular(8),
      ),
      child: const Icon(Icons.directions_car_outlined, color: TmColors.grey500, size: 28),
    );
  }

  Widget _customerSection() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            task.customerName,
            style: GoogleFonts.inter(
              color: TmColors.black,
              fontSize: 15,
              letterSpacing: -0.2,
            ),
          ),
          if (task.vehicleInfo != null) ...[
            const SizedBox(height: 2),
            Text(
              task.vehicleInfo!,
              style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 12),
            ),
          ],
          const SizedBox(height: 8),
          if (task.customerPhone.isNotEmpty)
            Text(
              task.customerPhone,
              style: GoogleFonts.inter(color: TmColors.grey700, fontSize: 13),
            ),
          if (task.customerEmail.isNotEmpty) ...[
            const SizedBox(height: 2),
            Text(
              task.customerEmail,
              style: GoogleFonts.inter(color: TmColors.grey700, fontSize: 13),
            ),
          ],
        ],
      ),
    );
  }

  Widget _routeSection() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const SizedBox(
                width: 10,
                height: 10,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    color: TmColors.yellow,
                    shape: BoxShape.circle,
                  ),
                ),
              ),
              const SizedBox(height: 4),
              const SizedBox(
                width: 2,
                height: 28,
                child: ColoredBox(color: TmColors.grey300),
              ),
              const SizedBox(height: 4),
              SizedBox(
                width: 10,
                height: 10,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    border: Border.all(color: TmColors.grey500, width: 1.5),
                    shape: BoxShape.circle,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  task.pickupAddress,
                  style: GoogleFonts.inter(
                    color: TmColors.black,
                    fontSize: 13,
                    height: 1.4,
                  ),
                ),
                const SizedBox(height: 36),
                Text(
                  task.dropoffAddress,
                  style: GoogleFonts.inter(
                    color: TmColors.black,
                    fontSize: 13,
                    height: 1.4,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _metaRow() {
    final parts = <String>[
      task.truckTypeName,
      '${task.distanceKm.toStringAsFixed(1)} km',
      task.serviceType == 'book_now' ? 'Immediate' : 'Scheduled',
    ];
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Text(
        parts.join('  ·  '),
        style: GoogleFonts.inter(
          color: TmColors.grey500,
          fontSize: 12,
          letterSpacing: 0.1,
        ),
      ),
    );
  }

  Widget _notesRow() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
      child: Text(
        task.notes!,
        style: GoogleFonts.inter(
          color: TmColors.grey700,
          fontSize: 12,
          height: 1.5,
        ),
      ),
    );
  }

  Widget _divider() => const Divider(height: 1, color: TmColors.grey300);
}
