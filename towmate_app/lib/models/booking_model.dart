import 'package:intl/intl.dart';

String humanStatusLabel(String status) {
  return switch (status) {
    'requested'            => 'Requested',
    'reviewed'             => 'Under review',
    'quoted'               => 'Quoted',
    'quotation_sent'       => 'Quotation sent',
    'scheduled'            => 'Scheduled',
    'scheduled_confirmed'  => 'Scheduled — Confirmed',
    'confirmed'            => 'Confirmed',
    'accepted'             => 'Accepted',
    'assigned'             => 'Unit assigned',
    'on_the_way'           => 'On the way',
    'arrived_pickup'       => 'Arrived at pickup',
    'in_progress'          => 'Towing in progress',
    'loading_vehicle'      => 'Loading vehicle',
    'on_job'               => 'On the way to drop-off',
    'arrived_dropoff'      => 'Arrived at destination',
    'waiting_verification' => 'Awaiting verification',
    'delayed'              => 'Delayed',
    'completed'            => 'Completed',
    'cancelled'            => 'Cancelled',
    'rejected'             => 'Rejected',
    'not_responding'       => 'You did not respond',
    _                      => status,
  };
}

String scheduledLabelFor({
  DateTime? scheduledFor,
  String? scheduledDate,
  String? scheduledTime,
}) {
  if (scheduledFor != null) {
    return DateFormat('MMM d · h:mm a').format(scheduledFor.toLocal());
  }
  if (scheduledDate == null) return '';
  final d = DateTime.tryParse(scheduledDate);
  if (d == null) return '';
  final datePart = DateFormat('MMM d').format(d);
  if (scheduledTime == null) return datePart;
  final parts = scheduledTime.split(':');
  if (parts.length < 2) return datePart;
  final h = int.tryParse(parts[0]) ?? 0;
  final m = int.tryParse(parts[1]) ?? 0;
  final ampm = h >= 12 ? 'PM' : 'AM';
  final h12 = h % 12 == 0 ? 12 : h % 12;
  return '$datePart · $h12:${m.toString().padLeft(2, '0')} $ampm';
}

double? _d(dynamic v) => v == null ? null : double.tryParse(v.toString());
int? _i(dynamic v) => v == null ? null : int.tryParse(v.toString());

class BookingGroupSibling {
  const BookingGroupSibling({
    required this.bookingCode,
    required this.vehicleTypeName,
    required this.truckTypeName,
    required this.serviceType,
    required this.status,
    this.scheduledDate,
    this.scheduledTime,
    this.scheduledFor,
    this.isCurrent = false,
  });

  final String bookingCode;
  final String? vehicleTypeName;
  final String? truckTypeName;
  final String serviceType;
  final String status;
  final String? scheduledDate;
  final String? scheduledTime;
  final DateTime? scheduledFor;
  final bool isCurrent;

  String get displayVehicleName =>
      (vehicleTypeName != null && vehicleTypeName!.isNotEmpty)
          ? vehicleTypeName!
          : (truckTypeName ?? '');

  String get humanStatus => humanStatusLabel(status);

  String get scheduledLabel => scheduledLabelFor(
        scheduledFor: scheduledFor,
        scheduledDate: scheduledDate,
        scheduledTime: scheduledTime,
      );

  factory BookingGroupSibling.fromJson(Map<String, dynamic> j) {
    return BookingGroupSibling(
      bookingCode: j['booking_code'] as String? ?? '',
      vehicleTypeName: j['vehicle_type_name'] as String?,
      truckTypeName: j['truck_type_name'] as String?,
      serviceType: j['service_type'] as String? ?? 'book_now',
      status: j['status'] as String? ?? '',
      scheduledDate: j['scheduled_date'] as String?,
      scheduledTime: j['scheduled_time'] as String?,
      scheduledFor: j['scheduled_for'] != null
          ? DateTime.tryParse(j['scheduled_for'] as String)
          : null,
      isCurrent: j['is_current'] as bool? ?? false,
    );
  }
}

