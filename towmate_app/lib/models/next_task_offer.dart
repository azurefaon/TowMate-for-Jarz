/// A dispatcher's offer of a next task, queued behind the job the Team Leader is finishing.
///
/// Accepting only reserves the task; it becomes a normal assignment when dispatch assigns it
/// after the current job is released.
class NextTaskOffer {
  final int id;
  final String status; // 'offered' | 'accepted'
  final String bookingCode;
  final String? currentBookingCode;
  final String? pickupAddress;
  final String? dropoffAddress;
  final String? towClass;
  final String? vehicleInfo;
  final bool isScheduled;
  final DateTime? scheduledFor;

  /// Seconds left when this offer was received from the server. The countdown is anchored to the
  /// server's clock through this value, never to the device clock, and can only count down.
  final int? secondsLeftAtReceipt;
  final DateTime receivedAt;

  NextTaskOffer({
    required this.id,
    required this.status,
    required this.bookingCode,
    this.currentBookingCode,
    this.pickupAddress,
    this.dropoffAddress,
    this.towClass,
    this.vehicleInfo,
    this.isScheduled = false,
    this.scheduledFor,
    this.secondsLeftAtReceipt,
    DateTime? receivedAt,
  }) : receivedAt = receivedAt ?? DateTime.now();

  bool get isReserved => status == 'accepted';

  /// Whole seconds still available to answer, or null once it is reserved.
  int? secondsLeft([DateTime? now]) {
    final base = secondsLeftAtReceipt;
    if (isReserved || base == null) return null;
    // A device clock moved backwards must never stretch the server's deadline.
    final elapsed = (now ?? DateTime.now()).difference(receivedAt).inSeconds.clamp(0, 1 << 30);
    final left = base - elapsed;
    return left < 0 ? 0 : left;
  }

  bool isExpired([DateTime? now]) => secondsLeft(now) == 0;

  factory NextTaskOffer.fromJson(Map<String, dynamic> json) {
    final booking = (json['booking'] as Map?)?.cast<String, dynamic>() ?? const {};
    return NextTaskOffer(
      id: (json['id'] as num).toInt(),
      status: json['status']?.toString() ?? 'offered',
      bookingCode: booking['booking_code']?.toString() ?? '',
      currentBookingCode: json['current_booking_code']?.toString(),
      pickupAddress: booking['pickup_address']?.toString(),
      dropoffAddress: booking['dropoff_address']?.toString(),
      towClass: booking['tow_class']?.toString(),
      vehicleInfo: booking['vehicle_info']?.toString(),
      isScheduled: booking['is_scheduled'] == true,
      scheduledFor: DateTime.tryParse(booking['scheduled_for']?.toString() ?? '')?.toLocal(),
      secondsLeftAtReceipt: (json['seconds_left'] as num?)?.toInt(),
    );
  }
}
