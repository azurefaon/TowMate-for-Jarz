import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/api_service.dart';
import '../../services/team_leader_service.dart';
import '../../services/location_tracker.dart';
import '../../widgets/tl_drawer.dart';
import '../../widgets/tl_status_timeline.dart';
import '../../widgets/tl_task_detail_card.dart';
import 'tl_en_route_screen.dart';
import 'tl_arrived_pickup_screen.dart';
import 'tl_inspection_screen.dart';
import 'tl_loading_screen.dart';
import 'tl_transporting_screen.dart';
import 'tl_arrived_dropoff_screen.dart';
import 'tl_awaiting_confirm_screen.dart';
import 'tl_completed_screen.dart';
import 'tl_return_screen.dart';
import 'tl_returned_screen.dart';
import 'tl_navigate_screen.dart';

class TlActiveTaskShell extends StatefulWidget {
  const TlActiveTaskShell({super.key});

  @override
  State<TlActiveTaskShell> createState() => _TlActiveTaskShellState();
}

class _TlActiveTaskShellState extends State<TlActiveTaskShell>
    with WidgetsBindingObserver {
  TaskModel? _task;
  bool _loading = true;
  int _tabIndex = 0;
  final LocationTracker _gps = LocationTracker();
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  Timer? _pollTimer;
  Timer? _presenceTimer;
  String? _name;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    ApiService.getUserName().then((n) { if (mounted) setState(() => _name = n); });
    TeamLeaderService.pingPresence();
    _fetchTask();
    _pollTimer = Timer.periodic(
      const Duration(seconds: 20),
      (_) => _fetchTask(),
    );
    _presenceTimer = Timer.periodic(
      const Duration(seconds: 45),
      (_) => TeamLeaderService.pingPresence(),
    );
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      TeamLeaderService.pingPresence();
    }
  }

  Future<void> _fetchTask() async {
    final task = await TeamLeaderService.getCurrentTask();
    if (!mounted) return;
    setState(() {
      _task = task;
      _loading = false;
    });
    _syncGps(task);

    if (task == null ||
        task.status == 'completed' ||
        task.status == 'returned') {
      _pollTimer?.cancel();
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
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _pollTimer?.cancel();
    _presenceTimer?.cancel();
    _gps.stop();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(
        backgroundColor: TmColors.white,
        body: Center(child: CircularProgressIndicator(color: TmColors.yellow)),
      );
    }

    if (_task == null) {
      return Scaffold(
        backgroundColor: TmColors.white,
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(
                Icons.error_outline_rounded,
                size: 48,
                color: TmColors.grey300,
              ),
              const SizedBox(height: 16),
              Text(
                'No active task found.',
                style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 14),
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
                  const Spacer(),
                  if (_gps.isRunning)
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
            TlStatusTimeline(currentStatus: task.status),
            const SizedBox(height: 4),
          ],
        ),
      ),
    );
  }

  Widget _taskTab(TaskModel task) {
    return switch (task.status) {
      'accepted' => _AcceptedView(task: task, onUpdate: onTaskUpdated),
      'on_the_way' => TlEnRouteScreen(task: task, onUpdate: onTaskUpdated),
      'arrived_pickup' => TlArrivedPickupScreen(
        task: task,
        onUpdate: onTaskUpdated,
      ),
      'in_progress' => TlInspectionScreen(task: task, onUpdate: onTaskUpdated),
      'loading_vehicle' => TlLoadingScreen(task: task, onUpdate: onTaskUpdated),
      'on_job' => TlTransportingScreen(task: task, onUpdate: onTaskUpdated),
      'arrived_dropoff' => TlArrivedDropoffScreen(
        task: task,
        onUpdate: onTaskUpdated,
      ),
      'waiting_verification' => TlAwaitingConfirmScreen(
        task: task,
        onUpdate: onTaskUpdated,
      ),
      'completed' => TlCompletedScreen(task: task),
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
    if (task.status == 'completed') return TlCompletedScreen(task: task);
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
          'accepted': 'Task Accepted',
          'on_the_way': 'En Route',
          'arrived_pickup': 'Arrived at Pickup',
          'in_progress': 'Inspecting',
          'loading_vehicle': 'Loading Vehicle',
          'on_job': 'Transporting',
          'arrived_dropoff': 'At Drop-off',
          'waiting_verification': 'Awaiting Verification',
          'completed': 'Completed',
          'returned': 'Returned',
        }[status] ??
        status;
  }
}

