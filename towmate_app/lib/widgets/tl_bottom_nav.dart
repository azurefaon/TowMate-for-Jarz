import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';

class TlBottomNav extends StatelessWidget {
  const TlBottomNav({super.key, required this.currentRoute});

  final String currentRoute;

  void _go(BuildContext context, String route) {
    if (route == currentRoute) return;
    Navigator.of(context).pushReplacementNamed(route);
  }

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: context.card,
        border: Border(top: BorderSide(color: context.divider, width: 0.5)),
      ),
      child: SafeArea(
        top: false,
        child: SizedBox(
          height: 60,
          child: Row(
            children: [
              _TlNavItem(
                icon: Icons.dashboard_outlined,
                label: 'Home',
                selected: currentRoute == '/tl-home',
                onTap: () => _go(context, '/tl-home'),
              ),
              _TlNavItem(
                icon: Icons.task_alt_outlined,
                label: 'My Task',
                selected: currentRoute == '/tl-active-task',
                onTap: () => _go(context, '/tl-active-task'),
              ),
              _TlNavItem(
                icon: Icons.history_rounded,
                label: 'History',
                selected: currentRoute == '/tl-history',
                onTap: () => _go(context, '/tl-history'),
              ),
              _TlNavItem(
                icon: Icons.person_outline_rounded,
                label: 'Profile',
                selected: currentRoute == '/tl-profile',
                onTap: () => _go(context, '/tl-profile'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _TlNavItem extends StatelessWidget {
  const _TlNavItem({
    required this.icon,
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final color = selected ? TmColors.yellow : context.textTertiary;
    return Expanded(
      child: Semantics(
        button: true,
        selected: selected,
        label: label,
        child: InkWell(
          onTap: onTap,
          child: SizedBox.expand(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(icon, color: color, size: 22),
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
