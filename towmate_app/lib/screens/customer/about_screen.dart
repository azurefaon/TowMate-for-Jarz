import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';
import '../../widgets/tm_drawer.dart';
import '../../widgets/cms_image.dart';

class AboutScreen extends StatefulWidget {
  const AboutScreen({super.key});

  @override
  State<AboutScreen> createState() => _AboutScreenState();
}

class _AboutScreenState extends State<AboutScreen> {
  bool _isLoggedIn = false;
  String? _name;
  Map<String, dynamic>? _content;

  @override
  void initState() {
    super.initState();
    ApiService.isLoggedIn().then((v) {
      if (mounted) setState(() => _isLoggedIn = v);
    });
    ApiService.getUserName().then((n) {
      if (mounted) setState(() => _name = n);
    });
    _loadContent();
  }

  Future<void> _loadContent() async {
    final content = await ApiService.fetchCustomerContent();
    if (!mounted) return;
    setState(() => _content = content);
  }

  @override
  Widget build(BuildContext context) {
    final aboutText = _content?['about']?['text'] as String?;
    final aboutImageUrl = _content?['about']?['image_url'] as String?;
    final howItWorks = (_content?['how_it_works'] as List<dynamic>?) ?? [];
    final coverageAreas = (_content?['coverage_areas'] as List<dynamic>?) ?? [];
    final support = _content?['support'] as Map<String, dynamic>?;

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
                      _AboutHeader(imageUrl: aboutImageUrl),
                      _MissionSection(aboutText: aboutText),
                      const _WhyChooseUsSection(),
                      _HowItWorksSection(steps: howItWorks),
                      _CoverageSection(areas: coverageAreas),
                      _ContactSection(support: support),
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

class _AboutHeader extends StatelessWidget {
  const _AboutHeader({required this.imageUrl});
  final String? imageUrl;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 260,
      child: Stack(
        fit: StackFit.expand,
        children: [
          CmsImage(imageUrl: imageUrl, fallbackIcon: Icons.groups),
          DecoratedBox(
            decoration: BoxDecoration(color: TmColors.black.withValues(alpha: 0.68)),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 32),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.end,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'About TowMate',
                  style: GoogleFonts.inter(
                    color: TmColors.white,
                    fontSize: 30,
                    fontWeight: FontWeight.w600,
                    letterSpacing: -0.9,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  'We built TowMate to solve a simple problem — getting help on the road should not be stressful.',
                  style: GoogleFonts.inter(
                    color: TmColors.grey300,
                    fontSize: 13,
                    letterSpacing: 0.1,
                    height: 1.55,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _MissionSection extends StatelessWidget {
  const _MissionSection({required this.aboutText});
  final String? aboutText;

  static const _fallback =
      'To provide fast, transparent, and professional towing and roadside assistance to every driver in the Philippines. '
      'We operate with a single commitment: when you call, we show up. No runaround, no hidden fees, no delays.';

  @override
  Widget build(BuildContext context) {
    final text = (aboutText != null && aboutText!.trim().isNotEmpty) ? aboutText! : _fallback;

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 28, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionHeading('Our Mission'),
          const SizedBox(height: 16),
          Text(
            text,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 16,
              letterSpacing: -0.2,
              height: 1.6,
            ),
          ),
        ],
      ),
    );
  }
}

class _WhyChooseUsSection extends StatelessWidget {
  const _WhyChooseUsSection();

  static const _points = [
    {'icon': Icons.badge, 'label': 'Professional Team'},
    {'icon': Icons.local_shipping, 'label': 'Modern Fleet'},
    {'icon': Icons.support_agent, 'label': 'Reliable Assistance'},
    {'icon': Icons.map, 'label': 'Local Service Coverage'},
  ];

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 28, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionHeading('Why Choose Us'),
          const SizedBox(height: 16),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(child: _WhyChooseUsItem(point: _points[0])),
              const SizedBox(width: 20),
              Expanded(child: _WhyChooseUsItem(point: _points[1])),
            ],
          ),
          const SizedBox(height: 18),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(child: _WhyChooseUsItem(point: _points[2])),
              const SizedBox(width: 20),
              Expanded(child: _WhyChooseUsItem(point: _points[3])),
            ],
          ),
        ],
      ),
    );
  }
}

