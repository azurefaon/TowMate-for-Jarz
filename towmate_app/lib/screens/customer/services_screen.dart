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

class ServicesScreen extends StatefulWidget {
  const ServicesScreen({super.key});

  @override
  State<ServicesScreen> createState() => _ServicesScreenState();
}

class _ServicesScreenState extends State<ServicesScreen> {
  bool _isLoggedIn = false;
  String? _name;
  List<Service> _services = [];
  List<Map<String, dynamic>> _twoWheelers = [];
  List<Map<String, dynamic>> _fourWheelers = [];
  List<Map<String, dynamic>> _heavyVehicles = [];
  List<dynamic> _coverageAreas = [];
  String? _servicesImageUrl;
  bool _loading = true;

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
    final results = await Future.wait([
      ApiService.fetchCustomerContent(),
      ApiService.fetchVehicleTypesByCategory('2_wheeler'),
      ApiService.fetchVehicleTypesByCategory('4_wheeler'),
      ApiService.fetchVehicleTypesByCategory('heavy_vehicle'),
    ]);
    if (!mounted) return;

    final content = results[0] as Map<String, dynamic>?;
    final rawServices = content?['services'] as List<dynamic>? ?? [];

    setState(() {
      _services = rawServices
          .map((s) => Service.fromJson(s as Map<String, dynamic>))
          .toList();
      _servicesImageUrl = content?['services_image_url'] as String?;
      _coverageAreas = content?['coverage_areas'] as List<dynamic>? ?? [];
      _twoWheelers = results[1] as List<Map<String, dynamic>>;
      _fourWheelers = results[2] as List<Map<String, dynamic>>;
      _heavyVehicles = results[3] as List<Map<String, dynamic>>;
      _loading = false;
    });
  }

  void _onBookTap() {
    if (_isLoggedIn) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Booking flow coming soon.',
            style: GoogleFonts.inter(color: TmColors.white, fontSize: 14),
          ),
          backgroundColor: TmColors.black,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          margin: const EdgeInsets.all(16),
        ),
      );
    } else {
      Navigator.pushNamed(context, '/login');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      drawer: TmDrawer(currentRoute: '/services', isLoggedIn: _isLoggedIn, name: _name),
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
                      _ServicesHeader(imageUrl: _servicesImageUrl),
                      if (_loading)
                        const _ServicesSkeleton()
                      else ...[
                        _ServiceCards(services: _services, onBookTap: _onBookTap),
                        if (_twoWheelers.isNotEmpty ||
                            _fourWheelers.isNotEmpty ||
                            _heavyVehicles.isNotEmpty)
                          _VehicleTypesSection(
                            twoWheelers: _twoWheelers,
                            fourWheelers: _fourWheelers,
                            heavyVehicles: _heavyVehicles,
                          ),
                        if (_coverageAreas.isNotEmpty)
                          _CoverageChips(areas: _coverageAreas),
                      ],
                      _BottomCta(imageUrl: _servicesImageUrl),
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

class _ServicesHeader extends StatelessWidget {
  const _ServicesHeader({required this.imageUrl});
  final String? imageUrl;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 190,
      child: Stack(
        fit: StackFit.expand,
        children: [
          CmsImage(imageUrl: imageUrl, fallbackIcon: Icons.build),
          DecoratedBox(
            decoration: BoxDecoration(color: TmColors.black.withValues(alpha: 0.68)),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.end,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Our Services',
                  style: GoogleFonts.inter(
                    color: TmColors.white,
                    fontSize: 30,
                    fontWeight: FontWeight.w500,
                    letterSpacing: -0.9,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  'Everything you need on the road, covered by our team.',
                  style: GoogleFonts.inter(
                    color: TmColors.grey300,
                    fontSize: 13,
                    letterSpacing: 0.1,
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

class _ServicesSkeleton extends StatelessWidget {
  const _ServicesSkeleton();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 28, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (var i = 0; i < 3; i++) ...[
            SkeletonBox(
              height: 72,
              width: double.infinity,
              borderRadius: BorderRadius.circular(16),
            ),
            const SizedBox(height: 14),
          ],
          const SizedBox(height: 22),
          const SkeletonBox(width: 150, height: 20),
          const SizedBox(height: 16),
          const SkeletonBox(width: 90, height: 12),
          const SizedBox(height: 8),
          const SkeletonBox(width: 220, height: 14),
          const SizedBox(height: 18),
          const SkeletonBox(width: 90, height: 12),
          const SizedBox(height: 8),
          const SkeletonBox(width: 260, height: 14),
          const SizedBox(height: 18),
          const SkeletonBox(width: 100, height: 12),
          const SizedBox(height: 8),
          const SkeletonBox(width: 240, height: 14),
        ],
      ),
    );
  }
}

class _ServiceCards extends StatelessWidget {
  const _ServiceCards({required this.services, required this.onBookTap});
  final List<Service> services;
  final VoidCallback onBookTap;

  @override
  Widget build(BuildContext context) {
    if (services.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'Services will be listed here soon.',
          style: GoogleFonts.inter(color: context.textSecondary, fontSize: 13),
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 28, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (final service in services) ...[
            _ServiceCard(service: service, onTap: onBookTap),
            const SizedBox(height: 14),
          ],
        ],
      ),
    );
  }
}

class _ServiceCard extends StatelessWidget {
  const _ServiceCard({required this.service, required this.onTap});
  final Service service;
  final VoidCallback onTap;

  bool get _hasImage => service.imageUrl != null && service.imageUrl!.isNotEmpty;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        decoration: BoxDecoration(
          color: context.card,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: context.divider),
        ),
        clipBehavior: Clip.antiAlias,
        child: _hasImage ? _imageLayout(context) : _compactLayout(context),
      ),
    );
  }

  Widget _imageLayout(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          height: 108,
          width: double.infinity,
          child: CmsImage(imageUrl: service.imageUrl),
        ),
        Padding(
          padding: const EdgeInsets.all(14),
          child: _serviceText(context),
        ),
      ],
    );
  }

  Widget _compactLayout(BuildContext context) {
    return Row(
      children: [
        SizedBox(
          width: 72,
          height: 72,
          child: CmsImage(imageUrl: null),
        ),
        Expanded(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            child: _serviceText(context),
          ),
        ),
      ],
    );
  }

  Widget _serviceText(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                service.title,
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 15,
                  fontWeight: FontWeight.w500,
                  letterSpacing: -0.3,
                ),
              ),
            ),
            Icon(Icons.chevron_right, color: context.textTertiary, size: 20),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          service.description,
          maxLines: _hasImage ? null : 2,
          overflow: _hasImage ? TextOverflow.visible : TextOverflow.ellipsis,
          style: GoogleFonts.inter(
            color: context.textSecondary,
            fontSize: 12.5,
            letterSpacing: 0.1,
            height: 1.4,
          ),
        ),
        if (service.availability.isNotEmpty) ...[
          const SizedBox(height: 6),
          Text(
            service.availability,
            style: GoogleFonts.inter(
              color: TmColors.yellow,
              fontSize: 11,
              fontWeight: FontWeight.w600,
              letterSpacing: 0.2,
            ),
          ),
        ],
      ],
    );
  }
}

