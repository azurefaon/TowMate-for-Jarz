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

  factory BookingModel.fromJson(Map<String, dynamic> j) {
    final tt = j['truck_type'] as Map<String, dynamic>?;
    return BookingModel(
      id: j['id'] != null ? (j['id'] as num).toInt() : 0,
      bookingCode: j['booking_code'] as String,
      status: j['status'] as String,
      pickupAddress: j['pickup_address'] as String,
      dropoffAddress: j['dropoff_address'] as String,
      truckTypeName: tt?['name'] as String?
          ?? j['truck_type_name'] as String?
          ?? '',
      distanceKm: j['distance_km'] != null ? (j['distance_km'] as num).toDouble() : null,
      computedTotal: j['computed_total'] != null ? (j['computed_total'] as num).toDouble() : null,
      createdAt: j['created_at'] != null ? DateTime.tryParse(j['created_at'] as String) : null,
      // Detail-only
      teamLeaderName: j['team_leader_name'] as String?,
      driverName: j['driver_name'] as String?,
      baseRate: j['base_rate'] != null ? (j['base_rate'] as num).toDouble() : null,
      perKmRate: j['per_km_rate'] != null ? (j['per_km_rate'] as num).toDouble() : null,
      additionalFee: j['additional_fee'] != null ? (j['additional_fee'] as num).toDouble() : null,
      finalTotal: j['final_total'] != null ? (j['final_total'] as num).toDouble() : null,
      paymentMethod: j['payment_method'] as String?,
      pickupNotes: j['pickup_notes'] as String?,
      serviceType: j['service_type'] as String?,
      scheduledDate: j['scheduled_date'] as String?,
      scheduledTime: j['scheduled_time'] as String?,
      pickupLat: j['pickup_lat'] != null ? (j['pickup_lat'] as num).toDouble() : null,
      pickupLng: j['pickup_lng'] != null ? (j['pickup_lng'] as num).toDouble() : null,
      dropoffLat: j['dropoff_lat'] != null ? (j['dropoff_lat'] as num).toDouble() : null,
      dropoffLng: j['dropoff_lng'] != null ? (j['dropoff_lng'] as num).toDouble() : null,
      truckTypeId: j['truck_type_id'] != null ? (j['truck_type_id'] as num).toInt() : null,
      arrivalPhotoUrl: j['arrival_photo_url'] as String?,
      dropoffPhotoUrl: j['dropoff_photo_url'] as String?,
      completedAt: j['completed_at'] != null ? DateTime.tryParse(j['completed_at'] as String) : null,
    );
  }
}