class GroupTotals {
  const GroupTotals({
    required this.vehicleCount,
    required this.baseRate,
    required this.computedTotal,
    required this.vatAmount,
    required this.additionalFee,
    required this.finalTotal,
  });

  final int vehicleCount;
  final double baseRate;
  final double computedTotal;
  final double vatAmount;
  final double additionalFee;
  final double finalTotal;

  factory GroupTotals.fromJson(Map<String, dynamic> j) {
    return GroupTotals(
      vehicleCount: _i(j['vehicle_count']) ?? 0,
      baseRate: _d(j['base_rate']) ?? 0,
      computedTotal: _d(j['computed_total']) ?? 0,
      vatAmount: _d(j['vat_amount']) ?? 0,
      additionalFee: _d(j['additional_fee']) ?? 0,
      finalTotal: _d(j['final_total']) ?? 0,
    );
  }
}

class GroupVehicleBreakdown {
  const GroupVehicleBreakdown({
    required this.bookingCode,
    required this.status,
    this.vehicleTypeName,
    this.truckTypeName,
    this.baseRate,
    this.distanceFee,
    this.vatAmount,
    this.finalTotal,
    this.pricingIsProvisional = false,
  });

  final String bookingCode;
  final String status;
  final String? vehicleTypeName;
  final String? truckTypeName;
  final double? baseRate;
  final double? distanceFee;
  final double? vatAmount;
  final double? finalTotal;
  final bool pricingIsProvisional;

  String get displayVehicleName =>
      (vehicleTypeName != null && vehicleTypeName!.isNotEmpty)
          ? vehicleTypeName!
          : (truckTypeName ?? '');

  String get humanStatus => humanStatusLabel(status);

  bool get isCancellableByCustomer =>
      BookingModel.cancellableStatuses.contains(status);

  bool get isActiveInGroup =>
      !BookingModel.groupInactiveStatuses.contains(status);

