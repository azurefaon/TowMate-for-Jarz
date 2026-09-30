import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';

class TmBottomNav extends StatelessWidget {
  const TmBottomNav({
    super.key,
    required this.currentRoute,
    this.unreadCount = 0,
  });

  final String currentRoute;
  final int unreadCount;

  static const _pushRoutes = {'/book-now', '/notifications', '/profile'};

  void _go(BuildContext context, String route) {
    if (route == currentRoute) return;
    final nav = Navigator.of(context);
    if (_pushRoutes.contains(route)) {
      nav.pushNamed(route);
    } else {
      nav.pushReplacementNamed(route);
    }
  }

  static const double _barHeight = 62;
  static const double _fabDiameter = 58;
  // How far the floating button's top edge pokes above the bar's top edge.
  static const double _fabOverlap = 24;

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.paddingOf(context).bottom;
    // The widget's own bounds include the part of the floating button that
    // rises above the bar, so the whole visible circle is hit-testable.
    return SizedBox(
      height: _fabOverlap + _barHeight + bottomInset,
      child: Stack(
        alignment: Alignment.topCenter,
        children: [
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: context.card,
                border: Border(top: BorderSide(color: context.divider, width: 0.5)),
              ),
              child: Padding(
                padding: EdgeInsets.only(bottom: bottomInset),
                child: SizedBox(
                  height: _barHeight,
                  child: Row(
                    children: [
                      _NavItem(
                        icon: Icons.home_outlined,
                        selectedIcon: Icons.home,
                        label: 'Home',
                        selected: currentRoute == '/home',
                        onTap: () => _go(context, '/home'),
                      ),
                      _NavItem(
                        icon: Icons.receipt_long_outlined,
                        selectedIcon: Icons.receipt_long,
                        label: 'Bookings',
                        selected: currentRoute == '/my-bookings',
                        onTap: () => _go(context, '/my-bookings'),
                      ),
                      const Expanded(child: SizedBox.shrink()),
                      _NavItem(
                        icon: Icons.notifications_outlined,
                        selectedIcon: Icons.notifications,
                        label: 'Alerts',
                        selected: currentRoute == '/notifications',
                        onTap: () => _go(context, '/notifications'),
                        badgeCount: unreadCount,
                      ),
                      _NavItem(
                        icon: Icons.person_outline,
                        selectedIcon: Icons.person,
                        label: 'Profile',
                        selected: currentRoute == '/profile',
                        onTap: () => _go(context, '/profile'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
          Positioned(
            top: 0,
            child: _BookNowNavItem(
              selected: currentRoute == '/book-now',
              diameter: _fabDiameter,
              onTap: () => _go(context, '/book-now'),
            ),
          ),
        ],
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  const _NavItem({
    required this.icon,
    required this.selectedIcon,
    required this.label,
    required this.selected,
    required this.onTap,
    this.badgeCount = 0,
  });

  final IconData icon;
  final IconData selectedIcon;
  final String label;
  final bool selected;
  final VoidCallback onTap;
  final int badgeCount;

  @override
  Widget build(BuildContext context) {
    final color = selected ? TmColors.yellow : context.textTertiary;
    return Expanded(
      child: Semantics(
        button: true,
        selected: selected,
        label: badgeCount > 0 ? '$label, $badgeCount unread' : label,
        child: InkWell(
          onTap: onTap,
          child: SizedBox.expand(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Stack(
                  clipBehavior: Clip.none,
                  children: [
                    Icon(
                      selected ? selectedIcon : icon,
                      color: color,
                      size: 23,
                    ),
                    if (badgeCount > 0)
                      Positioned(
                        right: -6,
                        top: -4,
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 4,
                            vertical: 1,
                          ),
                          constraints: const BoxConstraints(
                            minWidth: 15,
                            minHeight: 15,
                          ),
                          decoration: const BoxDecoration(
                            color: TmColors.error,
                            shape: BoxShape.circle,
                          ),
                          child: Text(
                            badgeCount > 99 ? '99+' : '$badgeCount',
                            textAlign: TextAlign.center,
                            style: GoogleFonts.inter(
                              color: Colors.white,
                              fontSize: 8.5,
                              fontWeight: FontWeight.w700,
                              height: 1.2,
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 4),
                Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: GoogleFonts.inter(
                    color: color,
                    fontSize: 10.5,
                    fontWeight: selected ? FontWeight.w600 : FontWeight.w500,
                    letterSpacing: 0.1,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _BookNowNavItem extends StatelessWidget {
  const _BookNowNavItem({
    required this.selected,
    required this.diameter,
    required this.onTap,
  });

  final bool selected;
  final double diameter;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: selected,
      label: 'Book Now',
      child: InkWell(
        onTap: onTap,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: diameter,
              height: diameter,
              decoration: BoxDecoration(
                color: TmColors.yellow,
                shape: BoxShape.circle,
                border: Border.all(color: context.card, width: 3),
                boxShadow: [
                  BoxShadow(
                    color: TmColors.yellow.withValues(alpha: 0.45),
                    blurRadius: 12,
                    offset: const Offset(0, 4),
                  ),
                  const BoxShadow(
                    color: Color(0x33000000),
                    blurRadius: 6,
                    offset: Offset(0, 2),
                  ),
                ],
              ),
              child: const Icon(Icons.add, color: TmColors.black, size: 26),
            ),
            const SizedBox(height: 4),
            // Keep the tap target as wide as the button itself, even at large text sizes.
            SizedBox(
              width: diameter,
              child: FittedBox(
                fit: BoxFit.scaleDown,
                child: Text(
                  'Book Now',
                  maxLines: 1,
                  style: GoogleFonts.inter(
                    color: selected ? TmColors.yellow : context.textPrimary,
                    fontSize: 10.5,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 0.1,
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
