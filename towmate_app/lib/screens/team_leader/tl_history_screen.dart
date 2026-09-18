import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/team_leader_service.dart';
import '../../widgets/skeleton_box.dart';
import '../../widgets/tl_bottom_nav.dart';

class TlHistoryScreen extends StatefulWidget {
  const TlHistoryScreen({super.key});

  @override
  State<TlHistoryScreen> createState() => _TlHistoryScreenState();
}

class _TlHistoryScreenState extends State<TlHistoryScreen> {
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
      backgroundColor: context.bg,
      bottomNavigationBar: const TlBottomNav(currentRoute: '/tl-history'),
      body: SafeArea(
        child: Column(
          children: [
            _header(context),
            Expanded(
              child: _loading
                  ? const _HistorySkeleton()
                  : RefreshIndicator(
                      onRefresh: () => _load(refresh: true),
                      color: TmColors.yellow,
                      child: _jobs.isEmpty
                          ? _emptyState(context)
                          : NotificationListener<ScrollNotification>(
                              onNotification: (n) {
                                if (n.metrics.pixels >=
                                    n.metrics.maxScrollExtent - 200) {
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
                                  return _jobCard(context, _jobs[i]);
                                },
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
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: context.divider, width: 0.5)),
      ),
      child: Center(
        child: RichText(
          text: TextSpan(
            style: GoogleFonts.inter(
              fontSize: 20,
              fontWeight: FontWeight.w700,
              letterSpacing: -0.5,
            ),
            children: [
              TextSpan(text: 'Tow', style: TextStyle(color: context.textPrimary)),
              const TextSpan(text: 'Mate', style: TextStyle(color: TmColors.yellow)),
            ],
          ),
        ),
      ),
    );
  }

  Widget _emptyState(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) => SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(20),
        child: ConstrainedBox(
          constraints: BoxConstraints(minHeight: constraints.maxHeight - 40),
          child: Center(
            child: Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 24, horizontal: 20),
              decoration: BoxDecoration(
                color: context.surface,
                borderRadius: BorderRadius.circular(12),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'No completed jobs yet',
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 14.5,
                      fontWeight: FontWeight.w600,
                      letterSpacing: -0.1,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Jobs you complete or return will show up here.',
                    style: GoogleFonts.inter(color: context.textTertiary, fontSize: 13),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _jobCard(BuildContext context, Map<String, dynamic> job) {
    final status = job['status'] as String? ?? '';
    final isCompleted = status == 'completed';
    final total = (job['final_total'] as num?)?.toDouble() ?? 0;
    final date = _formatDate(job['completed_at'] as String?);

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: context.card,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: context.divider),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  job['booking_code'] as String? ?? '',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: isCompleted ? TmColors.success.withValues(alpha: 0.12) : context.surface,
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  isCompleted ? 'Completed' : 'Returned',
                  style: GoogleFonts.inter(
                    color: isCompleted ? TmColors.success : context.textTertiary,
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
            style: GoogleFonts.inter(color: context.textPrimary, fontSize: 13),
          ),
          const SizedBox(height: 4),
          Text(
            '${job['pickup_address'] ?? ''} → ${job['dropoff_address'] ?? ''}',
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: GoogleFonts.inter(color: context.textTertiary, fontSize: 12),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: Text(
                  '₱${total.toStringAsFixed(2)}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Text(
                date,
                style: GoogleFonts.inter(color: context.textTertiary, fontSize: 12),
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

class _HistorySkeleton extends StatelessWidget {
  const _HistorySkeleton();

  @override
  Widget build(BuildContext context) {
    return ListView.separated(
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.all(20),
      itemCount: 4,
      separatorBuilder: (_, _) => const SizedBox(height: 12),
      itemBuilder: (_, _) => const _JobCardSkeleton(),
    );
  }
}

class _JobCardSkeleton extends StatelessWidget {
  const _JobCardSkeleton();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: context.card,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: context.divider),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const SkeletonBox(width: 90, height: 14),
              const Spacer(),
              SkeletonBox(
                width: 70,
                height: 18,
                borderRadius: BorderRadius.circular(999),
              ),
            ],
          ),
          const SizedBox(height: 12),
          const SkeletonBox(width: 140, height: 13),
          const SizedBox(height: 8),
          const SkeletonBox(height: 12),
          const SizedBox(height: 14),
          Row(
            children: [
              const SkeletonBox(width: 80, height: 15),
              const Spacer(),
              const SkeletonBox(width: 64, height: 12),
            ],
          ),
        ],
      ),
    );
  }
}
