import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../core/route_observer.dart';
import '../core/status_style.dart';
import '../core/theme.dart';
import '../models/booking_model.dart' show humanStatusLabel;
import '../models/tracking_model.dart';
import '../services/live_tracking_controller.dart';
import 'status_badge.dart';

/// Route used to open the dedicated Live Tracking map for one booking_code.
const String kLiveTrackingRoute = '/live-tracking';

void openLiveTracking(BuildContext context, String bookingCode) {
  Navigator.pushNamed(context, kLiveTrackingRoute, arguments: bookingCode);
}

// ---------------------------------------------------------------------------
// Scope: binds one widget to the shared controller for one booking_code.
// ---------------------------------------------------------------------------

typedef LiveTrackingWidgetBuilder = Widget Function(BuildContext context, LiveTrackingController? controller);

/// Acquires the shared [LiveTrackingController] for [bookingCode] while
/// [enabled], pauses its claim while this screen is covered by another route,
/// and releases it on dispose. [onEnded] fires once each time the backend
/// switches from tracking to not tracking, so the host can refresh its data.
class LiveTrackingScope extends StatefulWidget {
  const LiveTrackingScope({
    super.key,
    required this.bookingCode,
    required this.enabled,
    required this.builder,
    this.status,
    this.onEnded,
  });

  final String bookingCode;
  final bool enabled;

  /// The host's own view of the booking status; a change re-checks tracking.
  final String? status;
  final VoidCallback? onEnded;
  final LiveTrackingWidgetBuilder builder;

  @override
  State<LiveTrackingScope> createState() => _LiveTrackingScopeState();
}

class _LiveTrackingScopeState extends State<LiveTrackingScope> with RouteAware {
  LiveTrackingHandle? _handle;
  PageRoute<dynamic>? _route;
  bool _covered = false;
  bool _wasEnded = false;

  LiveTrackingController? get _controller => _handle?.controller;

  @override
  void initState() {
    super.initState();
    if (widget.enabled) _acquire();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final route = ModalRoute.of(context);
    if (route is PageRoute && route != _route) {
      if (_route != null) appRouteObserver.unsubscribe(this);
      _route = route;
      appRouteObserver.subscribe(this, route);
    }
  }

  @override
  void didUpdateWidget(covariant LiveTrackingScope old) {
    super.didUpdateWidget(old);
    if (old.bookingCode != widget.bookingCode || old.enabled != widget.enabled) {
      _releaseHandle();
      if (widget.enabled) _acquire();
    } else if (widget.enabled && old.status != widget.status) {
      // Host data moved (e.g. on_the_way -> on_job): re-check right away.
      _controller?.start();
    }
  }

  void _acquire() {
    final handle = LiveTrackingRegistry.instance.acquire(widget.bookingCode);
    _handle = handle;
    _wasEnded = handle.controller.ended;
    handle.controller.addListener(_onChanged);
    if (_covered) handle.setActive(false);
  }

  void _releaseHandle() {
    final handle = _handle;
    if (handle == null) return;
    handle.controller.removeListener(_onChanged);
    handle.release();
    _handle = null;
  }

  void _onChanged() {
    if (!mounted) return;
    final ended = _controller?.ended ?? false;
    final justEnded = ended && !_wasEnded;
    _wasEnded = ended;
    setState(() {});
    if (justEnded) widget.onEnded?.call();
  }

  @override
  void didPushNext() {
    _covered = true;
    _handle?.setActive(false);
  }

  @override
  void didPopNext() {
    _covered = false;
    _handle?.setActive(true);
  }

