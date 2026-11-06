/// What the backend says about an online (PayMongo) payment. Everything here is read from the Laravel API;
/// nothing is inferred from a browser redirect or from the checkout page having been opened.
library;

import 'booking_model.dart' show BookingGroupSibling;

/// A transaction's verified state, as the backend reports it.
enum OnlinePaymentStatus {
  pending,
  paid,
  failed,
  cancelled,
  expired,

  /// Money may have moved but a human must look (amount mismatch, duplicate, paid after close...).
  review,

  /// A status this app version does not know. Treated cautiously: never shown as paid.
  unknown;

  static OnlinePaymentStatus parse(String? value) => switch (value) {
        'pending' => pending,
        'paid' => paid,
        'failed' => failed,
        'cancelled' => cancelled,
        'expired' => expired,
        'review' || 'duplicate' => review,
        _ => unknown,
      };

  /// No further change is expected without a new action.
  bool get isFinal => this != pending;

  /// Only a backend-verified `paid` counts as paid. Nothing else ever does.
  bool get isPaid => this == paid;

  /// The customer can start a fresh attempt.
  bool get canRetry => this == failed || this == cancelled || this == expired;
}

class OnlinePaymentTransaction {
  const OnlinePaymentTransaction({
    required this.reference,
    required this.status,
    required this.amount,
    required this.currency,
    required this.method,
    this.bank,
    this.expiresAt,
    this.paidAt,
    this.checkoutUrl,
    this.bookingStatus,
    this.bookingCompleted = false,
    this.bookingSubmitted = false,
    this.payAtBooking = false,
    this.bookings = const [],
  });

  final String reference;
  final OnlinePaymentStatus status;

  /// Peso amount as the server formatted it (e.g. "100.00"). Display only; the server decides what is charged.
  final String amount;
  final String currency;
  final String method;

  /// Online Banking only: the bank chosen for this attempt, so a retry can use it again.
  final String? bank;
  final DateTime? expiresAt;
  final DateTime? paidAt;

  /// Present only while the session is open. Never stored or shown, only handed to the secure browser.
  final String? checkoutUrl;

  /// The booking's own status. A paid payment is NOT a completed booking.
  final String? bookingStatus;
  final bool bookingCompleted;

  /// Pay & Submit: true once the server has submitted the booking after the verified payment. Until then the booking
  /// does not exist for dispatch, whatever the browser showed.
  final bool bookingSubmitted;
  final bool payAtBooking;

  /// The submitted booking(s), for the "Booking successful" screen (empty until submitted).
  final List<BookingGroupSibling> bookings;

  factory OnlinePaymentTransaction.fromJson(Map<String, dynamic> json) {
    return OnlinePaymentTransaction(
      reference: json['reference'] as String? ?? '',
      status: OnlinePaymentStatus.parse(json['status'] as String?),
      amount: json['amount']?.toString() ?? '0.00',
      currency: json['currency'] as String? ?? 'PHP',
      method: json['method'] as String? ?? 'gcash',
      bank: json['bank'] as String?,
      expiresAt: DateTime.tryParse(json['expires_at'] as String? ?? ''),
      paidAt: DateTime.tryParse(json['paid_at'] as String? ?? ''),
      checkoutUrl: json['checkout_url'] as String?,
      bookingStatus: json['booking_status'] as String?,
      bookingCompleted: json['booking_completed'] as bool? ?? false,
      bookingSubmitted: json['booking_submitted'] as bool? ?? false,
      payAtBooking: json['pay_at_booking'] as bool? ?? false,
      bookings: (json['bookings'] as List?)
              ?.whereType<Map<String, dynamic>>()
              .map(BookingGroupSibling.fromJson)
              .toList() ??
          const [],
    );
  }
}

/// A bank offered for Online Banking. The server decides which banks can really be paid with.
class OnlineBank {
  const OnlineBank({required this.code, required this.label});

  final String code;
  final String label;

  factory OnlineBank.fromJson(Map<String, dynamic> json) =>
      OnlineBank(code: json['code'] as String? ?? '', label: json['label'] as String? ?? '');
}

/// What Step 3 may offer as "Pay & Submit" for this account. Online Banking is offered only with at least one bank.
class OnlinePaymentOptions {
  const OnlinePaymentOptions({this.channels = const {}, this.banks = const []});

