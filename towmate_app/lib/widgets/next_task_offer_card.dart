import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';
import '../models/next_task_offer.dart';
import '../services/team_leader_service.dart';

/// Secondary card shown under the current active task: a dispatcher's offer of a next task.
///
/// Accepting only reserves the task. It does not start, replace or interrupt the current job.
/// Declining releases it. The offer is refreshed with the task polling the screen already does;
/// there is no background push for this.
class NextTaskOfferCard extends StatefulWidget {
  final NextTaskOffer offer;

  /// Called after an answer (or when the offer turns out to be gone) so the owner re-fetches.
  final VoidCallback onChanged;

  const NextTaskOfferCard({super.key, required this.offer, required this.onChanged});

  @override
  State<NextTaskOfferCard> createState() => _NextTaskOfferCardState();
}

class _NextTaskOfferCardState extends State<NextTaskOfferCard> {
  Timer? _ticker;
  bool _busy = false;
  String? _error;
  late NextTaskOffer _offer;

  @override
  void initState() {
    super.initState();
    _offer = widget.offer;
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      setState(() {});
      if (_offer.isExpired() && !_busy) {
        _ticker?.cancel();
        widget.onChanged(); // the server decides; the card disappears once it says so
      }
    });
  }

  @override
  void didUpdateWidget(NextTaskOfferCard old) {
    super.didUpdateWidget(old);
    if (old.offer.id != widget.offer.id || old.offer.status != widget.offer.status ||
        old.offer.receivedAt != widget.offer.receivedAt) {
      _offer = widget.offer;
    }
  }

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  Future<void> _answer(bool accept) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final result = accept
        ? await TeamLeaderService.acceptNextOffer(_offer.id)
        : await TeamLeaderService.declineNextOffer(_offer.id);
    if (!mounted) return;
    if (result['success'] == true) {
      final updated = result['offer'];
      setState(() {
        _busy = false;
        if (updated is NextTaskOffer) _offer = updated;
      });
      widget.onChanged();
      return;
    }
    setState(() {
      _busy = false;
      _error = result['message']?.toString() ?? 'Could not send your answer. Try again.';
    });
    if (result['stale'] == true) widget.onChanged();
  }

  String _clock(int seconds) {
    final m = seconds ~/ 60;
    final s = seconds % 60;
    return '$m:${s.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    final left = _offer.secondsLeft();
    final reserved = _offer.isReserved;
    final border = reserved ? TmColors.success : TmColors.yellow;

    return Semantics(
      container: true,
      label: reserved ? 'Next task reserved' : 'Next task offer',
      child: Container(
        key: const Key('nextTaskOfferCard'),
        margin: const EdgeInsets.fromLTRB(12, 0, 12, 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: context.card,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: border, width: 1.5),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(reserved ? Icons.check_circle : Icons.schedule,
                    size: 16, color: reserved ? TmColors.success : context.textSecondary),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    reserved ? 'Next task reserved' : 'Next task offer',
                    style: GoogleFonts.inter(
                        fontSize: 13, fontWeight: FontWeight.w700, color: context.textPrimary),
                  ),
                ),
                if (left != null)
                  Text(
                    'Respond in ${_clock(left)}',
                    key: const Key('nextTaskCountdown'),
                    style: GoogleFonts.inter(
                        fontSize: 12, fontWeight: FontWeight.w600, color: context.textSecondary),
                  ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              [
                _offer.bookingCode,
                if (_offer.towClass != null) _offer.towClass!,
                if (_offer.vehicleInfo != null && _offer.vehicleInfo!.isNotEmpty) _offer.vehicleInfo!,
              ].join(' · '),
              style: GoogleFonts.inter(
                  fontSize: 13, fontWeight: FontWeight.w600, color: context.textPrimary),
            ),
            if ((_offer.pickupAddress ?? '').isNotEmpty) ...[
              const SizedBox(height: 4),
              Text(
                'Pickup: ${_offer.pickupAddress}',
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: GoogleFonts.inter(fontSize: 12.5, color: context.textSecondary),
              ),
            ],
            if (_offer.isScheduled && _offer.scheduledFor != null) ...[
              const SizedBox(height: 2),
              Text(
                'Scheduled: ${_offer.scheduledFor!.toString().substring(0, 16)}',
                style: GoogleFonts.inter(fontSize: 12.5, color: context.textSecondary),
              ),
            ],
            const SizedBox(height: 6),
            Text(
              reserved
                  ? 'Reserved for you. Dispatch will assign it once you finish ${_offer.currentBookingCode ?? 'your current job'}.'
                  : 'Accepting only reserves this task. It does not start now — finish your current job first.',
              style: GoogleFonts.inter(fontSize: 12, color: context.textTertiary),
            ),
            if (_error != null) ...[
              const SizedBox(height: 6),
              Text(_error!,
                  key: const Key('nextTaskError'),
                  style: GoogleFonts.inter(fontSize: 12, color: TmColors.destructive)),
            ],
            const SizedBox(height: 8),
            if (!reserved)
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      key: const Key('nextTaskAccept'),
                      onPressed: _busy ? null : () => _answer(true),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: context.textPrimary,
                        side: BorderSide(color: context.textPrimary),
                        minimumSize: const Size.fromHeight(44),
                      ),
                      child: Text(_busy ? 'Sending…' : 'Accept'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: TextButton(
                      key: const Key('nextTaskDecline'),
                      onPressed: _busy ? null : () => _answer(false),
                      style: TextButton.styleFrom(
                        foregroundColor: TmColors.destructive,
                        minimumSize: const Size.fromHeight(44),
                      ),
                      child: const Text('Decline'),
                    ),
                  ),
                ],
              )
            else
              Align(
                alignment: Alignment.centerRight,
                child: TextButton(
                  key: const Key('nextTaskRelease'),
                  onPressed: _busy ? null : () => _answer(false),
                  style: TextButton.styleFrom(foregroundColor: TmColors.destructive),
                  child: const Text('Release reservation'),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
