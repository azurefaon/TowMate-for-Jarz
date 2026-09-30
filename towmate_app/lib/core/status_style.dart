import 'package:flutter/material.dart';

/// The ONE mapping from a backend booking/task status to its badge colours.
/// Every status chip in the app (Team Leader and Customer) reads this, so the
/// same status always has the same semantic colour family.
///
/// Rules (both themes): SOLID fills only, never tinted/translucent; label
/// text >= 4.5:1 against its fill. Light mode prefers white text on a deep
/// fill; dark mode uses a slightly lighter solid fill (still white text) so
/// the chip stays distinct from the dark surfaces without going neon.
/// Amber is the one family that takes near-black text in both themes.
class StatusStyle {
  const StatusStyle._(this.background, this.foreground, this.darkBackground, this.darkForeground);

  final Color background;
  final Color foreground;
  final Color darkBackground;
  final Color darkForeground;

  Color backgroundFor(Brightness b) => b == Brightness.dark ? darkBackground : background;
  Color foregroundFor(Brightness b) => b == Brightness.dark ? darkForeground : foreground;

  static const _white = Color(0xFFFFFFFF);
  static const _ink = Color(0xFF171717);

  // -- families -------------------------------------------------------------
  static const amber = StatusStyle._(Color(0xFFFACC15), _ink, Color(0xFFFACC15), _ink);
  static const blue = StatusStyle._(Color(0xFF1D4ED8), _white, Color(0xFF2563EB), _white);
  static const violet = StatusStyle._(Color(0xFF6D28D9), _white, Color(0xFF7C3AED), _white);
  static const orange = StatusStyle._(Color(0xFFC2410C), _white, Color(0xFFC9440C), _white);
  static const teal = StatusStyle._(Color(0xFF0F766E), _white, Color(0xFF0D8379), _white);
  static const brown = StatusStyle._(Color(0xFFB45309), _white, Color(0xFFB85A0A), _white);
  static const green = StatusStyle._(Color(0xFF2E7D32), _white, Color(0xFF2C8634), _white);
  static const red = StatusStyle._(Color(0xFFB91C1C), _white, Color(0xFFDC2626), _white);
  static const slate = StatusStyle._(Color(0xFF475569), _white, Color(0xFF64748B), _white);
  static const indigo = StatusStyle._(Color(0xFF4338CA), _white, Color(0xFF5B50E0), _white);

  /// Safe readable fallback for anything unmapped. Deliberately not any
  /// lifecycle family, so an unknown status never masquerades as a known one.
  static const neutral = StatusStyle._(Color(0xFF52525B), _white, Color(0xFF6B7280), _white);

  static const _byStatus = <String, StatusStyle>{
    // Team Leader operational lifecycle.
    'assigned': amber,
    'accepted': blue,
    'on_the_way': blue,
    'arrived_pickup': violet,
    'in_progress': violet,
    'loading_vehicle': violet,
    'on_job': orange,
    'arrived_dropoff': teal,
    'waiting_verification': brown,
    'completed': green,
    'returned': red,
    // Customer-visible pre-dispatch / scheduling / terminal statuses.
    'requested': slate,
    'reviewed': slate,
    'quoted': slate,
    'quotation_sent': slate,
    'scheduled': indigo,
    'scheduled_confirmed': indigo,
    'confirmed': indigo,
    'delayed': brown,
    'cancelled': red,
    'rejected': red,
    'not_responding': red,
    // Scheduling-bucket banners on the booking detail screen.
    'overdue': red,
    'ready': amber,
  };

  /// Every status with an explicit mapping (used by tests and audits).
  static Iterable<String> get knownStatuses => _byStatus.keys;

  static bool isKnown(String status) => _byStatus.containsKey(status);

  static StatusStyle of(String status) => _byStatus[status] ?? neutral;

  /// Readable label for an unmapped status: 'some_status' -> 'Some Status'.
  static String fallbackLabel(String status) => status
      .split('_')
      .where((w) => w.isNotEmpty)
      .map((w) => '${w[0].toUpperCase()}${w.substring(1)}')
      .join(' ');
}
