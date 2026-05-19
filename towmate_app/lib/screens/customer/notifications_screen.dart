import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';

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

  void _onTap(Map<String, dynamic> n) {
    final code = n['booking_code'] as String?;
    if (code != null && code.isNotEmpty) {
      Navigator.pushNamed(context, '/booking-detail', arguments: code);
    }
    if (n['is_read'] == false) {
      setState(() {
        final idx = _notifications.indexOf(n);
        if (idx >= 0) _notifications[idx] = {...n, 'is_read': true};
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final hasUnread = _notifications.any((n) => n['is_read'] == false);
    return Scaffold(
      backgroundColor: context.bg,
      appBar: AppBar(
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
        leading: IconButton(
          icon: Icon(Icons.arrow_back_rounded, color: context.textPrimary),
          onPressed: () => Navigator.pop(context),
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
      body: _loading
          ? const Center(
              child: CircularProgressIndicator(color: TmColors.yellow),
            )
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
                  child: ListView.separated(
                    itemCount: _notifications.length,
                    separatorBuilder: (_, __) =>
                        Divider(height: 0.5, color: context.divider),
                    itemBuilder: (_, i) =>
                        _NotifCard(n: _notifications[i], onTap: _onTap),
                  ),
                ),
    );
  }
}

class _NotifCard extends StatelessWidget {
  const _NotifCard({required this.n, required this.onTap});
  final Map<String, dynamic> n;
  final void Function(Map<String, dynamic>) onTap;

  @override
  Widget build(BuildContext context) {
    final isRead = n['is_read'] == true;
    final type = n['type'] as String? ?? '';

    return InkWell(
      onTap: () => onTap(n),
      child: Container(
        color: isRead ? Colors.transparent : TmColors.yellow.withValues(alpha: 0.04),
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                color: _iconBg(type),
                shape: BoxShape.circle,
              ),
              child: Icon(_icon(type), color: _iconColor(type), size: 18),
            ),
            const SizedBox(width: 12),
            Expanded(
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
          ],
        ),
      ),
    );
  }

  IconData _icon(String type) {
    return switch (type) {
      'quotation_sent' || 'quotation_updated' => Icons.receipt_long_rounded,
      'booking_update' => Icons.local_shipping_rounded,
      _ => Icons.notifications_rounded,
    };
  }

  Color _iconBg(String type) {
    return switch (type) {
      'quotation_sent' || 'quotation_updated' =>
        TmColors.yellow.withValues(alpha: 0.12),
      'booking_update' => TmColors.success.withValues(alpha: 0.1),
      _ => TmColors.grey300.withValues(alpha: 0.3),
    };
  }

  Color _iconColor(String type) {
    return switch (type) {
      'quotation_sent' || 'quotation_updated' => TmColors.yellow,
      'booking_update' => TmColors.success,
      _ => TmColors.grey500,
    };
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
