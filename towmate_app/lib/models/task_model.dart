class GroupVehiclePricing {
  final double baseRate;
  final double distanceFee;
  final double vatAmount;
  final double finalTotal;

  const GroupVehiclePricing({
    required this.baseRate,
    required this.distanceFee,
    required this.vatAmount,
    required this.finalTotal,
  });

  factory GroupVehiclePricing.fromJson(Map<String, dynamic> json) {
    return GroupVehiclePricing(
      baseRate: (json['base_rate'] as num?)?.toDouble() ?? 0.0,
      distanceFee: (json['distance_fee'] as num?)?.toDouble() ?? 0.0,
      vatAmount: (json['vat_amount'] as num?)?.toDouble() ?? 0.0,
      finalTotal: (json['final_total'] as num?)?.toDouble() ?? 0.0,
    );
  }
}

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
  final String? paymentMethod;
  final String? groupCode;
  final int groupVehicleCount;
  final int groupPosition;
  final bool groupReadyForPayment;
  final double? groupTotal;
  final List<double> groupVehicleTotals;
  final List<GroupVehiclePricing>? groupVehicleBreakdown;
  final double? groupAdjustment;
  final bool hasClaimableSibling;

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
    this.paymentMethod,
    this.groupCode,
    this.groupVehicleCount = 1,
    this.groupPosition = 1,
    this.groupReadyForPayment = false,
    this.groupTotal,
    this.groupVehicleTotals = const [],
    this.groupVehicleBreakdown,
    this.groupAdjustment,
    this.hasClaimableSibling = false,
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
      paymentMethod: json['payment_method'] as String?,
      groupCode: json['group_code'] as String?,
      groupVehicleCount: (json['group_vehicle_count'] as num?)?.toInt() ?? 1,
      groupPosition: (json['group_position'] as num?)?.toInt() ?? 1,
      groupReadyForPayment: json['group_ready_for_payment'] as bool? ?? false,
      groupTotal: (json['group_total'] as num?)?.toDouble(),
      groupVehicleTotals: (json['group_vehicle_totals'] as List<dynamic>?)
              ?.map((v) => (v as num).toDouble())
              .toList() ??
          const [],
      groupVehicleBreakdown: (json['group_vehicle_breakdown'] as List<dynamic>?)
          ?.map((v) => GroupVehiclePricing.fromJson(v as Map<String, dynamic>))
          .toList(),
      groupAdjustment: (json['group_adjustment'] as num?)?.toDouble(),
      hasClaimableSibling: json['has_claimable_sibling'] as bool? ?? false,
    );
  }

  TaskModel copyWith({
    String? status,
    String? paymentMethod,
    String? groupCode,
    int? groupVehicleCount,
    int? groupPosition,
    bool? groupReadyForPayment,
    double? groupTotal,
    List<double>? groupVehicleTotals,
    List<GroupVehiclePricing>? groupVehicleBreakdown,
    double? groupAdjustment,
    bool? hasClaimableSibling,
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
      paymentMethod: paymentMethod ?? this.paymentMethod,
      groupCode: groupCode ?? this.groupCode,
      groupVehicleCount: groupVehicleCount ?? this.groupVehicleCount,
      groupPosition: groupPosition ?? this.groupPosition,
      groupReadyForPayment: groupReadyForPayment ?? this.groupReadyForPayment,
      groupTotal: groupTotal ?? this.groupTotal,
      groupVehicleTotals: groupVehicleTotals ?? this.groupVehicleTotals,
      groupVehicleBreakdown: groupVehicleBreakdown ?? this.groupVehicleBreakdown,
      groupAdjustment: groupAdjustment ?? this.groupAdjustment,
      hasClaimableSibling: hasClaimableSibling ?? this.hasClaimableSibling,
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
