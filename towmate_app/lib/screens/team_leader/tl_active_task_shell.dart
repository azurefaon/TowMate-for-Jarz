import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/api_service.dart';
import '../../services/team_leader_service.dart';
import '../../services/tl_presence_controller.dart';
import '../../services/location_tracker.dart';
import '../../widgets/skeleton_box.dart';
import '../../widgets/tl_bottom_nav.dart';
import '../../widgets/tl_drawer.dart';
import '../../widgets/tl_status_timeline.dart';
import 'tl_en_route_screen.dart';
import 'tl_arrived_pickup_screen.dart';
import 'tl_transporting_screen.dart';
import 'tl_arrived_dropoff_screen.dart';
import 'tl_awaiting_confirm_screen.dart';
import 'tl_completed_screen.dart';
import 'tl_returned_screen.dart';
import 'tl_navigate_screen.dart';
import 'tl_task_submitted_screen.dart';

TaskModel? resolveFetchedTask({
  required TaskModel? previous,
  required TaskModel? fetched,
}) {
  final isTerminal = fetched != null &&
      (fetched.status == 'completed' || fetched.status == 'returned');
  final wasTrackingSameBooking =
      previous != null && previous.bookingCode == fetched?.bookingCode;
  if (isTerminal && !wasTrackingSameBooking) {
    return null;
  }
  return fetched;
}

class TlActiveTaskShell extends StatefulWidget {
  const TlActiveTaskShell({super.key});

  @override
  State<TlActiveTaskShell> createState() => _TlActiveTaskShellState();
}

class _TlActiveTaskShellState extends State<TlActiveTaskShell> {
  TaskModel? _task;
  bool _loading = true;
  int _tabIndex = 0;
  final LocationTracker _gps = LocationTracker();
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  Timer? _pollTimer;
  String? _name;

  @override
  void initState() {
    super.initState();
    ApiService.getUserName().then((n) { if (mounted) setState(() => _name = n); });
    TlPresenceController.start();
    _fetchTask();
    _pollTimer = Timer.periodic(
      const Duration(seconds: 20),
      (_) => _fetchTask(),
    );
  }

  Future<void> _fetchTask() async {
    TaskModel? task;
    try {
      task = await TeamLeaderService.getCurrentTask();
    } catch (_) {
      if (!mounted) return;
      setState(() => _loading = false);
      return;
    }
    if (!mounted) return;

    task = resolveFetchedTask(previous: _task, fetched: task);

    setState(() {
      _task = task;
      _loading = false;
    });
    _syncGps(task);

    final isGroupAwaitingNext = task != null &&
        task.status == 'completed' &&
        task.isGroupBooking &&
        task.groupPosition < task.groupVehicleCount;

    if (task == null || task.status == 'returned' ||
        (task.status == 'completed' && !isGroupAwaitingNext)) {
      _pollTimer?.cancel();
    } else if (isGroupAwaitingNext &&
        (_pollTimer == null || !_pollTimer!.isActive)) {
      _pollTimer = Timer.periodic(
        const Duration(seconds: 20),
        (_) => _fetchTask(),
      );
    }
  }

  void _syncGps(TaskModel? task) {
    if (task == null) return;
    if (task.isGpsPhase && !_gps.isRunning) {
      _gps.start();
    } else if (!task.isGpsPhase && _gps.isRunning) {
      _gps.stop();
    }
  }

  void onTaskUpdated(TaskModel updated) {
    if (!mounted) return;
    setState(() => _task = updated);
    _syncGps(updated);
    if (updated.isActive && (_pollTimer == null || !_pollTimer!.isActive)) {
      _pollTimer = Timer.periodic(
        const Duration(seconds: 20),
        (_) => _fetchTask(),
      );
    }
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    _gps.stop();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return Scaffold(
        backgroundColor: context.bg,
        body: const SafeArea(
          child: Padding(
            padding: EdgeInsets.all(20),
            child: _TaskLoadingSkeleton(),
          ),
        ),
      );
    }