class _VehicleTypesSection extends StatelessWidget {
  const _VehicleTypesSection({
    required this.twoWheelers,
    required this.fourWheelers,
    required this.heavyVehicles,
  });
  final List<Map<String, dynamic>> twoWheelers;
  final List<Map<String, dynamic>> fourWheelers;
  final List<Map<String, dynamic>> heavyVehicles;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 36, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Supported Vehicles',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 20,
              fontWeight: FontWeight.w500,
              letterSpacing: -0.5,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'Vehicle types supported by our towing services.',
            style: GoogleFonts.inter(
              color: context.textSecondary,
              fontSize: 13,
            ),
          ),
          const SizedBox(height: 16),
          if (twoWheelers.isNotEmpty)
            _VehicleGroup(label: '2-Wheeler', vehicleTypes: twoWheelers),
          if (fourWheelers.isNotEmpty) ...[
            if (twoWheelers.isNotEmpty) const SizedBox(height: 18),
            _VehicleGroup(label: '4-Wheeler', vehicleTypes: fourWheelers),
          ],
          if (heavyVehicles.isNotEmpty) ...[
            if (twoWheelers.isNotEmpty || fourWheelers.isNotEmpty) const SizedBox(height: 18),
            _VehicleGroup(label: 'Heavy Vehicle', vehicleTypes: heavyVehicles),
          ],
        ],
      ),
    );
  }
}

class _VehicleGroup extends StatelessWidget {
  const _VehicleGroup({required this.label, required this.vehicleTypes});
  final String label;
  final List<Map<String, dynamic>> vehicleTypes;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: GoogleFonts.inter(
            color: context.textTertiary,
            fontSize: 12,
            fontWeight: FontWeight.w600,
            letterSpacing: 0.3,
          ),
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 16,
          runSpacing: 8,
          children: [
            for (final vt in vehicleTypes)
              _VehicleName(name: (vt['name'] as String?) ?? ''),
          ],
        ),
      ],
    );
  }
}

class _VehicleName extends StatelessWidget {
  const _VehicleName({required this.name});
  final String name;

  @override
  Widget build(BuildContext context) {
    if (name.isEmpty) return const SizedBox.shrink();
    return Text(
      name,
      style: GoogleFonts.inter(
        color: context.textPrimary,
        fontSize: 13,
        letterSpacing: 0.1,
      ),
    );
  }
}

class _CoverageChips extends StatelessWidget {
  const _CoverageChips({required this.areas});
  final List<dynamic> areas;

  @override
  Widget build(BuildContext context) {
    final names = areas
        .map((a) => (a['name'] as String?) ?? '')
        .where((n) => n.isNotEmpty)
        .toList();
    if (names.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 36, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Areas We Cover',
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 20,
              fontWeight: FontWeight.w500,
              letterSpacing: -0.5,
            ),
          ),
          const SizedBox(height: 16),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final name in names)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  decoration: BoxDecoration(
                    color: context.surface,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.location_on, size: 14, color: context.textTertiary),
                      const SizedBox(width: 6),
                      Text(
                        name,
                        style: GoogleFonts.inter(
                          color: context.textPrimary,
                          fontSize: 12.5,
                        ),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _BottomCta extends StatelessWidget {
  const _BottomCta({required this.imageUrl});
  final String? imageUrl;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(24, 32, 24, 32),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(18),
        child: SizedBox(
          height: 186,
          child: Stack(
            fit: StackFit.expand,
            children: [
              CmsImage(imageUrl: imageUrl, fallbackIcon: Icons.local_shipping),
              DecoratedBox(
                decoration: BoxDecoration(color: TmColors.black.withValues(alpha: 0.72)),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 20, 20, 20),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.end,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Need towing assistance?',
                      style: GoogleFonts.inter(
                        color: TmColors.white,
                        fontSize: 18,
                        fontWeight: FontWeight.w500,
                        letterSpacing: -0.4,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'Sign in or create an account to request towing.',
                      style: GoogleFonts.inter(
                        color: TmColors.grey300,
                        fontSize: 12.5,
                      ),
                    ),
                    const SizedBox(height: 14),
                    SizedBox(
                      width: 160,
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
