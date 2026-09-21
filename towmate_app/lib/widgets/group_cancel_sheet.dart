import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';
import '../models/booking_model.dart';
import 'quotation_price_cards.dart';

Future<List<String>?> showGroupCancelSelectionSheet(
  BuildContext context, {
  required List<GroupVehicleBreakdown> vehicles,
}) {
  return showModalBottomSheet<List<String>>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (_) => _VehicleSelectionSheet(vehicles: vehicles),
  );
}

Future<bool?> showGroupCancelConfirmDialog(
  BuildContext context, {
  required List<GroupVehicleBreakdown> selected,
  required List<GroupVehicleBreakdown> allVehicles,
}) {
  final activeCount = allVehicles.where((v) => v.isActiveInGroup).length;
  final remaining = activeCount - selected.length;
  return showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      backgroundColor: ctx.card,
      title: Text(
        remaining <= 0 ? 'Cancel this entire request?' : 'Cancel selected vehicles?',
        style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 16, fontWeight: FontWeight.w600, letterSpacing: -0.2),
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (final v in selected)
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Text(
                '${v.bookingCode} · ${v.displayVehicleName}',
                style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 13.5, fontWeight: FontWeight.w600),
              ),
            ),
          const SizedBox(height: 10),
          Text(
            remaining > 0
                ? '$remaining vehicle${remaining == 1 ? '' : 's'} will remain active and unaffected.'
                : 'All remaining active vehicles in this request will be cancelled.',
            style: GoogleFonts.inter(color: secondaryTextColor(ctx), fontSize: 13, height: 1.5),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(ctx, false),
          child: Text('Back', style: GoogleFonts.inter(color: ctx.textPrimary, fontSize: 14, fontWeight: FontWeight.w500)),
        ),
        TextButton(
          onPressed: () => Navigator.pop(ctx, true),
          style: TextButton.styleFrom(
            backgroundColor: TmColors.destructive,
            foregroundColor: TmColors.white,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          ),
          child: Text('Confirm Cancellation', style: GoogleFonts.inter(fontSize: 14, fontWeight: FontWeight.w600)),
        ),
      ],
    ),
  );
}

class _VehicleSelectionSheet extends StatefulWidget {
  const _VehicleSelectionSheet({required this.vehicles});
  final List<GroupVehicleBreakdown> vehicles;

  @override
  State<_VehicleSelectionSheet> createState() => _VehicleSelectionSheetState();
}

class _VehicleSelectionSheetState extends State<_VehicleSelectionSheet> {
  final Set<String> _selected = {};

  List<GroupVehicleBreakdown> get _eligible =>
      widget.vehicles.where((v) => v.isCancellableByCustomer).toList();

  bool get _allSelected =>
      _eligible.isNotEmpty && _eligible.every((v) => _selected.contains(v.bookingCode));

  void _toggleAll(bool? value) {
    setState(() {
      if (value == true) {
        _selected
          ..clear()
          ..addAll(_eligible.map((v) => v.bookingCode));
      } else {
        _selected.clear();
      }
    });
  }

  void _toggleOne(String code, bool? value) {
    setState(() {
      if (value == true) {
        _selected.add(code);
      } else {
        _selected.remove(code);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final sorted = [...widget.vehicles]..sort((a, b) => a.bookingCode.compareTo(b.bookingCode));

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
        child: Container(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 20),
          decoration: BoxDecoration(
            color: context.card,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'What would you like to cancel?',
                style: GoogleFonts.inter(color: context.textPrimary, fontSize: 16, fontWeight: FontWeight.w700, letterSpacing: -0.2),
              ),
              const SizedBox(height: 4),
              Text(
                'Select the vehicles you want to cancel from this request.',
                style: GoogleFonts.inter(color: secondaryTextColor(context), fontSize: 12.5, height: 1.4),
              ),
              const SizedBox(height: 8),
              if (_eligible.isNotEmpty)
                CheckboxListTile(
                  value: _allSelected,
                  onChanged: _toggleAll,
                  controlAffinity: ListTileControlAffinity.leading,
                  contentPadding: EdgeInsets.zero,
                  title: Text(
                    'Select all',
                    style: GoogleFonts.inter(color: context.textPrimary, fontSize: 14, fontWeight: FontWeight.w600),
                  ),
                ),
              Flexible(
                child: ListView.separated(
                  shrinkWrap: true,
                  itemCount: sorted.length,
                  separatorBuilder: (_, _) => Divider(color: context.divider, height: 1),
                  itemBuilder: (_, i) {
                    final v = sorted[i];
                    final eligible = v.isCancellableByCustomer;
                    return CheckboxListTile(
                      value: _selected.contains(v.bookingCode),
                      onChanged: eligible ? (value) => _toggleOne(v.bookingCode, value) : null,
                      controlAffinity: ListTileControlAffinity.leading,
                      contentPadding: EdgeInsets.zero,
                      title: Text(
                        '${v.displayVehicleName} · ${v.bookingCode}',
                        style: GoogleFonts.inter(
                          color: eligible ? context.textPrimary : context.textTertiary,
                          fontSize: 13.5,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      subtitle: Text(
                        eligible ? v.humanStatus : '${v.humanStatus} · Not eligible for cancellation',
                        style: GoogleFonts.inter(color: context.textTertiary, fontSize: 12),
                      ),
                    );
                  },
                ),
              ),
              const SizedBox(height: 12),
              SizedBox(
                width: double.infinity,
                height: 48,
                child: ElevatedButton(
                  onPressed: _selected.isEmpty
                      ? null
                      : () => Navigator.pop(context, _selected.toList()),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: TmColors.destructive,
                    foregroundColor: TmColors.white,
                    disabledBackgroundColor: TmColors.destructive.withValues(alpha: 0.4),
                    elevation: 0,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                  child: Text(
                    'Continue (${_selected.length} selected)',
                    style: GoogleFonts.inter(fontSize: 14, fontWeight: FontWeight.w600),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
