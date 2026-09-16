import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/service.dart';
import '../../services/api_service.dart';
import '../../widgets/tm_drawer.dart';
import '../../widgets/tm_button.dart';
import '../../widgets/cms_image.dart';
import '../../widgets/skeleton_box.dart';
import '../../widgets/auth_gate_sheet.dart';

class PublicHomeScreen extends StatefulWidget {
  const PublicHomeScreen({super.key});

  @override
  State<PublicHomeScreen> createState() => _PublicHomeScreenState();
}

class _PublicHomeScreenState extends State<PublicHomeScreen> {
  Map<String, dynamic>? _content;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _checkAuth();
    _loadContent();
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

  Future<void> _loadContent() async {
    final content = await ApiService.fetchCustomerContent();
    if (!mounted) return;
    setState(() {
      _content = content;
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final heroImageUrl = _content?['hero_image_url'] as String?;
    final emergencyImageUrl = _content?['emergency_image_url'] as String?;
    final rawServices = (_content?['services'] as List<dynamic>?) ?? [];
    final services = rawServices
        .map((s) => Service.fromJson(s as Map<String, dynamic>))
        .take(3)
        .toList();
    final howItWorks = (_content?['how_it_works'] as List<dynamic>?) ?? [];
    final coverageAreas =
        (_content?['coverage_areas'] as List<dynamic>?) ?? [];
    final supportHours =
        (_content?['support']?['hours'] as String?)?.trim();
    final supportPhone = (_content?['support']?['phone'] as String?)?.trim();
    final supportEmail = (_content?['support']?['email'] as String?)?.trim();

    return Scaffold(
      backgroundColor: context.bg,
      drawer: const TmDrawer(currentRoute: '/'),
      body: Builder(
        builder: (context) => SafeArea(
          child: Column(
            children: [
              _TopBar(onMenuTap: () => Scaffold.of(context).openDrawer()),
              Expanded(
                child: _loading
                    ? const SingleChildScrollView(child: _HomeSkeleton())
                    : SingleChildScrollView(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            _HeroSection(
                              imageUrl: heroImageUrl,
                              onGetStarted: () => showAuthGateSheet(context),
                              onExplore: () =>
                                  Navigator.pushNamed(context, '/services'),
                            ),
                            const _TrustSection(),
                            if (services.isNotEmpty)
                              _ServicesPreview(services: services),
                            _EmergencySection(
                              imageUrl: emergencyImageUrl,
                              hours: supportHours,
                            ),
                            if (howItWorks.isNotEmpty)
                              _HowItWorksSection(steps: howItWorks),
                            if (coverageAreas.isNotEmpty)
                              _CoverageSection(areas: coverageAreas),
                            _Footer(phone: supportPhone, email: supportEmail),
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

class _TopBar extends StatelessWidget {
  const _TopBar({required this.onMenuTap});
  final VoidCallback onMenuTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 52,
      padding: const EdgeInsets.symmetric(horizontal: 12),
      decoration: BoxDecoration(
        color: context.bg,
        border: Border(
          bottom: BorderSide(
            color: context.divider.withValues(alpha: 0.6),
            width: 0.5,
          ),
        ),
      ),
      child: Row(
        children: [
          SizedBox(
            width: 40,
            height: 40,
            child: IconButton(
              icon: Icon(Icons.menu, color: context.textTertiary, size: 22),
              onPressed: onMenuTap,
              tooltip: 'Menu',
              padding: EdgeInsets.zero,
              constraints: const BoxConstraints(),
            ),
          ),
          Expanded(
            child: Center(
              child: Text(
                'TowMate',
                style: GoogleFonts.inter(
                  color: TmColors.yellow,
                  fontSize: 18,
                  fontWeight: FontWeight.w600,
                  letterSpacing: -0.5,
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

class _HomeSkeleton extends StatelessWidget {
  const _HomeSkeleton();

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          height: 440,
          width: double.infinity,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 28),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.end,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const SkeletonBox(width: 220, height: 30),
                const SizedBox(height: 8),
                const SkeletonBox(width: 180, height: 30),
                const SizedBox(height: 14),
                SkeletonBox(width: MediaQuery.of(context).size.width - 48, height: 14),
                const SizedBox(height: 8),
                const SkeletonBox(width: 240, height: 14),
                const SizedBox(height: 20),
                Row(
                  children: [
                    Expanded(
                      child: SkeletonBox(
                        height: 52,
                        borderRadius: BorderRadius.circular(26),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: SkeletonBox(
                        height: 52,
                        borderRadius: BorderRadius.circular(26),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 20),
          child: Row(
            children: const [
              Expanded(child: _SkeletonTrustItem()),
              Expanded(child: _SkeletonTrustItem()),
              Expanded(child: _SkeletonTrustItem()),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 4, 24, 28),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SkeletonBox(width: 160, height: 20),
              const SizedBox(height: 16),
              for (var i = 0; i < 3; i++) ...[
                SkeletonBox(
                  height: 76,
                  width: double.infinity,
                  borderRadius: BorderRadius.circular(16),
                ),
                const SizedBox(height: 10),
              ],
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 0, 24, 8),
          child: SkeletonBox(
            height: 208,
            width: double.infinity,
            borderRadius: BorderRadius.circular(18),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 24, 24, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SkeletonBox(width: 140, height: 20),
              const SizedBox(height: 16),
              for (var i = 0; i < 3; i++) ...[
                if (i > 0) const SizedBox(height: 14),
                const _SkeletonStepRow(),
              ],
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(24, 24, 24, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SkeletonBox(width: 120, height: 20),
              const SizedBox(height: 16),
              SkeletonBox(
                height: 64,
                width: double.infinity,
                borderRadius: BorderRadius.circular(14),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _SkeletonTrustItem extends StatelessWidget {
  const _SkeletonTrustItem();

  @override
  Widget build(BuildContext context) {
    return const Column(
      children: [
        SkeletonBox(width: 24, height: 24, borderRadius: BorderRadius.all(Radius.circular(6))),
        SizedBox(height: 10),
        SkeletonBox(width: 64, height: 10),
      ],
    );
  }
}

class _SkeletonStepRow extends StatelessWidget {
  const _SkeletonStepRow();

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SkeletonBox(width: 20, height: 16),
        const SizedBox(width: 16),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: const [
              SkeletonBox(width: 140, height: 15),
              SizedBox(height: 6),
              SkeletonBox(width: double.infinity, height: 13),
            ],
          ),
        ),
      ],
    );
  }
}

class _HeroSection extends StatelessWidget {
  const _HeroSection({
    required this.imageUrl,
    required this.onGetStarted,
    required this.onExplore,
  });

  final String? imageUrl;
  final VoidCallback onGetStarted;
  final VoidCallback onExplore;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 440,
      child: Stack(
        fit: StackFit.expand,
        children: [
          CmsImage(imageUrl: imageUrl, fallbackIcon: Icons.local_shipping),
          DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [
                  TmColors.black.withValues(alpha: 0.15),
                  TmColors.black.withValues(alpha: 0.78),
                ],
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 28),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.end,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'On the road,\nalways with you.',
                  style: GoogleFonts.inter(
                    color: TmColors.white,
                    fontSize: 34,
                    fontWeight: FontWeight.w600,
                    letterSpacing: -1.0,
                    height: 1.14,
                  ),
                ),
                const SizedBox(height: 10),
                Text(
                  'Professional towing and roadside assistance, ready when your trip does not go as planned.',
                  style: GoogleFonts.inter(
                    color: TmColors.grey300,
                    fontSize: 14,
                    letterSpacing: 0.1,
                    height: 1.5,
                  ),
                ),
                const SizedBox(height: 20),
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
        ],
      ),
    );
  }
}

class _TrustSection extends StatelessWidget {
  const _TrustSection();

  @override
  Widget build(BuildContext context) {
    return Container(
      color: context.bg,
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 20),
      child: const Row(
        children: [
          Expanded(
            child: _TrustItem(
              icon: Icons.bolt,
              label: 'Fast Response',
            ),
          ),
          Expanded(
            child: _TrustItem(
              icon: Icons.verified_user,
              label: 'Trusted Professionals',
            ),
          ),
          Expanded(
            child: _TrustItem(
              icon: Icons.map,
              label: 'Wide Coverage',
            ),
          ),
        ],
      ),
    );
  }
}

class _TrustItem extends StatelessWidget {
  const _TrustItem({required this.icon, required this.label});
  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Icon(icon, color: context.textPrimary, size: 22),
        const SizedBox(height: 8),
        Text(
          label,
          textAlign: TextAlign.center,
          style: GoogleFonts.inter(
            color: context.textSecondary,
            fontSize: 12,
            fontWeight: FontWeight.w500,
            letterSpacing: 0.1,
          ),
        ),
      ],
    );
  }
}

class _ServicesPreview extends StatelessWidget {
  const _ServicesPreview({required this.services});
  final List<Service> services;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: context.surface,
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 28),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Towing Services',
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 22,
                  fontWeight: FontWeight.w500,
                  letterSpacing: -0.6,
                ),
              ),
              GestureDetector(
                onTap: () => Navigator.pushNamed(context, '/services'),
                child: Text(
                  'View all',
                  style: GoogleFonts.inter(
                    color: TmColors.yellow,
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            'Choose the towing support that fits your vehicle.',
            style: GoogleFonts.inter(
              color: context.textSecondary,
              fontSize: 13,
              letterSpacing: 0.1,
            ),
          ),
          const SizedBox(height: 16),
          for (final service in services) ...[
            _ServicePreviewCard(service: service),
            const SizedBox(height: 10),
          ],
        ],
      ),
    );
  }
}

class _ServicePreviewCard extends StatelessWidget {
  const _ServicePreviewCard({required this.service});
  final Service service;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => Navigator.pushNamed(context, '/services'),
      child: Container(
        decoration: BoxDecoration(
          color: context.card,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: context.divider),
        ),
        clipBehavior: Clip.antiAlias,
        child: Row(
          children: [
            SizedBox(
              width: 76,
              height: 76,
              child: CmsImage(imageUrl: service.imageUrl),
            ),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      service.title,
                      style: GoogleFonts.inter(
                        color: context.textPrimary,
                        fontSize: 14.5,
                        fontWeight: FontWeight.w500,
                        letterSpacing: -0.2,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      service.description,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: GoogleFonts.inter(
                        color: context.textSecondary,
                        fontSize: 12,
                        letterSpacing: 0.1,
                        height: 1.35,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.only(right: 10),
              child: Icon(Icons.chevron_right, color: context.textTertiary, size: 20),
            ),
          ],
        ),
      ),
    );
  }
}

class _EmergencySection extends StatelessWidget {
  const _EmergencySection({required this.imageUrl, required this.hours});
  final String? imageUrl;
  final String? hours;

  @override
  Widget build(BuildContext context) {
    final availability = (hours != null && hours!.isNotEmpty)
        ? hours!
        : 'Ready when you need us';

    return Container(
      color: context.bg,
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 8),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(18),
        child: SizedBox(
          height: 208,
          child: Stack(
            fit: StackFit.expand,
            children: [
              CmsImage(imageUrl: imageUrl, fallbackIcon: Icons.local_shipping),
              DecoratedBox(
                decoration: BoxDecoration(
                  color: TmColors.black.withValues(alpha: 0.72),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(22, 22, 22, 24),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.end,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Emergency Towing',
                      style: GoogleFonts.inter(
                        color: TmColors.white,
                        fontSize: 21,
                        fontWeight: FontWeight.w600,
                        letterSpacing: -0.5,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      availability,
                      style: GoogleFonts.inter(
                        color: TmColors.yellow,
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 0.2,
                      ),
                    ),
                    const SizedBox(height: 18),
                    SizedBox(
                      width: 200,
                      child: TmButton.yellowPrimary(
                        'Request Towing',
                        () => showAuthGateSheet(context),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _HowItWorksSection extends StatelessWidget {
  const _HowItWorksSection({required this.steps});
  final List<dynamic> steps;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: context.bg,
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'How It Works',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 22,
              fontWeight: FontWeight.w500,
              letterSpacing: -0.6,
            ),
          ),
          const SizedBox(height: 16),
          for (var i = 0; i < steps.length && i < 3; i++) ...[
            if (i > 0) const SizedBox(height: 14),
            _StepRow(
              number: (i + 1).toString().padLeft(2, '0'),
              title: (steps[i]['title'] as String?) ?? '',
              description: (steps[i]['description'] as String?) ?? '',
            ),
          ],
        ],
      ),
    );
  }
}

class _StepRow extends StatelessWidget {
  const _StepRow({
    required this.number,
    required this.title,
    required this.description,
  });

  final String number;
  final String title;
  final String description;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 36,
          child: Text(
            number,
            style: GoogleFonts.inter(
              color: TmColors.yellow,
              fontSize: 16,
              fontWeight: FontWeight.w600,
              letterSpacing: -0.4,
            ),
          ),
        ),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 15,
                  fontWeight: FontWeight.w500,
                  letterSpacing: -0.2,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                description,
                style: GoogleFonts.inter(
                  color: context.textSecondary,
                  fontSize: 13,
                  letterSpacing: 0.1,
                  height: 1.5,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

String _naturalJoin(List<String> items) {
  if (items.isEmpty) return '';
  if (items.length == 1) return items.first;
  if (items.length == 2) return '${items[0]} and ${items[1]}';
  return '${items.sublist(0, items.length - 1).join(', ')}, and ${items.last}';
}

class _CoverageSection extends StatelessWidget {
  const _CoverageSection({required this.areas});
  final List<dynamic> areas;

  @override
  Widget build(BuildContext context) {
    final names = areas
        .map((a) => (a['name'] as String?) ?? '')
        .where((n) => n.isNotEmpty)
        .toList();
    if (names.isEmpty) return const SizedBox.shrink();

    final summary = names.length <= 4
        ? _naturalJoin(names)
        : '${names.take(3).join(', ')}, and ${names.length - 3} more areas';

    return Container(
      color: context.bg,
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Coverage',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 22,
              fontWeight: FontWeight.w500,
              letterSpacing: -0.6,
            ),
          ),
          const SizedBox(height: 16),
          GestureDetector(
            onTap: () => Navigator.pushNamed(context, '/about'),
            child: Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: context.surface,
                borderRadius: BorderRadius.circular(14),
              ),
              child: Row(
                children: [
                  Icon(Icons.map, color: context.textPrimary, size: 22),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Serving $summary',
                          style: GoogleFonts.inter(
                            color: context.textPrimary,
                            fontSize: 14,
                            fontWeight: FontWeight.w500,
                            letterSpacing: -0.1,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          'See full coverage',
                          style: GoogleFonts.inter(
                            color: context.textSecondary,
                            fontSize: 12,
                          ),
                        ),
                      ],
                    ),
                  ),
                  Icon(Icons.chevron_right, color: context.textTertiary),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Footer extends StatelessWidget {
  const _Footer({this.phone, this.email});
  final String? phone;
  final String? email;

  @override
  Widget build(BuildContext context) {
    final contact = (phone != null && phone!.isNotEmpty)
        ? phone
        : (email != null && email!.isNotEmpty)
        ? email
        : null;

    return Container(
      color: context.bg,
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'TowMate',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 15,
              fontWeight: FontWeight.w600,
              letterSpacing: -0.4,
            ),
          ),
          const SizedBox(height: 3),
          Text(
            'Towing and roadside assistance you can rely on.',
            style: GoogleFonts.inter(
              color: context.textSecondary,
              fontSize: 12,
              letterSpacing: 0.1,
            ),
          ),
          if (contact != null) ...[
            const SizedBox(height: 4),
            Text(
              contact,
              style: GoogleFonts.inter(
                color: context.textSecondary,
                fontSize: 12,
                letterSpacing: 0.1,
              ),
            ),
          ],
          const SizedBox(height: 14),
          Container(height: 1, color: context.divider.withValues(alpha: 0.7)),
          const SizedBox(height: 12),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                '© 2025 TowMate',
                style: GoogleFonts.inter(
                  color: context.textSecondary,
                  fontSize: 11,
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
          color: context.textTertiary,
          fontSize: 12,
          letterSpacing: 0.1,
        ),
      ),
    );
  }
}
