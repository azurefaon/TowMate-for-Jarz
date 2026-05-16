class BookingModel {
  const BookingModel({
    required this.id,
    required this.bookingCode,
    required this.status,
    required this.pickupAddress,
    required this.dropoffAddress,
    required this.truckTypeName,
    this.distanceKm,
    this.computedTotal,
    this.createdAt,
    // Detail-only fields (nullable — not present in history list)
    this.teamLeaderName,
    this.driverName,
    this.baseRate,
    this.perKmRate,
    this.additionalFee,
    this.finalTotal,
    this.paymentMethod,
    this.pickupNotes,
    this.serviceType,
    this.scheduledDate,
    this.scheduledTime,
    this.pickupLat,
    this.pickupLng,
    this.dropoffLat,
    this.dropoffLng,
    this.truckTypeId,
    this.arrivalPhotoUrl,
    this.dropoffPhotoUrl,
    this.completedAt,
  });

  final int id;
  final String bookingCode;
  final String status;
  final String pickupAddress;
  final String dropoffAddress;
  final String truckTypeName;
  final double? distanceKm;
  final double? computedTotal;
  final DateTime? createdAt;

  // Detail-only fields
  final String? teamLeaderName;
  final String? driverName;
  final double? baseRate;
  final double? perKmRate;
  final double? additionalFee;
  final double? finalTotal;
  final String? paymentMethod;
  final String? pickupNotes;
  final String? serviceType;
  final String? scheduledDate;
  final String? scheduledTime;
  final double? pickupLat;
  final double? pickupLng;
  final double? dropoffLat;
  final double? dropoffLng;
  final int? truckTypeId;
  final String? arrivalPhotoUrl;
  final String? dropoffPhotoUrl;
  final DateTime? completedAt;

  String get formattedDate {
    if (createdAt == null) return '';
    return '${createdAt!.month.toString().padLeft(2, '0')}/'
        '${createdAt!.day.toString().padLeft(2, '0')}/'
        '${createdAt!.year}';
  }

  String get humanStatus {
    return switch (status) {
      'requested'            => 'Requested',
      'reviewed'             => 'Under review',
      'quoted'               => 'Quoted',
      'quotation_sent'       => 'Quotation sent',
      'confirmed'            => 'Confirmed',
      'accepted'             => 'Accepted',
      'assigned'             => 'Assigned',
      'on_the_way'           => 'On the way',
      'in_progress'          => 'In progress',
      'waiting_verification' => 'Awaiting verification',
      'on_job'               => 'On job',
      'completed'            => 'Completed',
      'cancelled'            => 'Cancelled',
      'rejected'             => 'Rejected',
      _                      => status,
    };
  }

  static double? _d(dynamic v) => v == null ? null : double.tryParse(v.toString());
  static int? _i(dynamic v) => v == null ? null : int.tryParse(v.toString());

  factory BookingModel.fromJson(Map<String, dynamic> j) {
    final tt = j['truck_type'] as Map<String, dynamic>?;
    return BookingModel(
      id: _i(j['id']) ?? 0,
      bookingCode: j['booking_code'] as String,
      status: j['status'] as String,
      pickupAddress: j['pickup_address'] as String,
      dropoffAddress: j['dropoff_address'] as String,
      truckTypeName: tt?['name'] as String?
          ?? j['truck_type_name'] as String?
          ?? '',
      distanceKm: _d(j['distance_km']),
      computedTotal: _d(j['computed_total']),
      createdAt: j['created_at'] != null ? DateTime.tryParse(j['created_at'] as String) : null,
      // Detail-only
      teamLeaderName: j['team_leader_name'] as String?,
      driverName: j['driver_name'] as String?,
      baseRate: _d(j['base_rate']),
      perKmRate: _d(j['per_km_rate']),
      additionalFee: _d(j['additional_fee']),
      finalTotal: _d(j['final_total']),
      paymentMethod: j['payment_method'] as String?,
      pickupNotes: j['pickup_notes'] as String?,
      serviceType: j['service_type'] as String?,
      scheduledDate: j['scheduled_date'] as String?,
      scheduledTime: j['scheduled_time'] as String?,
      pickupLat: _d(j['pickup_lat']),
      pickupLng: _d(j['pickup_lng']),
      dropoffLat: _d(j['dropoff_lat']),
      dropoffLng: _d(j['dropoff_lng']),
      truckTypeId: _i(j['truck_type_id']),
      arrivalPhotoUrl: j['arrival_photo_url'] as String?,
      dropoffPhotoUrl: j['dropoff_photo_url'] as String?,
      completedAt: j['completed_at'] != null ? DateTime.tryParse(j['completed_at'] as String) : null,
    );
  }
}
