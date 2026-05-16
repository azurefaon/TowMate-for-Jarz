import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';
import '../../widgets/tm_drawer.dart';
import '../../widgets/tm_button.dart';

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
    if (role == 'Team Leader') {
      Navigator.pushReplacementNamed(context, '/tl-home');
    } else if (role != null) {
      Navigator.pushReplacementNamed(context, '/home');
    }
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
                      const _ServicesGrid(),
                      const _FeaturedCard(),
                      const _VehicleChips(),
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
        padding: const EdgeInsets.fromLTRB(24, 40, 24, 40),
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
            const SizedBox(height: 32),
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

// ─── Services grid ─────────────────────────────────────────────────────────

class _ServicesGrid extends StatelessWidget {
  const _ServicesGrid();

  @override
  Widget build(BuildContext context) {
    return Container(
      color: TmColors.white,
      padding: const EdgeInsets.fromLTRB(24, 40, 24, 40),
      child: Column(
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
          const SizedBox(height: 24),
          const Row(
            children: [
              Expanded(
                child: _ServiceChip(
                  icon: Icons.local_shipping_rounded,
                  label: 'Towing',
                ),
              ),
              SizedBox(width: 10),
              Expanded(
                child: _ServiceChip(
                  icon: Icons.build_rounded,
                  label: 'Roadside Help',
                ),
              ),
              SizedBox(width: 10),
              Expanded(
                child: _ServiceChip(
                  icon: Icons.car_repair_rounded,
                  label: 'Recovery',
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ServiceChip extends StatelessWidget {
  const _ServiceChip({required this.icon, required this.label});
  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 18),
      decoration: BoxDecoration(
        color: TmColors.grey100,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        children: [
          Icon(icon, color: TmColors.yellow, size: 28),
          const SizedBox(height: 8),
          Text(
            label,
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(
              color: TmColors.black,
              fontSize: 12,
              letterSpacing: 0.1,
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
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
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

// ─── Vehicle chips ─────────────────────────────────────────────────────────

class _VehicleChips extends StatelessWidget {
  const _VehicleChips();

  @override
  Widget build(BuildContext context) {
    return Container(
      color: TmColors.grey100,
      padding: const EdgeInsets.fromLTRB(24, 40, 24, 40),
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
            'We tow any type of vehicle',
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 13,
              letterSpacing: 0.1,
            ),
          ),
          const SizedBox(height: 20),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: const [
              _VehicleChip(
                icon: Icons.directions_car_rounded,
                label: 'Sedan / Hatchback',
              ),
              _VehicleChip(
                icon: Icons.directions_car_filled_rounded,
                label: 'SUV / Crossover',
              ),
              _VehicleChip(
                icon: Icons.local_shipping_outlined,
                label: 'Pickup Truck',
              ),
              _VehicleChip(
                icon: Icons.airport_shuttle_rounded,
                label: 'Van / MPV',
              ),
              _VehicleChip(
                icon: Icons.two_wheeler_rounded,
                label: 'Motorcycle',
              ),
              _VehicleChip(
                icon: Icons.directions_bus_rounded,
                label: 'Bus',
              ),
              _VehicleChip(
                icon: Icons.local_shipping_rounded,
                label: 'Cargo Truck',
              ),
              _VehicleChip(
                icon: Icons.directions_bus_filled_rounded,
                label: 'Jeepney',
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _VehicleChip extends StatelessWidget {
  const _VehicleChip({required this.icon, required this.label});
  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: TmColors.white,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: TmColors.grey300),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: TmColors.grey700, size: 16),
          const SizedBox(width: 6),
          Text(
            label,
            style: GoogleFonts.inter(
              color: TmColors.grey700,
              fontSize: 12,
              letterSpacing: 0.1,
            ),
          ),
        ],
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
                    onTap: () => Navigator.pushNamed(context, '/services'),
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
