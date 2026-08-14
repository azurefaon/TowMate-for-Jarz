import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/team_leader_service.dart';
import '../../widgets/tl_drawer.dart';

class TlHistoryScreen extends StatefulWidget {
  const TlHistoryScreen({super.key});

  @override
  State<TlHistoryScreen> createState() => _TlHistoryScreenState();
}

class _TlHistoryScreenState extends State<TlHistoryScreen> {
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  final List<Map<String, dynamic>> _jobs = [];
  bool _loading = true;
  bool _loadingMore = false;
  int _page = 1;
  int _lastPage = 1;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool refresh = false}) async {
    if (refresh) {
      setState(() {
        _loading = true;
        _page = 1;
        _jobs.clear();
      });
    }
    final res = await TeamLeaderService.getHistory(page: _page);
    if (!mounted) return;
    setState(() {
      _loading = false;
      _loadingMore = false;
      if (res['success'] == true) {
        _jobs.addAll(List<Map<String, dynamic>>.from(res['data'] as List));
        _lastPage = res['last_page'] as int? ?? 1;
      }
    });
  }

  Future<void> _loadMore() async {
    if (_loadingMore || _page >= _lastPage) return;
    setState(() {
      _loadingMore = true;
      _page += 1;
    });
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: TmColors.white,
      drawer: TlDrawer(currentRoute: '/tl-history'),
      appBar: AppBar(
        backgroundColor: TmColors.white,
        elevation: 0,
        automaticallyImplyLeading: false,
        leading: IconButton(
          icon: const Icon(Icons.menu_rounded, color: TmColors.black),
          onPressed: () => _scaffoldKey.currentState?.openDrawer(),
          tooltip: 'Menu',
        ),
        title: Text(
          'History',
          style: GoogleFonts.inter(
            color: TmColors.black,
            fontSize: 18,
            fontWeight: FontWeight.w600,
            letterSpacing: -0.3,
          ),
        ),
      ),
      body: RefreshIndicator(
        onRefresh: () => _load(refresh: true),
        color: TmColors.yellow,
        child: _loading
            ? const Center(
                child: CircularProgressIndicator(color: TmColors.yellow),
              )
            : _jobs.isEmpty
            ? _emptyState()
            : NotificationListener<ScrollNotification>(
                onNotification: (n) {
                  if (n.metrics.pixels >= n.metrics.maxScrollExtent - 200) {
                    _loadMore();
                  }
                  return false;
                },
                child: ListView.separated(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(20),
                  itemCount: _jobs.length + (_loadingMore ? 1 : 0),
                  separatorBuilder: (_, _) => const SizedBox(height: 12),
                  itemBuilder: (context, i) {
                    if (i >= _jobs.length) {
                      return const Padding(
                        padding: EdgeInsets.symmetric(vertical: 16),
                        child: Center(
                          child: CircularProgressIndicator(
                            color: TmColors.yellow,
                            strokeWidth: 2,
                          ),
                        ),
                      );
                    }
                    return _jobCard(_jobs[i]);
                  },
                ),
              ),
      ),
    );
  }

  Widget _emptyState() {
    return LayoutBuilder(
      builder: (context, constraints) => SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        child: ConstrainedBox(
          constraints: BoxConstraints(minHeight: constraints.maxHeight),
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(
                    Icons.history_rounded,
                    size: 44,
                    color: TmColors.black,
                  ),
                  const SizedBox(height: 14),
                  Text(
                    'No completed jobs yet',
                    style: GoogleFonts.inter(
                      color: TmColors.black,
                      fontSize: 15,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _jobCard(Map<String, dynamic> job) {
    final status = job['status'] as String? ?? '';
    final isCompleted = status == 'completed';
    final total = (job['final_total'] as num?)?.toDouble() ?? 0;
    final date = _formatDate(job['completed_at'] as String?);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: TmColors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: TmColors.black, width: 1),
        boxShadow: [
          BoxShadow(
            color: TmColors.black.withValues(alpha: 0.06),
            blurRadius: 6,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                job['booking_code'] as String? ?? '',
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const Spacer(),
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 4,
                ),
                decoration: BoxDecoration(
                  color: isCompleted ? TmColors.black : TmColors.white,
                  border: Border.all(color: TmColors.black, width: 1),
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  isCompleted ? 'Completed' : 'Returned',
                  style: GoogleFonts.inter(
                    color: isCompleted ? TmColors.yellow : TmColors.black,
                    fontSize: 10,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 0.3,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            job['customer_name'] as String? ?? '',
            style: GoogleFonts.inter(color: TmColors.black, fontSize: 13),
          ),
          const SizedBox(height: 4),
          Text(
            '${job['pickup_address'] ?? ''} → ${job['dropoff_address'] ?? ''}',
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: GoogleFonts.inter(
              color: TmColors.black.withValues(alpha: 0.65),
              fontSize: 12,
            ),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Text(
                '₱${total.toStringAsFixed(2)}',
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const Spacer(),
              Text(
                date,
                style: GoogleFonts.inter(
                  color: TmColors.black.withValues(alpha: 0.65),
                  fontSize: 12,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  String _formatDate(String? iso) {
    if (iso == null) return '';
    final dt = DateTime.tryParse(iso);
    if (dt == null) return '';
    const months = [
      'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];
    return '${months[dt.month - 1]} ${dt.day}, ${dt.year}';
  }
}
