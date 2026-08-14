import 'dart:io';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import '../../core/theme.dart';
import '../../models/truck_type_model.dart';
import '../../models/vehicle_type_model.dart';
import '../../services/api_service.dart';

class BookingReviewScreen extends StatefulWidget {
  const BookingReviewScreen({
    super.key,
    required this.serviceType,
    required this.pickupAddress,
    required this.pickupLat,
    required this.pickupLng,
    required this.dropoffAddress,
    required this.dropoffLat,
    required this.dropoffLng,
    required this.distanceKm,
    required this.primaryTruck,
    required this.primaryVehicle,
    required this.extraVehicles,
    required this.vehicleImagePaths,
    required this.notes,
    this.durationMin,
    this.scheduledDate,
    this.scheduledTime,
    this.scheduledDateStr,
    this.scheduledTimeStr,
  });

  final String serviceType;
  final DateTime? scheduledDate;
  final TimeOfDay? scheduledTime;
  final String? scheduledDateStr;
  final String? scheduledTimeStr;
  final String pickupAddress;
  final double pickupLat;
  final double pickupLng;
  final String dropoffAddress;
  final double dropoffLat;
  final double dropoffLng;
  final double distanceKm;
  final double? durationMin;
  final TruckTypeModel primaryTruck;
  final VehicleTypeModel primaryVehicle;
  // each entry: {truck_type_id, vehicle_type_id, truck_name, vehicle_name, base_rate}
  final List<Map<String, dynamic>> extraVehicles;
  final List<String> vehicleImagePaths;
  final String notes;

  @override
  State<BookingReviewScreen> createState() => _BookingReviewScreenState();
}

class _BookingReviewScreenState extends State<BookingReviewScreen> {
  bool _submitting = false;
  String? _error;

  String? _userName;
  String? _userEmail;
  String? _userPhone;

  @override
  void initState() {
    super.initState();
    _loadUserInfo();
  }

  Future<void> _loadUserInfo() async {
    var name = await ApiService.getUserName();
    var email = await ApiService.getUserEmail();
    var phone = await ApiService.getUserPhone();

    // If email or phone missing from prefs (session pre-dates the save), fetch from API
    if (email == null || phone == null) {
      await ApiService.fetchAndCacheProfile();
      name = await ApiService.getUserName();
      email = await ApiService.getUserEmail();
      phone = await ApiService.getUserPhone();
    }

    if (mounted) {
      setState(() {
        _userName = name;
        _userEmail = email;
        _userPhone = phone;
      });
    }
  }

  static final _fmt = NumberFormat('#,##0.00', 'en_PH');

  double get _extraDistance => widget.distanceKm > 1.0 ? widget.distanceKm - 1.0 : 0.0;
  double get _distanceFee => _extraDistance * 300.0;

  double get _grossPrice {
    double bases = widget.primaryTruck.baseRate;
    for (final ev in widget.extraVehicles) {
      bases += (ev['base_rate'] as num).toDouble();
    }
    return bases + _distanceFee;
  }

  double get _vatAmount => _grossPrice * 0.12;
  double get _total => _grossPrice * 1.12;

  String _formatSchedule() {
    if (widget.scheduledDate == null || widget.scheduledTime == null) return '';
    final d = widget.scheduledDate!;
    final t = widget.scheduledTime!;
    final h = t.hourOfPeriod == 0 ? 12 : t.hourOfPeriod;
    final m = t.minute.toString().padLeft(2, '0');
    final p = t.period == DayPeriod.am ? 'AM' : 'PM';
    return '${d.month}/${d.day}/${d.year}  $h:$m $p';
  }

