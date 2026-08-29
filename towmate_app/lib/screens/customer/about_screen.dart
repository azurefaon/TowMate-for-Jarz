import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';
import '../../widgets/tm_drawer.dart';
import '../../widgets/tm_button.dart';

class AboutScreen extends StatefulWidget {
  const AboutScreen({super.key});

  @override
  State<AboutScreen> createState() => _AboutScreenState();
}

class _AboutScreenState extends State<AboutScreen> {
  bool _isLoggedIn = false;
  String? _name;

  @override
  void initState() {
    super.initState();
    ApiService.isLoggedIn().then((v) {
      if (mounted) setState(() => _isLoggedIn = v);
    });
    ApiService.getUserName().then((n) {
      if (mounted) setState(() => _name = n);
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      drawer: TmDrawer(currentRoute: '/about', isLoggedIn: _isLoggedIn, name: _name),
      body: Builder(
        builder: (context) => SafeArea(
          child: Column(
            children: [
              _TopBar(
                isLoggedIn: _isLoggedIn,
                onMenuTap: () => Scaffold.of(context).openDrawer(),
              ),
              Expanded(
                child: SingleChildScrollView(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const _AboutHeader(),
                      const _MissionSection(),
                      const _HowItWorksSection(),
                      const _CoverageSection(),
                      _ContactSection(isLoggedIn: _isLoggedIn),
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
  const _TopBar({required this.isLoggedIn, required this.onMenuTap});
  final bool isLoggedIn;
  final VoidCallback onMenuTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: context.divider, width: 0.5)),
      ),
      child: Row(
        children: [
          IconButton(
            icon: Icon(Icons.menu_rounded, color: context.textTertiary),
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

class _AboutHeader extends StatelessWidget {
  const _AboutHeader();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: TmColors.black,
      padding: const EdgeInsets.fromLTRB(24, 40, 24, 40),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'About TowMate',
            style: GoogleFonts.inter(
              color: TmColors.white,
              fontSize: 34,
              letterSpacing: -1.1,
            ),
          ),
          const SizedBox(height: 10),
          Text(
            'We built TowMate to solve a simple problem — getting help on the road should not be stressful.',
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 14,
              letterSpacing: 0.1,
              height: 1.6,
            ),
          ),
        ],
      ),
    );
  }
}

class _MissionSection extends StatelessWidget {
  const _MissionSection();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 48, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionLabel(label: 'Our Mission'),
          const SizedBox(height: 16),
          Text(
            'To provide fast, transparent, and professional towing and roadside assistance to every driver in the Philippines.',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 18,
              letterSpacing: -0.3,
              height: 1.5,
            ),
          ),
          const SizedBox(height: 20),
          Text(
            'We operate with a single commitment: when you call, we show up. No runaround, no hidden fees, no delays. Every driver deserves a reliable partner on the road.',
            style: GoogleFonts.inter(
              color: context.textTertiary,
              fontSize: 14,
              letterSpacing: 0.1,
              height: 1.65,
            ),
          ),
        ],
      ),
    );
  }
}

class _HowItWorksSection extends StatelessWidget {
  const _HowItWorksSection();

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(24, 48, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionLabel(label: 'How It Works'),
          const SizedBox(height: 24),
          const _Step(
            number: '01',
            title: 'Request a Service',
            description:
                'Create an account, choose a service, and submit your location. Takes under 2 minutes.',
          ),
          const SizedBox(height: 20),
          const _Step(
            number: '02',
            title: 'We Dispatch a Team',
            description:
                'Our nearest available team is dispatched immediately. You get a real-time ETA.',
          ),
          const SizedBox(height: 20),
          const _Step(
            number: '03',
            title: 'Problem Solved',
            description:
                'Our professional team arrives, handles your situation, and gets you back on the road.',
          ),
        ],
      ),
    );
  }
}

class _Step extends StatelessWidget {
  const _Step({
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
                  letterSpacing: -0.2,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                description,
                style: GoogleFonts.inter(
                  color: context.textTertiary,
                  fontSize: 13,
                  letterSpacing: 0.1,
                  height: 1.55,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _CoverageSection extends StatelessWidget {
  const _CoverageSection();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 48, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionLabel(label: 'Coverage'),
          const SizedBox(height: 20),
          const _CoverageRow(area: 'Metro Manila', status: 'Full coverage'),
          const _CoverageRow(area: 'Rizal & Cavite', status: 'Full coverage'),
          const _CoverageRow(area: 'Bulacan & Laguna', status: 'Full coverage'),
          const _CoverageRow(area: 'Pampanga', status: 'Expanding'),
          const _CoverageRow(area: 'Other provinces', status: 'Long distance'),
        ],
      ),
    );
  }
}

class _CoverageRow extends StatelessWidget {
  const _CoverageRow({required this.area, required this.status});
  final String area;
  final String status;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: context.divider, width: 0.5)),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            area,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 13,
              letterSpacing: 0.1,
            ),
          ),
          Text(
            status,
            style: GoogleFonts.inter(
              color: context.textSecondary,
              fontSize: 12,
              letterSpacing: 0.2,
            ),
          ),
        ],
      ),
    );
  }
}

class _ContactSection extends StatelessWidget {
  const _ContactSection({required this.isLoggedIn});
  final bool isLoggedIn;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: TmColors.black,
      margin: const EdgeInsets.only(top: 48),
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 48),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Get in touch',
            style: GoogleFonts.inter(
              color: TmColors.white,
              fontSize: 24,
              letterSpacing: -0.6,
            ),
          ),
          const SizedBox(height: 10),
          Text(
            'For inquiries, partnerships, or feedback, reach out to our team.',
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 14,
              letterSpacing: 0.1,
              height: 1.6,
            ),
          ),
          const SizedBox(height: 28),
          _ContactItem(label: 'Email', value: 'support@towmate.ph'),
          const SizedBox(height: 12),
          _ContactItem(label: 'Hotline', value: '+63 900 000 0000'),
          if (!isLoggedIn) ...[
            const SizedBox(height: 32),
            TmButton.yellowPrimary(
              'Create an Account',
              () => Navigator.pushNamed(context, '/signup'),
            ),
          ],
        ],
      ),
    );
  }
}

class _ContactItem extends StatelessWidget {
  const _ContactItem({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: GoogleFonts.inter(
            color: TmColors.grey500,
            fontSize: 11,
            letterSpacing: 0.6,
          ),
        ),
        const SizedBox(height: 2),
        Text(
          value,
          style: GoogleFonts.inter(
            color: TmColors.white,
            fontSize: 15,
            letterSpacing: 0.1,
          ),
        ),
      ],
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel({required this.label});
  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Container(
          width: 4,
          height: 16,
          decoration: BoxDecoration(
            color: TmColors.yellow,
            borderRadius: BorderRadius.circular(2),
          ),
        ),
        const SizedBox(width: 10),
        Text(
          label,
          style: GoogleFonts.inter(
            color: context.textTertiary,
            fontSize: 12,
            letterSpacing: 0.6,
          ),
        ),
      ],
    );
  }
}