  factory GroupVehicleBreakdown.fromJson(Map<String, dynamic> j) {
    return GroupVehicleBreakdown(
      bookingCode: j['booking_code'] as String? ?? '',
      status: j['status'] as String? ?? '',
      vehicleTypeName: j['vehicle_type_name'] as String?,
      truckTypeName: j['truck_type_name'] as String?,
      baseRate: _d(j['base_rate']),
      distanceFee: _d(j['distance_fee']),
      vatAmount: _d(j['vat_amount']),
      finalTotal: _d(j['final_total']),
      pricingIsProvisional: j['pricing_is_provisional'] as bool? ?? false,
    );
  }
}

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
    this.groupCode,
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
    this.cancelledAt,
    this.priceChangeLog,
    this.truckTypeClass,
    this.distanceFee,
    this.vatAmount,
    this.scheduledFor,
    this.schedulingBucket,
    this.vehicleTypeName,
    this.pricingIsProvisional = false,
    this.groupSiblings,
    this.groupVehicleCount,
    this.groupBookingCode,
    this.quotationNumber,
    this.groupTotals,
    this.groupVehicles,
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
  final String? groupCode;

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
  final DateTime? cancelledAt;
  final List<Map<String, dynamic>>? priceChangeLog;
  final String? truckTypeClass;
  final double? distanceFee;
  final double? vatAmount;
  final DateTime? scheduledFor;
  final String? schedulingBucket;
  final String? vehicleTypeName;
  final bool pricingIsProvisional;
  final List<BookingGroupSibling>? groupSiblings;
  final int? groupVehicleCount;
  final String? groupBookingCode;
  final String? quotationNumber;
  final GroupTotals? groupTotals;
  final List<GroupVehicleBreakdown>? groupVehicles;

  String get displayVehicleName =>
      (vehicleTypeName != null && vehicleTypeName!.isNotEmpty)
          ? vehicleTypeName!
          : truckTypeName;

  String get formattedDate {
    if (createdAt == null) return '';
    return '${createdAt!.month.toString().padLeft(2, '0')}/'
        '${createdAt!.day.toString().padLeft(2, '0')}/'
        '${createdAt!.year}';
  }

  String get humanStatus => humanStatusLabel(status);

  String get scheduledLabel => scheduledLabelFor(
        scheduledFor: scheduledFor,
        scheduledDate: scheduledDate,
        scheduledTime: scheduledTime,
      );

  static const Set<String> cancellableStatuses = {
    'requested',
    'scheduled',
    'scheduled_confirmed',
  };

  static const Set<String> _kHistoryStatuses = {
    'completed',
    'cancelled',
    'rejected',
    'not_responding',
  };

  bool get isCancellableByCustomer => cancellableStatuses.contains(status);

  bool get isHistorical => _kHistoryStatuses.contains(status);

  bool get isGrouped => groupCode != null && groupCode!.isNotEmpty;

  bool get isGroupChildVehicle =>
      isGrouped && groupBookingCode != null && groupBookingCode != bookingCode;

  static const Set<String> groupInactiveStatuses = {
    'cancelled',
    'rejected',
    'not_responding',
  };

  List<String> get groupMemberStatuses {
    if (groupVehicles != null && groupVehicles!.isNotEmpty) {
      return groupVehicles!.map((v) => v.status).toList();
    }
    if (groupSiblings != null && groupSiblings!.isNotEmpty) {
      return [status, ...groupSiblings!.map((s) => s.status)];
    }
    return [status];
  }

  int get groupTotalVehicleCount => groupMemberStatuses.length;

  int get groupActiveVehicleCount => groupMemberStatuses
      .where((s) => !groupInactiveStatuses.contains(s))
      .length;

  bool get groupAllCancelled =>
      groupMemberStatuses.isNotEmpty && groupMemberStatuses.every((s) => s == 'cancelled');

  bool get groupAllCompleted =>
      groupMemberStatuses.isNotEmpty && groupMemberStatuses.every((s) => s == 'completed');

  String get groupActiveStatusText {
    if (groupAllCancelled) return 'Cancelled';
    if (groupAllCompleted) return 'Completed';
    final total = groupTotalVehicleCount;
    return '$groupActiveVehicleCount of $total vehicle${total == 1 ? '' : 's'} active';
  }

  String get groupTotalLabel =>
      groupActiveVehicleCount < groupTotalVehicleCount ? 'Remaining Total' : 'Group Total';

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
      groupCode: j['group_code'] as String?,
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
      cancelledAt: j['cancelled_at'] != null ? DateTime.tryParse(j['cancelled_at'] as String) : null,
      priceChangeLog: (j['price_change_log'] as List?)
          ?.map((e) => Map<String, dynamic>.from(e as Map))
          .toList(),
      truckTypeClass: j['truck_type_class'] as String?,
      distanceFee: _d(j['distance_fee']),
      vatAmount: _d(j['vat_amount']),
      scheduledFor: j['scheduled_for'] != null
          ? DateTime.tryParse(j['scheduled_for'] as String)
          : null,
      schedulingBucket: j['scheduling_bucket'] as String?,
      vehicleTypeName: j['vehicle_type_name'] as String?,
      pricingIsProvisional: j['pricing_is_provisional'] as bool? ?? false,
      groupSiblings: (j['group_siblings'] as List?)
          ?.map((e) => BookingGroupSibling.fromJson(e as Map<String, dynamic>))
          .toList(),
      groupVehicleCount: _i(j['group_vehicle_count']),
      groupBookingCode: j['group_booking_code'] as String?,
      quotationNumber: j['quotation_number'] as String?,
      groupTotals: j['group_totals'] != null
          ? GroupTotals.fromJson(j['group_totals'] as Map<String, dynamic>)
          : null,
      groupVehicles: (j['group_vehicles'] as List?)
          ?.map((e) => GroupVehicleBreakdown.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }
}
