import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/api_service.dart';
import '../../services/team_leader_service.dart';
import '../../widgets/tl_task_detail_card.dart';
import '../../widgets/tl_drawer.dart';

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
  Timer? _presenceTimer;
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _init();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      TeamLeaderService.pingPresence();
    }
  }

  Future<void> _init() async {
    _name = await ApiService.getUserName();
    _dutyClass = await ApiService.getUserDutyClass();
    TeamLeaderService.pingPresence();
    await _fetchTask();
    if (!mounted) return;
    _pollTimer = Timer.periodic(
      const Duration(seconds: 15),
      (_) => _fetchTask(),
    );
    _presenceTimer = Timer.periodic(
      const Duration(seconds: 45),
      (_) => TeamLeaderService.pingPresence(),
    );
  }

  Future<void> _fetchTask() async {
    if (!mounted) return;
    setState(() => _loadingTask = true);
    final task = await TeamLeaderService.getCurrentTask();
    if (!mounted) return;
    setState(() {
      _task = task;
      _loadingTask = false;
    });

    // Statuses that belong to the active task shell — navigate there immediately.
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

    // Completed/returned/cancelled tasks must not appear on the home screen.
    // Only 'assigned' is shown here as an available task to accept.
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
      // Re-fetch to clear stale task card if it's no longer available
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
    _presenceTimer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: TmColors.grey100,
      drawer: TlDrawer(currentRoute: '/tl-home', name: _name),
      appBar: AppBar(
        backgroundColor: TmColors.white,
        elevation: 0,
        automaticallyImplyLeading: false,
        leading: IconButton(
          icon: const Icon(Icons.menu_rounded, color: TmColors.grey700),
          onPressed: () => _scaffoldKey.currentState?.openDrawer(),
          tooltip: 'Menu',
        ),
        title: RichText(
          text: TextSpan(
            children: [
              TextSpan(
                text: 'Tow',
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 20,
                  letterSpacing: -0.4,
                ),
              ),
              TextSpan(
                text: 'Mate',
                style: GoogleFonts.inter(
                  color: TmColors.yellow,
                  fontSize: 20,
                  letterSpacing: -0.4,
                ),
              ),
            ],
          ),
        ),
      ),
      body: RefreshIndicator(
        onRefresh: _fetchTask,
        color: TmColors.yellow,
        child: CustomScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          slivers: [
            SliverPadding(
              padding: const EdgeInsets.all(20),
              sliver: SliverList(
                delegate: SliverChildListDelegate([
                  // Greeting
                  Text(
                    'Hello, ${_name ?? 'Team Leader'}',
                    style: GoogleFonts.inter(
                      color: TmColors.black,
                      fontSize: 20,
                      letterSpacing: -0.3,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'You will be notified when a task is assigned.',
                    style: GoogleFonts.inter(
                      color: TmColors.grey500,
                      fontSize: 13,
                    ),
                  ),
                  const SizedBox(height: 24),

                  // Status + duty class row
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 12,
                      vertical: 10,
                    ),
                    color: TmColors.white,
                    child: Row(
                      children: [
                        Container(
                          width: 7,
                          height: 7,
                          decoration: const BoxDecoration(
                            color: TmColors.success,
                            shape: BoxShape.circle,
                          ),
                        ),
                        const SizedBox(width: 8),
                        Text(
                          'Available',
                          style: GoogleFonts.inter(
                            color: TmColors.grey700,
                            fontSize: 13,
                          ),
                        ),
                        if (_dutyClass != null) ...[
                          const SizedBox(width: 10),
                          Container(
                            width: 1,
                            height: 12,
                            color: TmColors.grey300,
                          ),
                          const SizedBox(width: 10),
                          Text(
                            _dutyClassLabel(_dutyClass!),
                            style: GoogleFonts.inter(
                              color: TmColors.grey500,
                              fontSize: 12,
                              letterSpacing: 0.2,
                            ),
                          ),
                        ],
                        const Spacer(),
                        if (_loadingTask)
                          const SizedBox(
                            width: 14,
                            height: 14,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: TmColors.yellow,
                            ),
                          ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 24),

                  if (_task == null && !_loadingTask) _idleCard(),
                  if (_task != null) ...[
                    _sectionLabel('Available Task'),
                    const SizedBox(height: 12),
                    TlTaskDetailCard(task: _task!),
                    const SizedBox(height: 20),
                    _acceptButton(),
                  ],
                ]),
              ),
            ),
          ],
        ),
      ),
    );
  }

  String _dutyClassLabel(String dc) => switch (dc) {
        'light'  => 'Light Duty',
        'medium' => 'Medium Duty',
        'heavy'  => 'Heavy Duty',
        _        => dc,
      };

  Widget _idleCard() {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 48, horizontal: 24),
      decoration: BoxDecoration(
        color: TmColors.white,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        children: [
          const Icon(Icons.inbox_outlined, size: 48, color: TmColors.grey300),
          const SizedBox(height: 16),
          Text(
            'No Task Assigned',
            style: GoogleFonts.inter(
              color: TmColors.black,
              fontSize: 16,
              letterSpacing: -0.2,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            'Pull down to refresh or wait — this screen checks automatically every 15 seconds.',
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(color: TmColors.grey500, fontSize: 13),
          ),
        ],
      ),
    );
  }

  Widget _sectionLabel(String text) {
    return Text(
      text,
      style: GoogleFonts.inter(
        color: TmColors.grey700,
        fontSize: 12,
        letterSpacing: 0.5,
      ),
    );
  }

  Widget _acceptButton() {
    return SizedBox(
      width: double.infinity,
      height: 52,
      child: ElevatedButton(
        onPressed: _accepting ? null : _accept,
        style: ElevatedButton.styleFrom(
          backgroundColor: TmColors.yellow,
          foregroundColor: TmColors.black,
          disabledBackgroundColor: TmColors.yellow.withValues(alpha: 0.6),
          shape: const StadiumBorder(),
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
            : Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Icon(
                    Icons.check_circle_outline_rounded,
                    color: TmColors.black,
                    size: 20,
                  ),
                  const SizedBox(width: 8),
                  Text(
                    'Accept Task',
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
}