class _WhyChooseUsItem extends StatelessWidget {
  const _WhyChooseUsItem({required this.point});
  final Map<String, dynamic> point;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(point['icon'] as IconData, color: context.textPrimary, size: 18),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            point['label'] as String,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 13.5,
              fontWeight: FontWeight.w500,
              letterSpacing: -0.1,
              height: 1.3,
            ),
          ),
        ),
      ],
    );
  }
}

class _HowItWorksSection extends StatelessWidget {
  const _HowItWorksSection({required this.steps});
  final List<dynamic> steps;

  static const _fallback = [
    {
      'title': 'Request a Service',
      'description': 'Create an account, choose a service, and submit your location. Takes under 2 minutes.',
    },
    {
      'title': 'We Dispatch a Team',
      'description': 'Our nearest available team is dispatched immediately. You get a real-time ETA.',
    },
    {
      'title': 'Problem Solved',
      'description': 'Our professional team arrives, handles your situation, and gets you back on the road.',
    },
  ];

  @override
  Widget build(BuildContext context) {
    final source = steps.isNotEmpty ? steps : _fallback;

    return Container(
      margin: const EdgeInsets.fromLTRB(24, 32, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionHeading('How It Works'),
          const SizedBox(height: 18),
          for (var i = 0; i < source.length; i++) ...[
            if (i > 0) const SizedBox(height: 16),
            _Step(
              number: (i + 1).toString().padLeft(2, '0'),
              title: (source[i]['title'] as String?) ?? '',
              description: (source[i]['description'] as String?) ?? '',
            ),
          ],
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

class _CoverageSection extends StatelessWidget {
  const _CoverageSection({required this.areas});
  final List<dynamic> areas;

  static const _fallback = [
    'Metro Manila',
    'Rizal & Cavite',
    'Bulacan & Laguna',
  ];

  @override
  Widget build(BuildContext context) {
    final names = areas.isNotEmpty
        ? areas.map((a) => (a['name'] as String?) ?? '').where((n) => n.isNotEmpty).toList()
        : _fallback;

    if (names.isEmpty) {
      return const SizedBox.shrink();
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 32, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _SectionHeading('Coverage'),
          const SizedBox(height: 16),
          for (final name in names) _CoverageRow(area: name),
        ],
      ),
    );
  }
}

class _CoverageRow extends StatelessWidget {
  const _CoverageRow({required this.area});
  final String area;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: context.divider, width: 0.5)),
      ),
      child: Row(
        children: [
          Icon(Icons.location_on, size: 16, color: context.textTertiary),
          const SizedBox(width: 8),
          Text(
            area,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 13,
              letterSpacing: 0.1,
            ),
          ),
        ],
      ),
    );
  }
}

class _ContactSection extends StatelessWidget {
  const _ContactSection({required this.support});
  final Map<String, dynamic>? support;

  @override
  Widget build(BuildContext context) {
    final email = (support?['email'] as String?)?.trim();
    final phone = (support?['phone'] as String?)?.trim();
    final location = (support?['location'] as String?)?.trim();
    final hours = (support?['hours'] as String?)?.trim();

    final hasEmail = email != null && email.isNotEmpty;
    final hasPhone = phone != null && phone.isNotEmpty;
    final hasLocation = location != null && location.isNotEmpty;
    final hasHours = hours != null && hours.isNotEmpty;

    return Container(
      color: TmColors.black,
      margin: const EdgeInsets.only(top: 32),
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 40),
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
          const SizedBox(height: 24),
          if (hasEmail) ...[
            _ContactItem(label: 'Email', value: email),
            const SizedBox(height: 12),
          ],
          if (hasPhone) ...[
            _ContactItem(label: 'Hotline', value: phone),
            const SizedBox(height: 12),
          ],
          if (hasLocation) ...[
            _ContactItem(label: 'Location', value: location),
            const SizedBox(height: 12),
          ],
          if (hasHours) _ContactItem(label: 'Hours', value: hours),
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

class _SectionHeading extends StatelessWidget {
  const _SectionHeading(this.label);
  final String label;

  @override
  Widget build(BuildContext context) {
    return Text(
      label,
      style: GoogleFonts.inter(
        color: context.textPrimary,
        fontSize: 20,
        fontWeight: FontWeight.w500,
        letterSpacing: -0.5,
      ),
    );
  }
}
