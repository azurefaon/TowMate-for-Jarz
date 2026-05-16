import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/service.dart';
import '../../services/api_service.dart';
import '../../widgets/tm_drawer.dart';
import '../../widgets/tm_button.dart';
import '../../widgets/service_card.dart';

class PublicHomeScreen extends StatefulWidget {
  const PublicHomeScreen({super.key});

  @override
  State<PublicHomeScreen> createState() => _PublicHomeScreenState();
}

class _PublicHomeScreenState extends State<PublicHomeScreen> {
  @override
  void initState() {
    super.initState();
    _checkAuth();
  }

  Future<void> _checkAuth() async {
    final loggedIn = await ApiService.isLoggedIn();
    if (!mounted) return;
    if (!loggedIn) return;
    final role = await ApiService.getUserRole();
    if (!mounted) return;
    Navigator.pushReplacementNamed(
      context,
      role == 'Team Leader' ? '/tl-home' : '/home',
    );
  }

  void _onBookTap(BuildContext context) {
    Navigator.pushNamed(context, '/login');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: TmColors.white,
      drawer: const TmDrawer(currentRoute: '/'),
      body: Builder(
        builder: (context) => SafeArea(
          child: Column(
            children: [
              _TopBar(
                onMenuTap: () => Scaffold.of(context).openDrawer(),
              ),
              Expanded(
                child: SingleChildScrollView(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _HeroSection(
                        onGetStarted: () =>
                            Navigator.pushNamed(context, '/signup'),
                        onExplore: () =>
                            Navigator.pushNamed(context, '/services'),
                      ),
                      const _StatsBar(),
                      _ServicesPreview(
                        onBookTap: () => _onBookTap(context),
                        onSeeAll: () =>
                            Navigator.pushNamed(context, '/services'),
                      ),
                      const _FeaturedCard(),
                      _VehicleAssistanceSection(
                        onBookTap: () => _onBookTap(context),
                      ),
                      const _PromoSection(),
                      const _Footer(),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ─── Top bar ───────────────────────────────────────────────────────────────

class _TopBar extends StatelessWidget {
  const _TopBar({required this.onMenuTap});
  final VoidCallback onMenuTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
      decoration: const BoxDecoration(
        border: Border(bottom: BorderSide(color: TmColors.grey300, width: 0.5)),
      ),
      child: Row(
        children: [
          IconButton(
            icon: const Icon(Icons.menu_rounded, color: TmColors.grey700),
            onPressed: onMenuTap,
            tooltip: 'Menu',
            padding: EdgeInsets.zero,
            constraints: const BoxConstraints(),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Center(
              child: Text(
                'TowMate',
                style: GoogleFonts.inter(
                  color: TmColors.yellow,
                  fontSize: 22,
                  letterSpacing: -0.8,
                ),
              ),
            ),
          ),
          const SizedBox(width: 40),
        ],
      ),
    );
  }
}

// ─── Hero section ──────────────────────────────────────────────────────────

class _HeroSection extends StatelessWidget {
  const _HeroSection({
    required this.onGetStarted,
    required this.onExplore,
  });

  final VoidCallback onGetStarted;
  final VoidCallback onExplore;

  @override
  Widget build(BuildContext context) {
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: 1),
      duration: const Duration(milliseconds: 900),
      curve: Curves.easeOutCubic,
      builder: (_, value, child) => Opacity(
        opacity: value,
        child: Transform.translate(
          offset: Offset(0, (1 - value) * 24),
          child: child,
        ),
      ),
      child: Container(
        width: double.infinity,
        color: TmColors.black,
        padding: const EdgeInsets.fromLTRB(24, 48, 24, 48),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Fast.\nReliable.\nAnytime.',
              style: GoogleFonts.inter(
                color: TmColors.white,
                fontSize: 40,
                letterSpacing: -1.4,
                height: 1.1,
              ),
            ),
            const SizedBox(height: 8),
            Container(
              width: 40,
              height: 3,
              decoration: BoxDecoration(
                color: TmColors.yellow,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            const SizedBox(height: 20),
            Text(
              'Professional towing and roadside\nassistance across Metro Manila and beyond.',
              style: GoogleFonts.inter(
                color: TmColors.grey500,
                fontSize: 14,
                letterSpacing: 0.1,
                height: 1.6,
              ),
            ),
            const SizedBox(height: 36),
            Row(
              children: [
                Expanded(
                  child: TmButton.yellowPrimary('Get Started', onGetStarted),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: TmButton.ghost('Explore Services', onExplore),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

// ─── Stats bar ─────────────────────────────────────────────────────────────

class _StatsBar extends StatelessWidget {
  const _StatsBar();

  @override
  Widget build(BuildContext context) {
    return Container(
      color: TmColors.black,
      child: Container(
        margin: const EdgeInsets.fromLTRB(24, 0, 24, 0),
        padding: const EdgeInsets.symmetric(vertical: 20),
        decoration: const BoxDecoration(
          border: Border(
            top: BorderSide(color: Color(0xFF1A1A1A)),
          ),
        ),
        child: Row(
          children: [
            _StatItem(value: '5,000+', label: 'Customers'),
            _StatDivider(),
            _StatItem(value: '24 / 7', label: 'Availability'),
            _StatDivider(),
            _StatItem(value: '<15 min', label: 'Response'),
          ],
        ),
      ),
    );
  }
}

class _StatItem extends StatelessWidget {
  const _StatItem({required this.value, required this.label});
  final String value;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Column(
        children: [
          Text(
            value,
            style: GoogleFonts.inter(
              color: TmColors.yellow,
              fontSize: 16,
              letterSpacing: -0.4,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            label,
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 11,
              letterSpacing: 0.4,
            ),
          ),
        ],
      ),
    );
  }
}

class _StatDivider extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Container(
      width: 1,
      height: 32,
      color: const Color(0xFF2A2A2A),
    );
  }
}

// ─── Services preview ──────────────────────────────────────────────────────

class _ServicesPreview extends StatelessWidget {
  const _ServicesPreview({
    required this.onBookTap,
    required this.onSeeAll,
  });

  final VoidCallback onBookTap;
  final VoidCallback onSeeAll;

  @override
  Widget build(BuildContext context) {
    final preview = allServices.take(3).toList();

    return Container(
      color: TmColors.white,
      padding: const EdgeInsets.fromLTRB(24, 48, 24, 40),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Our Services',
                    style: GoogleFonts.inter(
                      color: TmColors.black,
                      fontSize: 24,
                      letterSpacing: -0.8,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Solutions for every situation',
                    style: GoogleFonts.inter(
                      color: TmColors.grey500,
                      fontSize: 13,
                      letterSpacing: 0.1,
                    ),
                  ),
                ],
              ),
              GestureDetector(
                onTap: onSeeAll,
                child: Text(
                  'See all',
                  style: GoogleFonts.inter(
                    color: TmColors.grey700,
                    fontSize: 13,
                    letterSpacing: 0.1,
                    decoration: TextDecoration.underline,
                    decorationColor: TmColors.grey700,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 28),
          ...preview.map(
            (s) => Padding(
              padding: const EdgeInsets.only(bottom: 16),
              child: ServiceCard(service: s, onBookTap: onBookTap),
            ),
          ),
        ],
      ),
    );
  }
}

// ─── Featured card ─────────────────────────────────────────────────────────

class _FeaturedCard extends StatelessWidget {
  const _FeaturedCard();

  @override
  Widget build(BuildContext context) {
    return Container(
      color: TmColors.grey100,
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 8),
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(28),
        decoration: BoxDecoration(
          color: TmColors.black,
          borderRadius: BorderRadius.circular(20),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              padding:
                  const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(
                color: TmColors.yellow,
                borderRadius: BorderRadius.circular(4),
              ),
              child: Text(
                'FEATURED',
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 10,
                  letterSpacing: 1.0,
                ),
              ),
            ),
            const SizedBox(height: 16),
            Text(
              'Emergency Towing\nAvailable 24 / 7',
              style: GoogleFonts.inter(
                color: TmColors.white,
                fontSize: 24,
                letterSpacing: -0.6,
                height: 1.2,
              ),
            ),
            const SizedBox(height: 10),
            Text(
              'Our fastest response fleet is always on standby. Call now or book through the app and we dispatch immediately.',
              style: GoogleFonts.inter(
                color: TmColors.grey500,
                fontSize: 13,
                letterSpacing: 0.1,
                height: 1.6,
              ),
            ),
            const SizedBox(height: 24),
            TmButton.yellowPrimary(
              'Book Emergency Towing',
              () => Navigator.pushNamed(context, '/login'),
            ),
          ],
        ),
      ),
    );
  }
}

// ─── Vehicle assistance section ────────────────────────────────────────────

class _VehicleAssistanceSection extends StatelessWidget {
  const _VehicleAssistanceSection({required this.onBookTap});
  final VoidCallback onBookTap;

