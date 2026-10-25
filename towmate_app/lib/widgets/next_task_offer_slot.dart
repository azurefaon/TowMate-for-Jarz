import 'dart:async';
import 'package:flutter/material.dart';
import '../models/next_task_offer.dart';
import '../services/team_leader_service.dart';
import 'next_task_offer_card.dart';

/// The next-task offer card for screens pushed on top of the active-task shell (the Payment
/// step), which hide the shell's own card. Polls in the foreground only, on the same 20-second
/// cadence as the shell; there is no push. Shows nothing when there is no offer or on failure.
class NextTaskOfferSlot extends StatefulWidget {
  final Duration interval;

  const NextTaskOfferSlot({super.key, this.interval = const Duration(seconds: 20)});

  @override
  State<NextTaskOfferSlot> createState() => _NextTaskOfferSlotState();
}

class _NextTaskOfferSlotState extends State<NextTaskOfferSlot> {
  NextTaskOffer? _offer;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _fetch();
    _timer = Timer.periodic(widget.interval, (_) => _fetch());
  }

  Future<void> _fetch() async {
    try {
      final offer = await TeamLeaderService.getNextOffer();
      if (!mounted) return;
      setState(() => _offer = offer);
    } catch (_) {}
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final offer = _offer;
    if (offer == null) return const SizedBox.shrink();
    return SafeArea(
      top: false,
      child: NextTaskOfferCard(
        key: ValueKey('offer-${offer.id}'),
        offer: offer,
        onChanged: _fetch,
      ),
    );
  }
}
