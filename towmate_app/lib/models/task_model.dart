class TaskModel {
  final int id;
  final String bookingCode;
  final String status;
  final String pickupAddress;
  final String dropoffAddress;
  final double pickupLat;
  final double pickupLng;
  final double dropoffLat;
  final double dropoffLng;
  final double distanceKm;
  final String customerName;
  final String customerPhone;
  final String customerEmail;
  final double finalTotal;
  final String truckTypeName;
  final String serviceType;
  final String? vehicleInfo;
  final String? vehicleImageUrl;
  final String? notes;
  final String? arrivalPhotoPath;
  final String? dropoffPhotoPath;
  final String? customerSignaturePath;
  final DateTime? assignedAt;
  final String? groupCode;
  final int groupVehicleCount;
  final int groupPosition;

  const TaskModel({
    required this.id,
    required this.bookingCode,
    required this.status,
    required this.pickupAddress,
    required this.dropoffAddress,
    required this.pickupLat,
    required this.pickupLng,
    required this.dropoffLat,
    required this.dropoffLng,
    required this.distanceKm,
    required this.customerName,
    required this.customerPhone,
    required this.customerEmail,
    required this.finalTotal,
    required this.truckTypeName,
    required this.serviceType,
    this.vehicleInfo,
    this.vehicleImageUrl,
    this.notes,
    this.arrivalPhotoPath,
    this.dropoffPhotoPath,
    this.customerSignaturePath,
    this.assignedAt,
    this.groupCode,
    this.groupVehicleCount = 1,
    this.groupPosition = 1,
  });

  factory TaskModel.fromJson(Map<String, dynamic> json) {
    return TaskModel(
      id: (json['id'] as num).toInt(),
      bookingCode: json['booking_code'] as String? ?? '',
      status: json['status'] as String? ?? 'assigned',
      pickupAddress: json['pickup_address'] as String? ?? '',
      dropoffAddress: json['dropoff_address'] as String? ?? '',
      pickupLat: (json['pickup_lat'] as num?)?.toDouble() ?? 0.0,
      pickupLng: (json['pickup_lng'] as num?)?.toDouble() ?? 0.0,
      dropoffLat: (json['dropoff_lat'] as num?)?.toDouble() ?? 0.0,
      dropoffLng: (json['dropoff_lng'] as num?)?.toDouble() ?? 0.0,
      distanceKm: (json['distance_km'] as num?)?.toDouble() ?? 0.0,
      customerName: json['customer_name'] as String? ?? 'Customer',
      customerPhone: json['customer_phone'] as String? ?? '',
      customerEmail: json['customer_email'] as String? ?? '',
      finalTotal: (json['final_total'] as num?)?.toDouble() ?? 0.0,
      truckTypeName: json['truck_type_name'] as String? ?? '',
      serviceType: json['service_type'] as String? ?? 'book_now',
      vehicleInfo: json['vehicle_info'] as String?,
      vehicleImageUrl: json['vehicle_image_url'] as String?,
      notes: json['notes'] as String?,
      arrivalPhotoPath: json['arrival_photo_path'] as String?,
      dropoffPhotoPath: json['dropoff_photo_path'] as String?,
      customerSignaturePath: json['customer_signature_path'] as String?,
      assignedAt: json['assigned_at'] != null
          ? DateTime.tryParse(json['assigned_at'] as String)
          : null,
      groupCode: json['group_code'] as String?,
      groupVehicleCount: (json['group_vehicle_count'] as num?)?.toInt() ?? 1,
      groupPosition: (json['group_position'] as num?)?.toInt() ?? 1,
    );
  }

  TaskModel copyWith({
    String? status,
    String? groupCode,
    int? groupVehicleCount,
    int? groupPosition,
  }) {
    return TaskModel(
      id: id,
      bookingCode: bookingCode,
      status: status ?? this.status,
      pickupAddress: pickupAddress,
      dropoffAddress: dropoffAddress,
      pickupLat: pickupLat,
      pickupLng: pickupLng,
      dropoffLat: dropoffLat,
      dropoffLng: dropoffLng,
      distanceKm: distanceKm,
      customerName: customerName,
      customerPhone: customerPhone,
      customerEmail: customerEmail,
      finalTotal: finalTotal,
      truckTypeName: truckTypeName,
      serviceType: serviceType,
      vehicleInfo: vehicleInfo,
      vehicleImageUrl: vehicleImageUrl,
      notes: notes,
      arrivalPhotoPath: arrivalPhotoPath,
      dropoffPhotoPath: dropoffPhotoPath,
      customerSignaturePath: customerSignaturePath,
      assignedAt: assignedAt,
      groupCode: groupCode ?? this.groupCode,
      groupVehicleCount: groupVehicleCount ?? this.groupVehicleCount,
      groupPosition: groupPosition ?? this.groupPosition,
    );
  }

  bool get isGroupBooking => groupVehicleCount > 1;

  bool get isActive => const {
        'accepted', 'on_the_way', 'arrived_pickup',
        'in_progress', 'loading_vehicle', 'on_job',
        'arrived_dropoff', 'waiting_verification',
      }.contains(status);

  bool get isGpsPhase =>
      status == 'on_the_way' || status == 'on_job';
}
