import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';
import '../../widgets/skeleton_box.dart';
import '../../widgets/tm_bottom_nav.dart';

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<Map<String, dynamic>> _notifications = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final result = await ApiService.fetchNotifications();
    if (!mounted) return;
    if (result['success'] != true && _notifications.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Could not load notifications. Check your connection.'),
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
    setState(() {
      _loading = false;
      _notifications = (result['notifications'] as List<dynamic>? ?? [])
          .map((e) => e as Map<String, dynamic>)
          .toList();
    });
  }

  Future<void> _markAllRead() async {
    await ApiService.markAllNotificationsRead();
    if (!mounted) return;
    setState(() {
      _notifications = _notifications
          .map((n) => {...n, 'is_read': true})
          .toList();
    });
  }

  static const _quotationActionTypes = {
    'quotation_sent',
    'quotation_updated',
    'quotation_price_review_kept',
    'quotation_followup',
  };

  Future<void> _onTap(Map<String, dynamic> n) async {
    final code = n['booking_code'] as String?;
    if (code == null || code.isEmpty) return;

    if (n['is_read'] == false) {
      final id = n['id'];
      setState(() {
        final idx = _notifications.indexOf(n);
        if (idx >= 0) _notifications[idx] = {...n, 'is_read': true};
      });
      if (id is int) {
        ApiService.markNotificationRead(id);
      }
    }

    if (_quotationActionTypes.contains(n['type'] as String?)) {
      final quotation = await ApiService.fetchPendingQuotation();
      if (!mounted) return;
      if (quotation != null) {
        Navigator.pushNamed(context, '/quotation', arguments: quotation);
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('This quotation is no longer available. It may have already been responded to or expired.'),
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
      return;
    }

    Navigator.pushNamed(context, '/booking-detail', arguments: code);
  }

  @override
  Widget build(BuildContext context) {
    final hasUnread = _notifications.any((n) => n['is_read'] == false);
    return Scaffold(
      backgroundColor: context.bg,
      appBar: AppBar(
        automaticallyImplyLeading: false,
        backgroundColor: context.surface,
        elevation: 0,
        centerTitle: true,
        title: Text(
          'Notifications',
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 16,
            letterSpacing: -0.3,
          ),
        ),
        actions: [
          if (hasUnread)
            TextButton(
              onPressed: _markAllRead,
              child: Text(
                'Mark all read',
                style: GoogleFonts.inter(
                  color: TmColors.yellow,
                  fontSize: 13,
                ),
              ),
            ),
        ],
      ),
      bottomNavigationBar: const TmBottomNav(currentRoute: '/notifications'),
      body: _loading
          ? const _NotificationsSkeleton()
          : _notifications.isEmpty
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.notifications_none_rounded,
                          size: 48, color: context.textTertiary),
                      const SizedBox(height: 12),
                      Text(
                        'No notifications yet',
                        style: GoogleFonts.inter(
                          color: context.textSecondary,
                          fontSize: 14,
                        ),
                      ),
                    ],
                  ),
                )
              : RefreshIndicator(
                  color: TmColors.yellow,
                  onRefresh: _load,
                  child: ListView(
                    children: _buildSectionedItems(),
                  ),
                ),
    );
  }

  List<Widget> _buildSectionedItems() {
    final sections = groupNotificationsByDate(_notifications);
    final items = <Widget>[];
    for (final section in sections) {
      items.add(_SectionHeader(label: section.label));
      for (final n in section.items) {
        items.add(_NotifCard(n: n, onTap: _onTap));
      }
    }
    return items;
  }
}

class NotificationDateSection {
  const NotificationDateSection(this.label, this.items);
  final String label;
  final List<Map<String, dynamic>> items;
}