  Future<void> _confirm() async {
    setState(() {
      _submitting = true;
      _error = null;
    });

    final extraList = widget.extraVehicles
        .map(
          (ev) => <String, dynamic>{
            'truck_type_id': ev['truck_type_id'],
            'vehicle_type_id': ev['vehicle_type_id'],
          },
        )
        .toList();

    final result = await ApiService.createBooking(
      truckTypeId: widget.primaryTruck.id,
      vehicleTypeId: widget.primaryVehicle.id,
      pickupAddress: widget.pickupAddress,
      pickupLat: widget.pickupLat,
      pickupLng: widget.pickupLng,
      dropoffAddress: widget.dropoffAddress,
      dropoffLat: widget.dropoffLat,
      dropoffLng: widget.dropoffLng,
      distanceKm: widget.distanceKm,
      serviceType: widget.serviceType,
      notes: widget.notes.isEmpty ? null : widget.notes,
      scheduledDate: widget.scheduledDateStr,
      scheduledTime: widget.scheduledTimeStr,
      vehicleImagePaths: widget.vehicleImagePaths,
      extraVehicles: extraList,
    );

    if (!mounted) return;

    if (result['success'] == true) {
      final code = result['booking_code'] as String? ?? '';
      Navigator.pushNamedAndRemoveUntil(context, '/home', (_) => false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Booking $code submitted.',
            style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
          ),
          backgroundColor: TmColors.black,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          margin: const EdgeInsets.all(16),
        ),
      );
    } else {
      setState(() {
        _submitting = false;
        _error =
            result['message'] as String? ?? 'Booking failed. Please try again.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: TmColors.white,
      body: SafeArea(
        child: Column(
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
              decoration: const BoxDecoration(
                border: Border(
                  bottom: BorderSide(color: TmColors.grey300, width: 0.5),
                ),
              ),
              child: Row(
                children: [
                  GestureDetector(
                    onTap: _submitting ? null : () => Navigator.pop(context),
                    child: Text(
                      'Back',
                      style: GoogleFonts.inter(
                        color: _submitting
                            ? TmColors.grey300
                            : TmColors.grey700,
                        fontSize: 14,
                        letterSpacing: 0.1,
                      ),
                    ),
                  ),
                  const Spacer(),
                  Text(
                    'Review Booking',
                    style: GoogleFonts.inter(
                      color: TmColors.black,
                      fontSize: 16,
                      letterSpacing: -0.3,
                    ),
                  ),
                  const Spacer(),
                  const SizedBox(width: 40),
                ],
              ),
            ),

            // ── Scrollable content ──────────────────────────────────────────
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(24, 24, 24, 32),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // ── Contact information ─────────────────────────────────
                    _SectionLabel('CONTACT INFORMATION'),
                    const SizedBox(height: 12),
                    _ReviewRow(label: 'Name', value: _userName ?? '—'),
                    const SizedBox(height: 6),
                    _ReviewRow(label: 'Email', value: _userEmail ?? '—'),
                    const SizedBox(height: 6),
                    _ReviewRow(label: 'Phone', value: _userPhone ?? '—'),

                    const SizedBox(height: 24),
                    _Divider(),

                    // ── Booking details ─────────────────────────────────────
                    const SizedBox(height: 20),
                    _SectionLabel('BOOKING DETAILS'),
                    const SizedBox(height: 12),
                    _ReviewRow(
                      label: 'Mode',
                      value: widget.serviceType == 'schedule'
                          ? 'Schedule Later'
                          : 'Book Now',
                    ),
                    if (widget.serviceType == 'schedule' &&
                        widget.scheduledDate != null) ...[
                      const SizedBox(height: 6),
                      _ReviewRow(label: 'Scheduled', value: _formatSchedule()),
                    ],
                    const SizedBox(height: 6),
                    _ReviewRow(label: 'Pickup', value: widget.pickupAddress),
                    const SizedBox(height: 6),
                    _ReviewRow(label: 'Drop-off', value: widget.dropoffAddress),
                    const SizedBox(height: 6),
                    _ReviewRow(
                      label: 'Distance',
                      value: '${widget.distanceKm.toStringAsFixed(2)} km',
                    ),
                    if (widget.durationMin != null) ...[
                      const SizedBox(height: 6),
                      _ReviewRow(
                        label: 'Est. travel time',
                        value: '${widget.durationMin!.round()} min',
                      ),
                    ],

                    const SizedBox(height: 24),
                    _Divider(),

                    // ── Vehicles ────────────────────────────────────────────
                    const SizedBox(height: 20),
                    _SectionLabel('VEHICLES'),
                    const SizedBox(height: 12),
                    _ReviewRow(
                      label: 'Vehicle 1',
                      value:
                          '${widget.primaryTruck.name}  ·  ${widget.primaryVehicle.name}',
                    ),
                    ...List.generate(widget.extraVehicles.length, (i) {
                      final ev = widget.extraVehicles[i];
                      return Padding(
                        padding: const EdgeInsets.only(top: 6),
                        child: _ReviewRow(
                          label: 'Vehicle ${i + 2}',
                          value:
                              '${ev['truck_name']}  ·  ${ev['vehicle_name']}',
                        ),
                      );
                    }),

                    const SizedBox(height: 24),
                    _Divider(),

                    // ── Pricing ─────────────────────────────────────────────
                    const SizedBox(height: 20),
                    _SectionLabel('FARE ESTIMATE'),
                    const SizedBox(height: 12),
                    _ReviewRow(
                      label: 'Vehicle 1 base rate',
                      value: '₱${_fmt.format(widget.primaryTruck.baseRate)}',
                    ),
                    ...List.generate(widget.extraVehicles.length, (i) {
                      final ev = widget.extraVehicles[i];
                      final rate = (ev['base_rate'] as num).toDouble();
                      return Padding(
                        padding: const EdgeInsets.only(top: 6),
                        child: _ReviewRow(
                          label: 'Vehicle ${i + 2} base rate',
                          value: '₱${_fmt.format(rate)}',
                        ),
                      );
                    }),
                    const SizedBox(height: 6),
                    _ReviewRow(
                      label: widget.distanceKm > 1.0
                          ? '${_extraDistance.toStringAsFixed(2)} km × ₱300'
                          : '${widget.distanceKm.toStringAsFixed(2)} km (first 1 km free)',
                      value: widget.distanceKm > 1.0
                          ? '₱${_fmt.format(_distanceFee)}'
                          : 'Free',
                    ),
                    const SizedBox(height: 6),
                    _ReviewRow(
                      label: 'VAT (12%)',
                      value: '₱${_fmt.format(_vatAmount)}',
                    ),
                    const SizedBox(height: 16),
                    _Divider(),
                    const SizedBox(height: 14),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          'Estimated total',
                          style: GoogleFonts.inter(
                            color: TmColors.black,
                            fontSize: 14,
                            letterSpacing: 0.1,
                          ),
                        ),
                        Text(
                          '₱${_fmt.format(_total)}',
                          style: GoogleFonts.inter(
                            color: TmColors.black,
                            fontSize: 22,
                            letterSpacing: -0.5,
                          ),
                        ),
                      ],
                    ),

                    // ── Photos ──────────────────────────────────────────────
                    if (widget.vehicleImagePaths.isNotEmpty) ...[
                      const SizedBox(height: 24),
                      _Divider(),
                      const SizedBox(height: 20),
                      _SectionLabel('VEHICLE PHOTOS'),
                      const SizedBox(height: 12),
                      SizedBox(
                        height: 80,
                        child: ListView.separated(
                          scrollDirection: Axis.horizontal,
                          itemCount: widget.vehicleImagePaths.length,
                          separatorBuilder: (_, _) => const SizedBox(width: 8),
                          itemBuilder: (_, i) {
                            final path = widget.vehicleImagePaths[i];
                            return ClipRRect(
                              borderRadius: BorderRadius.circular(4),
                              child: kIsWeb
                                  ? Image.network(
                                      path,
                                      width: 80,
                                      height: 80,
                                      fit: BoxFit.cover,
                                    )
                                  : Image.file(
                                      File(path),
                                      width: 80,
                                      height: 80,
                                      fit: BoxFit.cover,
                                    ),
                            );
                          },
                        ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        '${widget.vehicleImagePaths.length} photo${widget.vehicleImagePaths.length == 1 ? '' : 's'} attached',
                        style: GoogleFonts.inter(
                          color: TmColors.grey500,
                          fontSize: 12,
                          letterSpacing: 0.1,
                        ),
                      ),
                    ],

                    // ── Notes ───────────────────────────────────────────────
                    if (widget.notes.isNotEmpty) ...[
                      const SizedBox(height: 24),
                      _Divider(),
                      const SizedBox(height: 20),
                      _SectionLabel('NOTES'),
                      const SizedBox(height: 10),
                      Text(
                        widget.notes,
                        style: GoogleFonts.inter(
                          color: TmColors.grey700,
                          fontSize: 13,
                          letterSpacing: 0.1,
                          height: 1.5,
                        ),
                      ),
                    ],

                    // ── Error ───────────────────────────────────────────────
                    if (_error != null) ...[
                      const SizedBox(height: 20),
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: TmColors.error.withValues(alpha: 0.06),
                          border: Border.all(
                            color: TmColors.error.withValues(alpha: 0.3),
                          ),
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Text(
                          _error!,
                          style: GoogleFonts.inter(
                            color: TmColors.error,
                            fontSize: 13,
                            letterSpacing: 0.1,
                          ),
                        ),
                      ),
                    ],

                    const SizedBox(height: 32),
                  ],
                ),
              ),
            ),

            // ── Sticky confirm button ───────────────────────────────────────
            Container(
              padding: const EdgeInsets.fromLTRB(24, 12, 24, 24),
              decoration: const BoxDecoration(
                color: TmColors.white,
                border: Border(
                  top: BorderSide(color: TmColors.grey300, width: 0.5),
                ),
              ),
              child: SizedBox(
                width: double.infinity,
                height: 52,
                child: ElevatedButton(
                  onPressed: _submitting ? null : _confirm,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: TmColors.black,
                    foregroundColor: TmColors.white,
                    disabledBackgroundColor: TmColors.grey300,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(6),
                    ),
                    elevation: 0,
                  ),
                  child: _submitting
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                            color: TmColors.white,
                            strokeWidth: 2,
                          ),
                        )
                      : Text(
                          'Confirm Booking',
                          style: GoogleFonts.inter(
                            color: TmColors.white,
                            fontSize: 15,
                            letterSpacing: 0.2,
                          ),
                        ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ── Helpers ─────────────────────────────────────────────────────────────────

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);
  final String text;

  @override
  Widget build(BuildContext context) => Text(
    text,
    style: GoogleFonts.inter(
      color: TmColors.grey500,
      fontSize: 11,
      letterSpacing: 0.8,
    ),
  );
}

class _ReviewRow extends StatelessWidget {
  const _ReviewRow({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 140,
          child: Text(
            label,
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 13,
              letterSpacing: 0.1,
            ),
          ),
        ),
        Expanded(
          child: Text(
            value,
            style: GoogleFonts.inter(
              color: TmColors.grey700,
              fontSize: 13,
              letterSpacing: 0.1,
              height: 1.4,
            ),
          ),
        ),
      ],
    );
  }
}

class _Divider extends StatelessWidget {
  @override
  Widget build(BuildContext context) =>
      Container(height: 0.5, color: TmColors.grey300);
}
