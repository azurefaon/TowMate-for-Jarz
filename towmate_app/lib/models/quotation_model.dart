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
    this.subtotal = 0.0,
    this.vatAmount = 0.0,
    this.vatRate = 0.12,
    this.discount = 0.0,
    this.additionalFee = 0.0,
    this.additionalFeeNote,
    this.truckTypeClass,
    this.pickupNotes,
    this.serviceType,
    this.sourceBookingId,
    this.expiresAt,
    this.sentAt,
    this.priceHistory = const [],
    this.responseNote,
    this.extraVehicles,
    this.priceAdjustments = const [],
  });

  final int id;
  final String quotationNumber;
  final String status;
  final double estimatedPrice;
  final double baseRate;
  final double distanceFee;
  final double subtotal;
  final double vatAmount;
  final double vatRate;
  final double discount;
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
  final List<QuotationSentPrice> priceHistory;
  final String? responseNote;
  final List<QuotationVehicleLine>? extraVehicles;
  final List<QuotationAdjustment> priceAdjustments;

  bool get isGrouped =>
      extraVehicles != null &&
      extraVehicles!.any((v) => v.bookingId != null);

  List<QuotationVehicleLine> get groupVehicles {
    if (!isGrouped) return const [];
    final primary = QuotationVehicleLine(
      truckTypeName: truckTypeName,
      baseRate: baseRate,
      distanceFee: distanceFee,
      vatAmount: vatAmount,
      vatRate: vatRate,
      finalTotal: subtotal + vatAmount,
    );
    return [primary, ...extraVehicles!];
  }

  bool get isExpired => expiresAt != null && expiresAt!.isBefore(DateTime.now());
  bool get isPriceReviewRequested => status == 'price_review_requested';

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
        subtotal: (j['subtotal'] as num? ?? 0).toDouble(),
        vatAmount: (j['vat_amount'] as num? ?? 0).toDouble(),
        vatRate: (j['vat_rate'] as num? ?? 0.12).toDouble(),
        discount: (j['discount'] as num? ?? 0).toDouble(),
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
        priceHistory: (j['price_history'] as List<dynamic>?)
                ?.map((e) => QuotationSentPrice.fromJson(Map<String, dynamic>.from(e as Map)))
                .toList() ??
            const [],
        responseNote: j['response_note'] as String?,
        extraVehicles: (j['extra_vehicles'] as List<dynamic>?)
            ?.map((e) => QuotationVehicleLine.fromJson(Map<String, dynamic>.from(e as Map)))
            .toList(),
        priceAdjustments: (j['price_adjustments'] as List<dynamic>?)
                ?.map((e) => QuotationAdjustment.fromJson(Map<String, dynamic>.from(e as Map)))
                .toList() ??
            const [],
      );
}

/// A price that was actually sent to the customer — reconstructed
/// server-side from the quotation's `quotation_sent` markers (see
/// QuotationService::sentPriceHistory()). Never a raw internal
/// draft/adjustment delta; the backend is authoritative for what counts as
/// "sent", this model only renders what it's given.
class QuotationSentPrice {
  const QuotationSentPrice({
    required this.version,
    required this.price,
    this.sentAt,
  });

  final int version;
  final double price;
  final DateTime? sentAt;

  factory QuotationSentPrice.fromJson(Map<String, dynamic> j) => QuotationSentPrice(
        version: (j['version'] as num).toInt(),
        price: (j['price'] as num).toDouble(),
        sentAt: j['sent_at'] != null ? DateTime.tryParse(j['sent_at'] as String) : null,
      );
}

class QuotationAdjustment {
  const QuotationAdjustment({
    required this.type,
    required this.amount,
    this.reason,
  });

  final String type;
  final double amount;
  final String? reason;

  bool get isDeduction => type == 'deduct';

  String get displayLabel {
    if (reason != null && reason!.trim().isNotEmpty) return reason!;
    return isDeduction ? 'Discount' : 'Additional Fee';
  }

  factory QuotationAdjustment.fromJson(Map<String, dynamic> j) => QuotationAdjustment(
        type: j['type'] as String? ?? 'add',
        amount: (j['amount'] as num? ?? 0).toDouble(),
        reason: j['reason'] as String?,
      );
}

class QuotationVehicleLine {
  const QuotationVehicleLine({
    this.bookingId,
    this.vehicleName,
    this.truckTypeName,
    this.baseRate = 0.0,
    this.distanceFee = 0.0,
    this.vatAmount = 0.0,
    this.vatRate = 0.12,
    this.finalTotal = 0.0,
  });

  final int? bookingId;
  final String? vehicleName;
  final String? truckTypeName;
  final double baseRate;
  final double distanceFee;
  final double vatAmount;
  final double vatRate;
  final double finalTotal;

  String get displayName => vehicleName ?? truckTypeName ?? 'Tow Truck';

  factory QuotationVehicleLine.fromJson(Map<String, dynamic> j) => QuotationVehicleLine(
        bookingId: (j['booking_id'] as num?)?.toInt(),
        vehicleName: j['vehicle_name'] as String?,
        truckTypeName: j['truck_type_name'] as String?,
        baseRate: (j['base_rate'] as num? ?? 0).toDouble(),
        distanceFee: (j['distance_fee'] as num? ?? 0).toDouble(),
        vatAmount: (j['vat_amount'] as num? ?? 0).toDouble(),
        vatRate: (j['vat_rate'] as num? ?? 0.12).toDouble(),
        finalTotal: (j['final_total'] as num? ?? j['estimated_price'] as num? ?? 0).toDouble(),
      );
}
