/// Single source of truth for how a backend booking status maps onto the
/// Team Leader's 6-step operational cycle. (Badge colours live in
/// status_style.dart so every screen shares one mapping.)
///
/// `assigned` is deliberately NOT part of the cycle: the TL has not accepted
/// the task yet, so it has no step and must never be presented as one.
abstract final class TlTaskFlow {
  static const int totalSteps = 6;

  static const _stepStatuses = <List<String>>[
    ['accepted', 'on_the_way'],
    ['arrived_pickup', 'in_progress', 'loading_vehicle'],
    ['on_job'],
    ['arrived_dropoff'],
    ['waiting_verification'],
    ['completed'],
  ];

  static const _stepLabels = ['Route', 'Arrived', 'Towing', 'Dropoff', 'Pending Payment', 'Completed'];

  /// 1-based step for [status], or null when it is not in the operational
  /// cycle (assigned, returned, unknown).
  static int? stepFor(String status) {
    final i = _stepStatuses.indexWhere((s) => s.contains(status));
    return i < 0 ? null : i + 1;
  }

  static String? stepLabelFor(String status) {
    final step = stepFor(status);
    return step == null ? null : _stepLabels[step - 1];
  }

  /// True only for statuses the operational shell may render as a live task.
  static bool isOperational(String status) => stepFor(status) != null && status != 'completed';
}
