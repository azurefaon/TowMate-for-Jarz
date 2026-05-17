import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';
import '../services/api_service.dart';

class TmDrawer extends StatelessWidget {
  const TmDrawer({
    super.key,
    required this.currentRoute,
    this.isLoggedIn = false,
    this.name,
  });

  final String currentRoute;
  final bool isLoggedIn;
  final String? name;

  void _navigate(BuildContext context, String route) {
    final nav = Navigator.of(context);
    nav.pop();
    if (route == currentRoute) return;
    // These routes sit on top of the current screen; back returns to where you came from
    const pushRoutes = {'/login', '/signup', '/profile'};
    if (pushRoutes.contains(route)) {
      nav.pushNamed(route);
    } else {
      nav.pushReplacementNamed(route);
    }
  }

  Future<void> _logout(BuildContext context) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: ctx.card,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        title: Text(
          'Log out?',
          style: GoogleFonts.inter(
            color: ctx.textPrimary,
            fontSize: 17,
            letterSpacing: -0.3,
          ),
        ),
        content: Text(
          'You will need to sign in again to access your account.',
          style: GoogleFonts.inter(
            color: ctx.textTertiary,
            fontSize: 14,
            height: 1.5,
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text(
              'Cancel',
              style: GoogleFonts.inter(color: ctx.textTertiary, fontSize: 14),
            ),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(
              'Log out',
              style: GoogleFonts.inter(color: TmColors.error, fontSize: 14),
            ),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    await ApiService.clearSession();
    if (!context.mounted) return;
    Navigator.of(context).pushNamedAndRemoveUntil('/', (_) => false);
  }

  @override
  Widget build(BuildContext context) {
    return Drawer(
      width: 288,
      backgroundColor: context.card,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.only(
          topRight: Radius.circular(20),
          bottomRight: Radius.circular(20),
        ),
      ),
      child: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // ── Header ────────────────────────────────────────────────────
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 32, 24, 24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'TowMate',
                    style: GoogleFonts.inter(
                      color: TmColors.yellow,
                      fontSize: 22,
                      letterSpacing: -0.8,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    isLoggedIn
                        ? (name ?? 'Customer')
                        : 'Professional towing services',
                    style: GoogleFonts.inter(
                      color: context.textSecondary,
                      fontSize: 12,
                      letterSpacing: 0.2,
                    ),
                  ),
                ],
              ),
            ),

            // ── Divider ───────────────────────────────────────────────────
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 24),
              child: Container(height: 1, color: context.divider),
            ),
            const SizedBox(height: 8),

            if (isLoggedIn) ...[
              _TmDrawerItem(
                icon: Icons.home_outlined,
                label: 'Dashboard',
                route: '/home',
                currentRoute: currentRoute,
                onTap: () => _navigate(context, '/home'),
              ),
              _TmDrawerItem(
                icon: Icons.receipt_long_outlined,
                label: 'My Bookings',
                route: '/my-bookings',
                currentRoute: currentRoute,
                onTap: () => _navigate(context, '/my-bookings'),
              ),
              _TmDrawerItem(
                icon: Icons.add_circle_outline_rounded,
                label: 'Book Now',
                route: '/book-now',
                currentRoute: currentRoute,
                onTap: () => _navigate(context, '/book-now'),
              ),
              _TmDrawerItem(
                icon: Icons.person_outline_rounded,
                label: 'Profile',
                route: '/profile',
                currentRoute: currentRoute,
                onTap: () => _navigate(context, '/profile'),
              ),
              const SizedBox(height: 8),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 24),
                child: Builder(
                  builder: (ctx) => Container(height: 1, color: ctx.divider),
                ),
              ),
              const SizedBox(height: 8),
              _TmDrawerItem(
                icon: Icons.logout_rounded,
                label: 'Logout',
                route: '',
                currentRoute: currentRoute,
                onTap: () => _logout(context),
                isDestructive: true,
              ),
            ] else ...[
              _TmDrawerItem(
                icon: Icons.home_outlined,
                label: 'Home',
                route: '/',
                currentRoute: currentRoute,
                onTap: () => _navigate(context, '/'),
              ),
              _TmDrawerItem(
                icon: Icons.build_outlined,
                label: 'Services',
                route: '/services',
                currentRoute: currentRoute,
                onTap: () => _navigate(context, '/services'),
              ),
              _TmDrawerItem(
                icon: Icons.info_outline_rounded,
                label: 'About',
                route: '/about',
                currentRoute: currentRoute,
                onTap: () => _navigate(context, '/about'),
              ),
              const SizedBox(height: 8),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 24),
                child: Builder(
                  builder: (ctx) => Container(height: 1, color: ctx.divider),
                ),
              ),
              const SizedBox(height: 8),
              _TmDrawerItem(
                icon: Icons.login_rounded,
                label: 'Login',
                route: '/login',
                currentRoute: currentRoute,
                onTap: () => _navigate(context, '/login'),
              ),
              _TmDrawerItem(
                icon: Icons.person_add_outlined,
                label: 'Sign up',
                route: '/signup',
                currentRoute: currentRoute,
                onTap: () => _navigate(context, '/signup'),
              ),
            ],

            const Spacer(),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 20),
              child: Text(
                '© 2025 TowMate',
                style: GoogleFonts.inter(
                  color: context.textSecondary,
                  fontSize: 11,
                  letterSpacing: 0.3,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _TmDrawerItem extends StatefulWidget {
  const _TmDrawerItem({
    required this.icon,
    required this.label,
    required this.route,
    required this.currentRoute,
    required this.onTap,
    this.isDestructive = false,
  });

  final IconData icon;
  final String label;
  final String route;
  final String currentRoute;
  final VoidCallback onTap;
  final bool isDestructive;

  @override
  State<_TmDrawerItem> createState() => _TmDrawerItemState();
}

class _TmDrawerItemState extends State<_TmDrawerItem> {
  bool _hovered = false;

  @override
  Widget build(BuildContext context) {
    final isActive =
        widget.route.isNotEmpty && widget.route == widget.currentRoute;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
      child: GestureDetector(
        onTap: widget.onTap,
        onTapDown: (_) => setState(() => _hovered = true),
        onTapUp: (_) => setState(() => _hovered = false),
        onTapCancel: () => setState(() => _hovered = false),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 120),
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13),
          decoration: BoxDecoration(
            color: isActive
                ? TmColors.yellow.withValues(alpha: 0.12)
                : _hovered
                ? context.surface
                : Colors.transparent,
            borderRadius: BorderRadius.circular(10),
          ),
          child: Row(
            children: [
              Icon(
                widget.icon,
                size: 20,
                color: widget.isDestructive
                    ? TmColors.error
                    : isActive
                    ? context.textPrimary
                    : context.textTertiary,
              ),
              const SizedBox(width: 14),
              Text(
                widget.label,
                style: GoogleFonts.inter(
                  color: widget.isDestructive
                      ? TmColors.error
                      : isActive
                      ? context.textPrimary
                      : context.textTertiary,
                  fontSize: 15,
                  letterSpacing: 0.1,
                  fontWeight:
                      isActive ? FontWeight.w600 : FontWeight.normal,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