List<NotificationDateSection> groupNotificationsByDate(
  List<Map<String, dynamic>> notifications,
) {
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  final yesterday = today.subtract(const Duration(days: 1));

  final todayItems = <Map<String, dynamic>>[];
  final yesterdayItems = <Map<String, dynamic>>[];
  final earlierItems = <Map<String, dynamic>>[];

  for (final n in notifications) {
    final iso = n['created_at'] as String?;
    final dt = iso != null ? DateTime.tryParse(iso)?.toLocal() : null;
    if (dt == null) {
      earlierItems.add(n);
      continue;
    }
    final day = DateTime(dt.year, dt.month, dt.day);
    if (day == today) {
      todayItems.add(n);
    } else if (day == yesterday) {
      yesterdayItems.add(n);
    } else {
      earlierItems.add(n);
    }
  }

  final sections = <NotificationDateSection>[];
  if (todayItems.isNotEmpty) {
    sections.add(NotificationDateSection('Today', todayItems));
  }
  if (yesterdayItems.isNotEmpty) {
    sections.add(NotificationDateSection('Yesterday', yesterdayItems));
  }
  if (earlierItems.isNotEmpty) {
    sections.add(NotificationDateSection('Earlier', earlierItems));
  }
  return sections;
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader({required this.label});
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: context.bg,
      padding: const EdgeInsets.fromLTRB(20, 14, 20, 6),
      child: Text(
        label,
        style: GoogleFonts.inter(
          color: context.textTertiary,
          fontSize: 11.5,
          fontWeight: FontWeight.w700,
          letterSpacing: 0.4,
        ),
      ),
    );
  }
}

class _NotifCard extends StatelessWidget {
  const _NotifCard({required this.n, required this.onTap});
  final Map<String, dynamic> n;
  final Future<void> Function(Map<String, dynamic>) onTap;

  @override
  Widget build(BuildContext context) {
    final isRead = n['is_read'] == true;

    return InkWell(
      onTap: () => onTap(n),
      child: Container(
        color: isRead ? Colors.transparent : TmColors.yellow.withValues(alpha: 0.04),
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    n['title'] as String? ?? '',
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 13,
                      letterSpacing: -0.1,
                    ),
                  ),
                ),
                if (!isRead)
                  Container(
                    width: 7,
                    height: 7,
                    margin: const EdgeInsets.only(left: 8),
                    decoration: const BoxDecoration(
                      color: TmColors.yellow,
                      shape: BoxShape.circle,
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 3),
            Text(
              n['body'] as String? ?? '',
              style: GoogleFonts.inter(
                color: context.textSecondary,
                fontSize: 12,
              ),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
            const SizedBox(height: 4),
            Text(
              _timeAgo(n['created_at'] as String?),
              style: GoogleFonts.inter(
                color: context.textTertiary,
                fontSize: 11,
              ),
            ),
          ],
        ),
      ),
    );
  }

  String _timeAgo(String? iso) {
    if (iso == null) return '';
    try {
      final dt = DateTime.parse(iso).toLocal();
      final diff = DateTime.now().difference(dt);
      if (diff.inMinutes < 1) return 'Just now';
      if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
      if (diff.inHours < 24) return '${diff.inHours}h ago';
      if (diff.inDays < 7) return '${diff.inDays}d ago';
      return '${dt.day}/${dt.month}/${dt.year}';
    } catch (_) {
      return '';
    }
  }
}

class _NotificationsSkeleton extends StatelessWidget {
  const _NotificationsSkeleton();

  @override
  Widget build(BuildContext context) {
    return ListView.separated(
      physics: const NeverScrollableScrollPhysics(),
      itemCount: 5,
      separatorBuilder: (_, _) => Divider(height: 0.5, color: context.divider),
      itemBuilder: (_, _) => const _NotifCardSkeleton(),
    );
  }
}

class _NotifCardSkeleton extends StatelessWidget {
  const _NotifCardSkeleton();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SkeletonBox(width: 130, height: 13),
          const SizedBox(height: 6),
          const SkeletonBox(height: 12),
          const SizedBox(height: 4),
          const SkeletonBox(width: 180, height: 12),
          const SizedBox(height: 6),
          const SkeletonBox(width: 60, height: 11),
        ],
      ),
    );
  }
}