// ── Accepted view (task detail + navigate button) ──────────────────────────

class _AcceptedView extends StatefulWidget {
  const _AcceptedView({required this.task, required this.onUpdate});
  final TaskModel task;
  final void Function(TaskModel) onUpdate;

  @override
  State<_AcceptedView> createState() => _AcceptedViewState();
}

class _AcceptedViewState extends State<_AcceptedView> {
  bool _loading = false;

  Future<void> _startNavigation() async {
    setState(() => _loading = true);
    final res = await TeamLeaderService.updateStatus(
      widget.task.bookingCode,
      'on_the_way',
    );
    if (!mounted) return;
    if (res['success'] == true) {
      widget.onUpdate(widget.task.copyWith(status: 'on_the_way'));
    } else {
      setState(() => _loading = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] as String? ?? 'Failed.'),
          backgroundColor: TmColors.error,
        ),
      );
    }
  }

  Future<void> _return() async {
    final result = await Navigator.push<bool>(
      context,
      MaterialPageRoute(builder: (_) => TlReturnScreen(task: widget.task)),
    );
    if (result == true && mounted) {
      widget.onUpdate(widget.task.copyWith(status: 'returned'));
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Task Details',
              style: GoogleFonts.inter(
                color: TmColors.black,
                fontSize: 18,
                letterSpacing: -0.3,
              ),
            ),
            const SizedBox(height: 16),
            TlTaskDetailCard(task: widget.task),
            const SizedBox(height: 24),
            _primaryButton(
              label: 'Start Navigation',
              icon: Icons.navigation_rounded,
              loading: _loading,
              onTap: _startNavigation,
            ),
            const SizedBox(height: 12),
            _secondaryButton(
              label: 'Return Task',
              icon: Icons.undo_rounded,
              onTap: _return,
            ),
          ],
        ),
      ),
    );
  }

  Widget _primaryButton({
    required String label,
    required IconData icon,
    required bool loading,
    required VoidCallback onTap,
  }) {
    return SizedBox(
      width: double.infinity,
      height: 52,
      child: ElevatedButton(
        onPressed: loading ? null : onTap,
        style: ElevatedButton.styleFrom(
          backgroundColor: TmColors.yellow,
          foregroundColor: TmColors.black,
          disabledBackgroundColor: TmColors.yellow.withValues(alpha: 0.6),
          shape: const StadiumBorder(),
          elevation: 0,
        ),
        child: loading
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(
                  color: TmColors.black,
                  strokeWidth: 2,
                ),
              )
            : Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(icon, color: TmColors.black, size: 20),
                  const SizedBox(width: 8),
                  Text(
                    label,
                    style: GoogleFonts.inter(
                      color: TmColors.black,
                      fontSize: 15,
                    ),
                  ),
                ],
              ),
      ),
    );
  }

  Widget _secondaryButton({
    required String label,
    required IconData icon,
    required VoidCallback onTap,
  }) {
    return SizedBox(
      width: double.infinity,
      height: 48,
      child: OutlinedButton(
        onPressed: onTap,
        style: OutlinedButton.styleFrom(
          foregroundColor: TmColors.grey700,
          side: const BorderSide(color: TmColors.grey300),
          shape: const StadiumBorder(),
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, color: TmColors.grey700, size: 18),
            const SizedBox(width: 8),
            Text(
              label,
              style: GoogleFonts.inter(color: TmColors.grey700, fontSize: 14),
            ),
          ],
        ),
      ),
    );
  }
}