  @override
  Widget build(BuildContext context) {
    final assistanceServices = allServices
        .where((s) => s.category == 'Assistance' || s.category == 'Emergency')
        .toList();

    return Container(
      color: TmColors.grey100,
      padding: const EdgeInsets.fromLTRB(24, 48, 24, 48),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Vehicle Assistance',
            style: GoogleFonts.inter(
              color: TmColors.black,
              fontSize: 24,
              letterSpacing: -0.8,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'Quick help for common road problems',
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 13,
              letterSpacing: 0.1,
            ),
          ),
          const SizedBox(height: 24),
          LayoutBuilder(
            builder: (context, constraints) {
              final cardWidth = (constraints.maxWidth - 12) / 2;
              return Wrap(
                spacing: 12,
                runSpacing: 12,
                children: assistanceServices.map((s) {
                  return SizedBox(
                    width: cardWidth,
                    child: _AssistanceCard(
                      service: s,
                      onBookTap: onBookTap,
                    ),
                  );
                }).toList(),
              );
            },
          ),
        ],
      ),
    );
  }
}

class _AssistanceCard extends StatefulWidget {
  const _AssistanceCard({required this.service, required this.onBookTap});
  final Service service;
  final VoidCallback onBookTap;

  @override
  State<_AssistanceCard> createState() => _AssistanceCardState();
}