    if (_task == null) {
      return Scaffold(
        backgroundColor: context.bg,
        bottomNavigationBar: const TlBottomNav(currentRoute: '/tl-active-task'),
        body: SafeArea(
          child: Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  'No active task found.',
                  style: GoogleFonts.inter(color: context.textTertiary, fontSize: 14),
                ),
                const SizedBox(height: 20),
                TextButton(
                  onPressed: () =>
                      Navigator.pushReplacementNamed(context, '/tl-home'),
                  child: Text(
                    'Back to Home',
                    style: GoogleFonts.inter(color: TmColors.yellow),
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    }

    final task = _task!;
    final isDone = task.status == 'completed' || task.status == 'returned';

    return PopScope(
      canPop: isDone,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Cannot leave while a task is active.'),
              duration: Duration(seconds: 2),
            ),
          );
        }
      },
      child: Scaffold(
        key: _scaffoldKey,
        backgroundColor: TmColors.grey100,
        drawer: TlDrawer(currentRoute: '/tl-active-task', name: _name),
        body: Column(
          children: [
            if (!isDone) _topBar(task),
            Expanded(
              child: isDone
                  ? _doneView(task)
                  : IndexedStack(
                      index: _tabIndex,
                      children: [
                        _taskTab(task),
                        _navigateTab(task),
                        _emergencyTab(),
                      ],
                    ),
            ),
            if (!isDone) _bottomNav(),
          ],
        ),
      ),
    );
  }

  Widget _topBar(TaskModel task) {
    const redesignedStepStatuses = {
      'accepted', 'on_the_way',
      'arrived_pickup', 'in_progress', 'loading_vehicle',
      'on_job',
      'arrived_dropoff',
    };
    final hasOwnStepHeader = redesignedStepStatuses.contains(task.status) ||
        task.status == 'waiting_verification';
    return Container(
      color: TmColors.white,
      child: SafeArea(
        bottom: false,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              child: Row(
                children: [
                  IconButton(
                    icon: const Icon(Icons.menu_rounded, color: TmColors.grey700),
                    onPressed: () => _scaffoldKey.currentState?.openDrawer(),
                    tooltip: 'Menu',
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(),
                  ),
                  const SizedBox(width: 8),
                  RichText(
                    text: TextSpan(
                      children: [
                        TextSpan(
                          text: 'Tow',
                          style: GoogleFonts.inter(
                            color: TmColors.black,
                            fontSize: 18,
                            letterSpacing: -0.4,
                          ),
                        ),
                        TextSpan(
                          text: 'Mate',
                          style: GoogleFonts.inter(
                            color: TmColors.yellow,
                            fontSize: 18,
                            letterSpacing: -0.4,
                          ),
                        ),
                      ],
                    ),
                  ),
                  if (!hasOwnStepHeader) ...[
                    const SizedBox(width: 10),
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 4,
                      ),
                      decoration: BoxDecoration(
                        color: TmColors.yellow.withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text(
                        _statusLabel(task.status),
                        style: GoogleFonts.inter(
                          color: TmColors.yellow,
                          fontSize: 11,
                        ),
                      ),
                    ),
                  ],
                  const Spacer(),
                  if (_gps.isRunning && !hasOwnStepHeader)
                    Row(
                      children: [
                        const Icon(
                          Icons.gps_fixed_rounded,
                          color: TmColors.success,
                          size: 14,
                        ),
                        const SizedBox(width: 4),
                        Text(
                          'Live',
                          style: GoogleFonts.inter(
                            color: TmColors.success,
                            fontSize: 11,
                          ),
                        ),
                      ],
                    ),
                ],
              ),
            ),
            if (!hasOwnStepHeader) TlStatusTimeline(currentStatus: task.status),
            if (task.isGroupBooking) ...[
              const SizedBox(height: 6),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 10, vertical: 3),
                      clipBehavior: Clip.antiAlias,
                      decoration: BoxDecoration(
                        color: TmColors.yellow.withValues(alpha: 0.15),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(
                            color: TmColors.yellow.withValues(alpha: 0.4)),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(Icons.directions_car_rounded,
                              size: 12, color: TmColors.black),
                          const SizedBox(width: 4),
                          Text(
                            'Vehicle ${task.groupPosition} of ${task.groupVehicleCount}',
                            style: GoogleFonts.inter(
                              fontSize: 11,
                              color: TmColors.black,
                              letterSpacing: 0.2,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ],
            const SizedBox(height: 4),
          ],
        ),
      ),
    );
  }

  Widget _taskTab(TaskModel task) {
    return switch (task.status) {
      'accepted' => TlEnRouteScreen(
          task: task,
          onUpdate: onTaskUpdated,
          onNavigateToPickup: () => setState(() => _tabIndex = 1),
        ),
      'on_the_way' => TlEnRouteScreen(
          task: task,
          onUpdate: onTaskUpdated,
          onNavigateToPickup: () => setState(() => _tabIndex = 1),
        ),
      'arrived_pickup' => TlArrivedPickupScreen(
        task: task,
        onUpdate: onTaskUpdated,
      ),
      'in_progress' => TlArrivedPickupScreen(
        task: task,
        onUpdate: onTaskUpdated,
      ),
      'loading_vehicle' => TlArrivedPickupScreen(
        task: task,
        onUpdate: onTaskUpdated,
      ),
      'on_job' => TlTransportingScreen(
          task: task,
          onUpdate: onTaskUpdated,
          onNavigateToDropoff: () => setState(() => _tabIndex = 1),
        ),
      'arrived_dropoff' => TlArrivedDropoffScreen(
        task: task,
        onUpdate: onTaskUpdated,
      ),
      'waiting_verification' => task.paymentMethod != null
          ? TlTaskSubmittedScreen(task: task)
          : TlAwaitingConfirmScreen(task: task, onUpdate: onTaskUpdated),
      'completed' => TlCompletedScreen(task: task, onUpdate: onTaskUpdated),
      'returned' => TlReturnedScreen(task: task),
      _ => const Center(
        child: CircularProgressIndicator(color: TmColors.yellow),
      ),
    };
  }

  Widget _navigateTab(TaskModel task) {
    return TlNavigateScreen(task: task);
  }

  Widget _emergencyTab() {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Emergency',
              style: GoogleFonts.inter(
                color: TmColors.black,
                fontSize: 18,
                letterSpacing: -0.3,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              'Contact dispatch if you need immediate assistance.',
              style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 13),
            ),
            const SizedBox(height: 24),
            _emergencyCard(
              icon: Icons.support_agent_rounded,
              title: 'Contact Dispatch',
              subtitle: 'Report an issue or request assistance',
              color: TmColors.yellow,
            ),
            const SizedBox(height: 12),
            _emergencyCard(
              icon: Icons.warning_amber_rounded,
              title: 'Report Incident',
              subtitle: 'Vehicle breakdown, accident, or hazard',
              color: TmColors.error,
            ),
          ],
        ),
      ),
    );
  }

  Widget _emergencyCard({
    required IconData icon,
    required String title,
    required String subtitle,
    required Color color,
  }) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: TmColors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: TmColors.grey300),
      ),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: color.withValues(alpha: 0.1),
              shape: BoxShape.circle,
            ),
            child: Icon(icon, color: color, size: 22),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: GoogleFonts.inter(color: TmColors.black, fontSize: 14),
                ),
                Text(
                  subtitle,
                  style: GoogleFonts.inter(
                    color: TmColors.grey500,
                    fontSize: 12,
                  ),
                ),
              ],
            ),
          ),
          const Icon(
            Icons.chevron_right_rounded,
            color: TmColors.grey300,
            size: 20,
          ),
        ],
      ),
    );
  }

  Widget _doneView(TaskModel task) {
    if (task.status == 'completed') {
      return TlCompletedScreen(task: task, onUpdate: onTaskUpdated);
    }
    return TlReturnedScreen(task: task);
  }

  Widget _bottomNav() {
    const tabs = [
      (Icons.assignment_outlined, Icons.assignment_rounded, 'Task'),
      (Icons.map_outlined, Icons.map_rounded, 'Navigate'),
      (Icons.warning_amber_outlined, Icons.warning_amber_rounded, 'Emergency'),
    ];
    return Container(
      color: TmColors.white,
      child: SafeArea(
        top: false,
        child: Row(
          children: List.generate(tabs.length, (i) {
            final selected = _tabIndex == i;
            return Expanded(
              child: GestureDetector(
                onTap: () => setState(() => _tabIndex = i),
                behavior: HitTestBehavior.opaque,
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 10),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(
                        selected ? tabs[i].$2 : tabs[i].$1,
                        color: selected ? TmColors.yellow : TmColors.grey500,
                        size: 22,
                      ),
                      const SizedBox(height: 3),
                      Text(
                        tabs[i].$3,
                        style: GoogleFonts.inter(
                          color: selected ? TmColors.yellow : TmColors.grey500,
                          fontSize: 11,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            );
          }),
        ),
      ),
    );
  }

  String _statusLabel(String status) {
    return const {
          'accepted': 'Route',
          'on_the_way': 'Route',
          'arrived_pickup': 'Arrived',
          'in_progress': 'Arrived',
          'loading_vehicle': 'Arrived',
          'on_job': 'Towing',
          'arrived_dropoff': 'Dropoff',
          'waiting_verification': 'Pending Payment',
          'completed': 'Completed',
          'returned': 'Returned',
        }[status] ??
        status;
  }
}

class _TaskLoadingSkeleton extends StatelessWidget {
  const _TaskLoadingSkeleton();

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            const SkeletonBox(width: 90, height: 13),
            const Spacer(),
            SkeletonBox(width: 70, height: 22, borderRadius: BorderRadius.circular(20)),
          ],
        ),
        const SizedBox(height: 24),
        const SkeletonBox(width: 160, height: 26),
        const SizedBox(height: 24),
        SkeletonBox(height: 120, borderRadius: BorderRadius.circular(12)),
        const SizedBox(height: 16),
        const SkeletonBox(height: 14),
        const SizedBox(height: 8),
        const SkeletonBox(width: 220, height: 14),
      ],
    );
  }
}