  @override
  void dispose() {
    if (_route != null) appRouteObserver.unsubscribe(this);
    _releaseHandle();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => widget.builder(context, widget.enabled ? _controller : null);
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

String formatTrackingEta(double minutes) {
  if (minutes < 1) return '< 1 min';
  final total = minutes.round();
  if (total < 60) return '$total min';
  final h = total ~/ 60;
  final m = total % 60;
  return m == 0 ? '$h h' : '$h h $m min';
}

String formatTrackingDistance(double km, {bool approximate = false}) {
  final text = km < 1 ? '${(km * 1000).round()} m' : '${km.toStringAsFixed(1)} km';
  return approximate ? '≈ $text' : text;
}

String _ageText(int seconds) {
  if (seconds < 60) return '${seconds}s ago';
  final minutes = seconds ~/ 60;
  if (minutes < 60) return '$minutes min ago';
  final hours = minutes ~/ 60;
  return '$hours h ago';
}

/// Wording for the age of the location, based ONLY on what the API returned.
String? trackingAgeText(TrackingSnapshot s) {
  final loc = s.location;
  if (loc != null) {
    return loc.ageSeconds <= 10 ? 'Updated just now' : 'Updated ${_ageText(loc.ageSeconds)}';
  }
  final last = s.lastSeenAgeSeconds;
  if (last != null) return 'Last updated ${_ageText(last)}';
  return null;
}

String trackingEtaLabel(String? phase, String? status) {
  final pickup = phase == 'pickup' || (phase == null && status == 'on_the_way');
  return pickup ? 'ETA TO PICKUP' : 'ETA TO DROP-OFF';
}

Color _muted(BuildContext context) => context.isDark ? const Color(0xFFA3A3A3) : const Color(0xFF6B6B6B);

// ---------------------------------------------------------------------------
// Building blocks
// ---------------------------------------------------------------------------

/// Small dot + label. Colours come from the shared [StatusStyle] families.
class TrackingFreshnessIndicator extends StatelessWidget {
  const TrackingFreshnessIndicator({super.key, required this.controller});
  final LiveTrackingController? controller;

  @override
  Widget build(BuildContext context) {
    final snap = controller?.snapshot;
    final freshness = snap?.freshness;
    final (StatusStyle style, String label) = switch (freshness) {
      TrackingFreshness.live => (StatusStyle.green, 'Live'),
      TrackingFreshness.updating => (StatusStyle.amber, 'Updating'),
      TrackingFreshness.unavailable => (StatusStyle.neutral, 'Unavailable'),
      null => (StatusStyle.neutral, 'Connecting'),
    };
    final age = snap == null ? null : trackingAgeText(snap);
    final dot = style.backgroundFor(Theme.of(context).brightness);

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          key: const ValueKey('tracking-freshness-dot'),
          width: 8,
          height: 8,
          decoration: BoxDecoration(color: dot, shape: BoxShape.circle),
        ),
        const SizedBox(width: 6),
        Text(
          label,
          style: GoogleFonts.inter(color: context.textPrimary, fontSize: 12.5, fontWeight: FontWeight.w700),
        ),
        if (age != null && freshness != TrackingFreshness.unavailable) ...[
          Text(' · ', style: GoogleFonts.inter(color: _muted(context), fontSize: 12.5)),
          Flexible(
            child: Text(
              age,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: GoogleFonts.inter(color: _muted(context), fontSize: 12.5),
            ),
          ),
        ],
      ],
    );
  }
}

/// Calm unavailable state: no truck, no ETA, no distance.
class TrackingUnavailableNotice extends StatelessWidget {
  const TrackingUnavailableNotice({super.key, required this.snapshot});
  final TrackingSnapshot? snapshot;

  @override
  Widget build(BuildContext context) {
    final age = snapshot?.lastSeenAgeSeconds != null ? trackingAgeText(snapshot!) : null;
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(Icons.location_searching_rounded, size: 18, color: _muted(context)),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Live location temporarily unavailable',
                style: GoogleFonts.inter(color: context.textPrimary, fontSize: 13.5, fontWeight: FontWeight.w600),
              ),
              const SizedBox(height: 2),
              Text(
                age ?? "We'll keep checking and update this automatically.",
                style: GoogleFonts.inter(color: _muted(context), fontSize: 12.5, height: 1.35),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// ETA and Distance side by side. Muted while freshness is "updating".
class TrackingEtaDistance extends StatelessWidget {
  const TrackingEtaDistance({super.key, required this.controller, required this.etaLabel, this.large = false});
  final LiveTrackingController controller;
  final String etaLabel;
  final bool large;

  @override
  Widget build(BuildContext context) {
    final updating = controller.snapshot?.freshness == TrackingFreshness.updating;
    final eta = controller.etaMinutes;
    final km = controller.distanceKm;
    final valueColor = updating ? _muted(context) : context.textPrimary;

    Widget cell(String label, String value, Key key) => Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                label,
                style: GoogleFonts.inter(
                  color: _muted(context),
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 1.0,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                value,
                key: key,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: GoogleFonts.inter(
                  color: valueColor,
                  fontSize: large ? 24 : 20,
                  fontWeight: FontWeight.w800,
                  letterSpacing: -0.4,
                ),
              ),
            ],
          ),
        );

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        cell(etaLabel, eta != null ? formatTrackingEta(eta) : '—', const ValueKey('tracking-eta')),
        const SizedBox(width: 12),
        cell(
          'DISTANCE',
          km != null ? formatTrackingDistance(km, approximate: controller.distanceIsApproximate) : '—',
          const ValueKey('tracking-distance'),
        ),
      ],
    );
  }
}

/// Body shared by the Home block and the detail card: connecting /
/// unavailable / ETA+distance, plus freshness.
class _TrackingBody extends StatelessWidget {
  const _TrackingBody({required this.controller, required this.etaLabel});
  final LiveTrackingController? controller;
  final String etaLabel;

