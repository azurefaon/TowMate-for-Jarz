import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/api_service.dart';
import '../../services/location_tracker.dart';
import '../../services/team_leader_service.dart';
import '../../services/tl_presence_controller.dart';
import '../../widgets/tl_bottom_nav.dart';

class TlHomeScreen extends StatefulWidget {
  const TlHomeScreen({super.key});

  @override
  State<TlHomeScreen> createState() => _TlHomeScreenState();
}

class _TlHomeScreenState extends State<TlHomeScreen>
    with WidgetsBindingObserver {
  TaskModel? _task;
  bool _loadingTask = false;
  bool _accepting = false;
  String? _name;
  String? _dutyClass;
  Timer? _pollTimer;
  final LocationTracker _gps = LocationTracker();
  static final _money = NumberFormat('#,##0.00', 'en_PH');

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _init();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _gps.start();
    } else if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.detached) {
      _gps.stop();
    }
  }

  Future<void> _init() async {
    _name = await ApiService.getUserName();
    _dutyClass = await ApiService.getUserDutyClass();
    TlPresenceController.start();
    _gps.start();
    await _fetchTask();
    if (!mounted) return;
    _pollTimer = Timer.periodic(
      const Duration(seconds: 15),
      (_) => _fetchTask(),
    );
  }

  Future<void> _fetchTask() async {
    if (!mounted) return;
    setState(() => _loadingTask = true);
    TaskModel? task;
    try {
      task = await TeamLeaderService.getCurrentTask();
    } catch (_) {
      if (!mounted) return;
      setState(() => _loadingTask = false);
      return;
    }
    if (!mounted) return;
    setState(() {
      _task = task;
      _loadingTask = false;
    });

    const tlActiveStatuses = {
      'accepted', 'on_the_way', 'arrived_pickup', 'in_progress',
      'loading_vehicle', 'on_job', 'arrived_dropoff',
      'waiting_verification',
    };

    if (task != null && tlActiveStatuses.contains(task.status)) {
      _pollTimer?.cancel();
      if (!mounted) return;
      Navigator.pushReplacementNamed(context, '/tl-active-task');
      return;
    }

    if (task != null && task.status != 'assigned') {
      setState(() {
        _task = null;
        _loadingTask = false;
      });
      return;
    }
  }

  Future<void> _accept() async {
    if (_task == null) return;
    setState(() => _accepting = true);

    final res = await TeamLeaderService.acceptTask(_task!.bookingCode);
    if (!mounted) return;

    if (res['success'] == true) {
      await TeamLeaderService.updateStatus(_task!.bookingCode, 'on_the_way');
      if (!mounted) return;
      _pollTimer?.cancel();
      Navigator.pushReplacementNamed(context, '/tl-active-task');
    } else {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] as String? ?? 'Failed to accept task.'),
          backgroundColor: TmColors.error,
        ),
      );
      setState(() => _accepting = false);
      await _fetchTask();
      _pollTimer ??= Timer.periodic(
        const Duration(seconds: 15),
        (_) => _fetchTask(),
      );
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _pollTimer?.cancel();
    _gps.stop();
    super.dispose();
  }

  String get _greeting {
    final h = DateTime.now().hour;
    if (h < 12) return 'Good morning';
    if (h < 17) return 'Good afternoon';
    return 'Good evening';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      bottomNavigationBar: const TlBottomNav(currentRoute: '/tl-home'),
      body: SafeArea(
        child: Column(
          children: [
            _header(context),
            Expanded(
              child: RefreshIndicator(
                onRefresh: _fetchTask,
                color: TmColors.yellow,
                child: SingleChildScrollView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(20, 24, 20, 24),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '$_greeting,',
                        style: GoogleFonts.inter(
                          color: context.textSecondary,
                          fontSize: 15,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        _name ?? 'Team Leader',
                        style: GoogleFonts.inter(
                          color: context.textPrimary,
                          fontSize: 30,
                          fontWeight: FontWeight.w800,
                          letterSpacing: -0.8,
                        ),
                      ),
                      const SizedBox(height: 22),
                      Row(
                        children: [
                          Container(
                            width: 9,
                            height: 9,
                            decoration: const BoxDecoration(
                              color: TmColors.success,
                              shape: BoxShape.circle,
                            ),
                          ),
                          const SizedBox(width: 10),
                          Text(
                            'Available',
                            style: GoogleFonts.inter(
                              color: context.textPrimary,
                              fontSize: 15,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          if (_dutyClass != null) ...[
                            const SizedBox(width: 8),
                            Flexible(
                              child: Text(
                                '· ${_dutyClassLabel(_dutyClass!)}',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: GoogleFonts.inter(
                                  color: context.textSecondary,
                                  fontSize: 13.5,
                                  fontWeight: FontWeight.w500,
                                ),
                              ),
                            ),
                          ],
                          const Spacer(),
                          if (_loadingTask)
                            SizedBox(
                              width: 14,
                              height: 14,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: TmColors.yellow.withValues(alpha: 0.8),
                              ),
                            ),
                        ],
                      ),
                      const SizedBox(height: 22),
                      Divider(height: 1, color: context.divider),
                      const SizedBox(height: 22),

                      _sectionLabel(context, 'Current Task'),
                      const SizedBox(height: 12),
                      if (_task == null && !_loadingTask) _idleCard(context),
                      if (_task != null) ...[
                        _currentTaskCard(context, _task!),
                        const SizedBox(height: 16),
                        _acceptButton(),
                      ],
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _header(BuildContext context) {
    return Container(
      height: 64,
      alignment: Alignment.centerLeft,
      padding: const EdgeInsets.symmetric(horizontal: 20),
      decoration: BoxDecoration(
        color: context.card,
        border: Border(bottom: BorderSide(color: context.divider, width: 0.5)),
      ),
      child: RichText(
        text: TextSpan(
          style: GoogleFonts.inter(
            fontSize: 22,
            fontWeight: FontWeight.w800,
            letterSpacing: -0.8,
          ),
          children: [
            TextSpan(text: 'Tow', style: TextStyle(color: context.textPrimary)),
            const TextSpan(text: 'Mate', style: TextStyle(color: TmColors.yellow)),
          ],
        ),
      ),
    );
  }

  Widget _sectionLabel(BuildContext context, String text) {
    return Text(
      text.toUpperCase(),
      style: GoogleFonts.inter(
        color: context.textPrimary,
        fontSize: 12,
        fontWeight: FontWeight.w800,
        letterSpacing: 1.4,
      ),
    );
  }

  String _dutyClassLabel(String dc) => switch (dc) {
        'light'  => 'Light Duty',
        'medium' => 'Medium Duty',
        'heavy'  => 'Heavy Duty',
        _        => dc,
      };

  String _statusLabel(String status) => status
      .split('_')
      .where((w) => w.isNotEmpty)
      .map((w) => '${w[0].toUpperCase()}${w.substring(1)}')
      .join(' ');

  Widget _idleCard(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(vertical: 24, horizontal: 20),
      decoration: BoxDecoration(
        color: context.surface,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'No task assigned',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 14.5,
              fontWeight: FontWeight.w600,
              letterSpacing: -0.1,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'New towing requests will appear here when assigned.',
            style: GoogleFonts.inter(color: context.textTertiary, fontSize: 13),
          ),
        ],
      ),
    );
  }

  Widget _currentTaskCard(BuildContext context, TaskModel task) {
    return Container(
      decoration: BoxDecoration(
        color: context.card,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: context.divider),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: context.isDark ? 0.24 : 0.05),
            blurRadius: 20,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 20, 20, 20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [
                    Expanded(
                      child: Text(
                        task.bookingCode,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: GoogleFonts.inter(
                          color: context.textSecondary,
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                          letterSpacing: 0.4,
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    _statusPill(context, task.status),
                  ],
                ),
                const SizedBox(height: 18),
                Text(
                  task.customerName,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 19,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -0.3,
                  ),
                ),
                if (task.customerPhone.isNotEmpty) ...[
                  const SizedBox(height: 3),
                  Text(
                    task.customerPhone,
                    style: GoogleFonts.inter(
                      color: context.textSecondary,
                      fontSize: 14,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ],
              ],
            ),
          ),
          Divider(height: 1, color: context.divider),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 18, 20, 18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _routeLabel(context, 'Pickup'),
                const SizedBox(height: 6),
                Text(
                  task.pickupAddress,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    height: 1.35,
                  ),
                ),
                SizedBox(
                  height: 44,
                  child: Row(
                    children: [
                      SizedBox(
                        width: 2,
                        height: double.infinity,
                        child: ColoredBox(color: context.divider),
                      ),
                      const SizedBox(width: 12),
                      Text(
                        '${task.distanceKm.toStringAsFixed(1)} km',
                        style: GoogleFonts.inter(
                          color: context.textSecondary,
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                ),
                _routeLabel(context, 'Drop-off'),
                const SizedBox(height: 6),
                Text(
                  task.dropoffAddress,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    height: 1.35,
                  ),
                ),
              ],
            ),
          ),
          Divider(height: 1, color: context.divider),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 18, 20, 0),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    task.truckTypeName,
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 13.5,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                Text(
                  task.serviceType == 'book_now' ? 'Immediate' : 'Scheduled',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 18),
          Divider(height: 1, color: context.divider),
          if (task.notes != null && task.notes!.isNotEmpty) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 18, 20, 18),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _routeLabel(context, 'Customer note'),
                  const SizedBox(height: 6),
                  Text(
                    task.notes!,
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 14,
                      fontWeight: FontWeight.w500,
                      height: 1.4,
                    ),
                  ),
                ],
              ),
            ),
            Divider(height: 1, color: context.divider),
          ],
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 18, 20, 20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Total Fare',
                  style: GoogleFonts.inter(
                    color: context.textSecondary,
                    fontSize: 13.5,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  '₱${_money.format(task.finalTotal)}',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 30,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -0.8,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _routeLabel(BuildContext context, String text) {
    return Text(
      text.toUpperCase(),
      style: GoogleFonts.inter(
        color: context.textSecondary,
        fontSize: 11,
        fontWeight: FontWeight.w800,
        letterSpacing: 1.1,
      ),
    );
  }

  Widget _statusPill(BuildContext context, String status) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: context.textPrimary,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        _statusLabel(status),
        style: GoogleFonts.inter(
          color: context.bg,
          fontSize: 11.5,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }

  Widget _acceptButton() {
    return SizedBox(
      width: double.infinity,
      height: 56,
      child: ElevatedButton(
        onPressed: _accepting ? null : _accept,
        style: ElevatedButton.styleFrom(
          backgroundColor: TmColors.yellow,
          foregroundColor: TmColors.black,
          disabledBackgroundColor: TmColors.yellow.withValues(alpha: 0.6),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          elevation: 0,
        ),
        child: _accepting
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(
                  color: TmColors.black,
                  strokeWidth: 2,
                ),
              )
            : Text(
                'Accept Task',
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                ),
              ),
      ),
    );
  }
}