class _AssistanceCardState extends State<_AssistanceCard> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: widget.onBookTap,
      onTapDown: (_) => setState(() => _pressed = true),
      onTapUp: (_) => setState(() => _pressed = false),
      onTapCancel: () => setState(() => _pressed = false),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 120),
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: _pressed ? TmColors.grey300 : TmColors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: TmColors.grey300),
          boxShadow: _pressed
              ? []
              : [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.04),
                    blurRadius: 10,
                    offset: const Offset(0, 2),
                  ),
                ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              widget.service.title,
              style: GoogleFonts.inter(
                color: TmColors.black,
                fontSize: 14,
                letterSpacing: -0.2,
              ),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
            const SizedBox(height: 6),
            Text(
              widget.service.availability,
              style: GoogleFonts.inter(
                color: TmColors.grey500,
                fontSize: 11,
                letterSpacing: 0.3,
              ),
            ),
            const SizedBox(height: 10),
            if (widget.service.priceRange != null)
              Text(
                widget.service.priceRange!,
                style: GoogleFonts.inter(
                  color: TmColors.yellow,
                  fontSize: 12,
                  letterSpacing: 0.1,
                ),
              ),
          ],
        ),
      ),
    );
  }
}

// ─── Promo section ─────────────────────────────────────────────────────────

class _PromoSection extends StatelessWidget {
  const _PromoSection();

  @override
  Widget build(BuildContext context) {
    return Container(
      color: TmColors.black,
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 56),
      child: Column(
        children: [
          Text(
            'Ready when you need us.',
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(
              color: TmColors.white,
              fontSize: 28,
              letterSpacing: -0.8,
              height: 1.15,
            ),
          ),
          const SizedBox(height: 12),
          Text(
            'Create a free account and get access to instant booking, live tracking, and 24/7 support.',
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 14,
              letterSpacing: 0.1,
              height: 1.6,
            ),
          ),
          const SizedBox(height: 32),
          TmButton.yellowPrimary(
            'Create Free Account',
            () => Navigator.pushNamed(context, '/signup'),
          ),
          const SizedBox(height: 12),
          TmButton.ghost(
            'Login',
            () => Navigator.pushNamed(context, '/login'),
          ),
        ],
      ),
    );
  }
}

// ─── Footer ────────────────────────────────────────────────────────────────

class _Footer extends StatelessWidget {
  const _Footer();

  @override
  Widget build(BuildContext context) {
    return Container(
      color: TmColors.grey100,
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'TowMate',
            style: GoogleFonts.inter(
              color: TmColors.black,
              fontSize: 18,
              letterSpacing: -0.6,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            'Fast, reliable towing and roadside assistance\nacross Metro Manila and surrounding areas.',
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 12,
              letterSpacing: 0.1,
              height: 1.6,
            ),
          ),
          const SizedBox(height: 24),
          Container(height: 1, color: TmColors.grey300),
          const SizedBox(height: 16),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                '© 2025 TowMate',
                style: GoogleFonts.inter(
                  color: TmColors.grey500,
                  fontSize: 11,
                  letterSpacing: 0.3,
                ),
              ),
              Row(
                children: [
                  _FooterLink(
                    label: 'Services',
                    onTap: () =>
                        Navigator.pushNamed(context, '/services'),
                  ),
                  const SizedBox(width: 16),
                  _FooterLink(
                    label: 'About',
                    onTap: () => Navigator.pushNamed(context, '/about'),
                  ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _FooterLink extends StatelessWidget {
  const _FooterLink({required this.label, required this.onTap});
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Text(
        label,
        style: GoogleFonts.inter(
          color: TmColors.grey700,
          fontSize: 12,
          letterSpacing: 0.2,
          decoration: TextDecoration.underline,
          decorationColor: TmColors.grey700,
        ),
      ),
    );
  }
}