  @override
  Widget build(BuildContext context) {
    final c = controller;
    final snap = c?.snapshot;
    final Widget main;
    if (c == null || snap == null) {
      main = Text(
        'Connecting to live location…',
        style: GoogleFonts.inter(color: _muted(context), fontSize: 13),
      );
    } else if (!snap.hasCurrentLocation) {
      main = TrackingUnavailableNotice(snapshot: snap);
    } else {
      main = TrackingEtaDistance(controller: c, etaLabel: etaLabel);
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        main,
        const SizedBox(height: 10),
        TrackingFreshnessIndicator(controller: c),
      ],
    );
  }
}

/// Home: compact ETA / distance / freshness block inside the Current
/// Booking card (single booking) or a group row.
class LiveTrackingCompactBlock extends StatelessWidget {
  const LiveTrackingCompactBlock({super.key, required this.controller, required this.status});
  final LiveTrackingController? controller;
  final String status;

  @override
  Widget build(BuildContext context) {
    return Container(
      key: const ValueKey('home-live-tracking-block'),
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: context.isDark ? TmColors.dark700 : TmColors.grey100,
        borderRadius: BorderRadius.circular(16),
      ),
      child: _TrackingBody(
        controller: controller,
        etaLabel: trackingEtaLabel(controller?.snapshot?.phase, status),
      ),
    );
  }
}

/// Yellow pill action used on Home for "Track live".
class TrackLiveButton extends StatelessWidget {
  const TrackLiveButton({super.key, required this.onTap, this.compact = false});
  final VoidCallback onTap;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: TmColors.yellow,
      borderRadius: BorderRadius.circular(compact ? 12 : 14),
      child: InkWell(
        borderRadius: BorderRadius.circular(compact ? 12 : 14),
        onTap: onTap,
        child: Padding(
          padding: compact
              ? const EdgeInsets.symmetric(horizontal: 14, vertical: 9)
              : const EdgeInsets.symmetric(horizontal: 22, vertical: 14),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Flexible(
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  child: Text(
                    'Track live',
                    maxLines: 1,
                    style: GoogleFonts.inter(
                      color: TmColors.black,
                      fontSize: compact ? 13.5 : 16,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
              ),
              SizedBox(width: compact ? 4 : 8),
              Icon(Icons.chevron_right, size: compact ? 18 : 20, color: TmColors.black),
            ],
          ),
        ),
      ),
    );
  }
}

/// Booking Details: the Live Tracking card (single booking) — and, with
/// [title], one row of the group LIVE TRACKING section.
class LiveTrackingDetailCard extends StatelessWidget {
  const LiveTrackingDetailCard({
    super.key,
    required this.bookingCode,
    required this.status,
    required this.controller,
    this.title,
  });

  final String bookingCode;
  final String status;
  final LiveTrackingController? controller;

  /// Group rows: "Vehicle N · type". Null for the single-booking card.
  final String? title;

  @override
  Widget build(BuildContext context) {
    final phase = controller?.snapshot?.phase;
    final pickup = phase == 'pickup' || (phase == null && status == 'on_the_way');
    final buttonLabel = pickup ? 'Track tow truck' : 'Track vehicle';

    return Container(
      key: ValueKey('detail-live-tracking-$bookingCode'),
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: context.card,
        border: Border.all(color: context.divider),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (title != null) ...[
            Text(
              title!,
              style: GoogleFonts.inter(
                color: context.textPrimary,
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
                letterSpacing: 0.1,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              bookingCode,
              style: GoogleFonts.inter(color: secondaryTrackingText(context), fontSize: 12, letterSpacing: 0.1),
            ),
            const SizedBox(height: 8),
          ],
          Row(
            children: [
              Flexible(child: StatusBadge(status: status, label: humanStatusLabel(status), compact: true)),
              const SizedBox(width: 10),
              Flexible(child: TrackingFreshnessIndicator(controller: controller)),
            ],
          ),
          const SizedBox(height: 12),
          _detailMain(context, pickup),
          const SizedBox(height: 14),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton.icon(
              onPressed: () => openLiveTracking(context, bookingCode),
              icon: const Icon(Icons.local_shipping_outlined, color: TmColors.black, size: 18),
              label: Text(
                buttonLabel,
                style: GoogleFonts.inter(color: TmColors.black, fontSize: 14.5, fontWeight: FontWeight.w700),
              ),
              style: ElevatedButton.styleFrom(
                backgroundColor: TmColors.yellow,
                foregroundColor: TmColors.black,
                minimumSize: const Size(double.infinity, 46),
                elevation: 0,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _detailMain(BuildContext context, bool pickup) {
    final c = controller;
    final snap = c?.snapshot;
    if (c == null || snap == null) {
      return Text('Connecting to live location…', style: GoogleFonts.inter(color: _muted(context), fontSize: 13));
    }
    if (!snap.hasCurrentLocation) return TrackingUnavailableNotice(snapshot: snap);
    return TrackingEtaDistance(controller: c, etaLabel: pickup ? 'ETA TO PICKUP' : 'ETA TO DROP-OFF');
  }
}

Color secondaryTrackingText(BuildContext context) => _muted(context);
