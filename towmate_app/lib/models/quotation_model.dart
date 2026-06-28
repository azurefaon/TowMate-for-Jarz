class QuotationModel {
  const QuotationModel({
    required this.id,
    required this.quotationNumber,
    required this.status,
    required this.estimatedPrice,
    required this.distanceKm,
    required this.pickupAddress,
    required this.dropoffAddress,
    required this.truckTypeName,
    this.baseRate = 0.0,
    this.distanceFee = 0.0,
    this.vatAmount = 0.0,
    this.additionalFee = 0.0,
    this.additionalFeeNote,
    this.truckTypeClass,
    this.pickupNotes,
    this.serviceType,
    this.sourceBookingId,
    this.expiresAt,
    this.sentAt,
    this.priceChangeLog,
  });

  final int id;
  final String quotationNumber;
  final String status;
  final double estimatedPrice;
  final double baseRate;
  final double distanceFee;
  final double vatAmount;
  final double additionalFee;
  final String? additionalFeeNote;
  final double distanceKm;
  final String pickupAddress;
  final String dropoffAddress;
  final String truckTypeName;
  final String? truckTypeClass;
  final String? pickupNotes;
  final String? serviceType;
  final int? sourceBookingId;
  final DateTime? expiresAt;
  final DateTime? sentAt;
  final List<Map<String, dynamic>>? priceChangeLog;

  bool get isExpired => expiresAt != null && expiresAt!.isBefore(DateTime.now());

  Duration? get timeRemaining {
    if (expiresAt == null) return null;
    final r = expiresAt!.difference(DateTime.now());
    return r.isNegative ? Duration.zero : r;
  }

  factory QuotationModel.fromJson(Map<String, dynamic> j) => QuotationModel(
        id: (j['id'] as num).toInt(),
        quotationNumber: j['quotation_number'] as String,
        status: j['status'] as String,
        estimatedPrice: (j['estimated_price'] as num).toDouble(),
        baseRate: (j['base_rate'] as num? ?? 0).toDouble(),
        distanceFee: (j['distance_fee'] as num? ?? 0).toDouble(),
        vatAmount: (j['vat_amount'] as num? ?? 0).toDouble(),
        additionalFee: (j['additional_fee'] as num? ?? 0).toDouble(),
        additionalFeeNote: j['additional_fee_note'] as String?,
        distanceKm: (j['distance_km'] as num).toDouble(),
        pickupAddress: j['pickup_address'] as String,
        dropoffAddress: j['dropoff_address'] as String,
        truckTypeName: j['truck_type_name'] as String? ?? '',
        truckTypeClass: j['truck_type_class'] as String?,
        pickupNotes: j['pickup_notes'] as String?,
        serviceType: j['service_type'] as String?,
        sourceBookingId: (j['source_booking_id'] as num?)?.toInt(),
        expiresAt: j['expires_at'] != null ? DateTime.tryParse(j['expires_at'] as String) : null,
        sentAt: j['sent_at'] != null ? DateTime.tryParse(j['sent_at'] as String) : null,
        priceChangeLog: (j['price_change_log'] as List<dynamic>?)
            ?.map((e) => Map<String, dynamic>.from(e as Map))
            .toList(),
      );
}