  /// A subset of {'gcash', 'bank_transfer'}.
  final Set<String> channels;
  final List<OnlineBank> banks;

  factory OnlinePaymentOptions.fromJson(Map<String, dynamic> json) {
    if (json['available'] != true) return const OnlinePaymentOptions();

    final channels = json['channels'];
    final banks = (json['banks'] as List?)
            ?.whereType<Map<String, dynamic>>()
            .map(OnlineBank.fromJson)
            .where((bank) => bank.code.isNotEmpty)
            .toList() ??
        const <OnlineBank>[];

    return OnlinePaymentOptions(
      channels: {
        if (channels is! Map<String, dynamic> || channels['gcash'] == true) 'gcash',
        if (banks.isNotEmpty) 'bank_transfer',
      },
      banks: banks,
    );
  }
}

/// The server-decided "Pay Now" offer carried by the booking detail. Absent (null on the booking) when online
/// payment is not available to this customer at all.
class OnlinePaymentOffer {
  const OnlinePaymentOffer({
    required this.eligible,
    required this.amount,
    required this.currency,
    required this.method,
    required this.methodLabel,
    this.reason,
    this.isGroup = false,
    this.transaction,
  });

  final bool eligible;
  final String? reason;
  final String amount;
  final String currency;
  final String method;
  final String methodLabel;
  final bool isGroup;
  final OnlinePaymentTransaction? transaction;

  /// The card is worth showing only when there is something to do or to report.
  bool get worthShowing => eligible || transaction != null;

  static OnlinePaymentOffer? fromJson(Object? json) {
    if (json is! Map<String, dynamic> || json['available'] != true) return null;

    final transaction = json['transaction'];
    return OnlinePaymentOffer(
      eligible: json['eligible'] as bool? ?? false,
      reason: json['reason'] as String?,
      amount: json['amount']?.toString() ?? '0.00',
      currency: json['currency'] as String? ?? 'PHP',
      method: json['method'] as String? ?? 'gcash',
      methodLabel: json['method_label'] as String? ?? 'GCash',
      isGroup: json['is_group'] as bool? ?? false,
      transaction: transaction is Map<String, dynamic> ? OnlinePaymentTransaction.fromJson(transaction) : null,
    );
  }
}

/// Outcome of asking the backend for the current verified state.
class OnlinePaymentFetch {
  const OnlinePaymentFetch._({required this.reachable, required this.available, this.transaction});

  /// Could not reach the backend (or it errored). The previous state is kept; nothing is concluded.
  const OnlinePaymentFetch.unreachable() : this._(reachable: false, available: true);

  /// The backend answered 404: online payment is not available for this customer or booking.
  const OnlinePaymentFetch.unavailable() : this._(reachable: true, available: false);

  const OnlinePaymentFetch.result(OnlinePaymentTransaction? transaction)
      : this._(reachable: true, available: true, transaction: transaction);

  final bool reachable;
  final bool available;

  /// Null when reachable but no payment has been started.
  final OnlinePaymentTransaction? transaction;
}

/// The customer-facing phase. `verifying` is a UI phase (a check is in flight or has not answered yet); it is
/// never a claim about the payment.
enum OnlinePaymentPhase {
  notStarted('Not paid yet'),
  verifying('Verifying Payment'),
  pending('Payment Pending'),
  paid('Paid'),
  failed('Failed'),
  cancelled('Cancelled'),
  expired('Payment Expired'),
  review('Under Review');

  const OnlinePaymentPhase(this.label);

  final String label;

  static OnlinePaymentPhase of(OnlinePaymentTransaction? transaction, {required bool checking}) {
    if (transaction == null) return checking ? verifying : notStarted;

    return switch (transaction.status) {
      OnlinePaymentStatus.pending => checking ? verifying : pending,
      OnlinePaymentStatus.paid => paid,
      OnlinePaymentStatus.failed => failed,
      OnlinePaymentStatus.cancelled => cancelled,
      OnlinePaymentStatus.expired => expired,
      OnlinePaymentStatus.review => review,
      // A status this build does not understand is never presented as paid.
      OnlinePaymentStatus.unknown => checking ? verifying : pending,
    };
  }
}
