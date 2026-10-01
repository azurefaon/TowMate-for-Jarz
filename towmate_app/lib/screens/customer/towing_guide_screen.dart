import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/truck_type_model.dart';
import '../../models/vehicle_type_model.dart';
import '../../services/api_service.dart';
import '../../widgets/detail_header.dart';
import '../../widgets/tm_bottom_nav.dart';

const _guideCategories = {'2_wheeler', '4_wheeler', 'heavy_vehicle'};

const _guideClasses = [
  (
    key: 'light',
    title: 'Light Duty',
    description: 'For smaller passenger vehicles that typically need standard towing support.',
  ),
  (
    key: 'medium',
    title: 'Medium Duty',
    description: 'For larger vehicles that may require additional towing capacity.',
  ),
  (
    key: 'heavy',
    title: 'Heavy Duty',
    description: 'For larger commercial or heavy vehicles that require specialized towing equipment.',
  ),
];

class TowingGuideScreen extends StatefulWidget {
  const TowingGuideScreen({super.key});

  @override
  State<TowingGuideScreen> createState() => _TowingGuideScreenState();
}

class _TowingGuideScreenState extends State<TowingGuideScreen> {
  Map<String, List<String>> _vehiclesByClass = {};
  int _unreadCount = 0;

  @override
  void initState() {
    super.initState();
    _loadMapping();
    _fetchUnread();
  }

  Future<void> _fetchUnread() async {
    final result = await ApiService.fetchNotifications();
    if (!mounted) return;
    final count = (result['unread_count'] as int?) ?? 0;
    if (count != _unreadCount) setState(() => _unreadCount = count);
  }

  Future<void> _loadMapping() async {
    final List<TruckTypeModel> truckTypes = await ApiService.fetchTruckTypes();
    final List<VehicleTypeModel> vehicleTypes = await ApiService.fetchVehicleTypes();
    if (!mounted) return;
    final classByTruckId = {for (final t in truckTypes) t.id: t.truckClass};

    final grouped = <String, List<String>>{};
    for (final vehicle in vehicleTypes) {
      if (!_guideCategories.contains(vehicle.category)) continue;
      final truckId = vehicle.requiredTruckTypeId;
      final truckClass = truckId == null ? null : classByTruckId[truckId];
      if (truckClass == null || truckClass.isEmpty) continue;
      grouped.putIfAbsent(truckClass, () => []).add(vehicle.name);
    }
    setState(() => _vehiclesByClass = grouped);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      bottomNavigationBar: TmBottomNav(
        currentRoute: '/towing-guide',
        highlightedRoute: '/home',
        unreadCount: _unreadCount,
      ),
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            const DetailScreenHeader(
              title: 'Towing Guide',
              subtitle: 'Find the right towing option for your vehicle.',
            ),
            Expanded(
              child: ListView(
                padding: EdgeInsets.zero,
                children: [
                  for (final c in _guideClasses)
                    _GuideSection(
                      title: c.title,
                      description: c.description,
                      vehicles: _vehiclesByClass[c.key] ?? const [],
                    ),
                  const _StillNotSure(),
                  const SizedBox(height: 24),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _GuideSection extends StatelessWidget {
  const _GuideSection({required this.title, required this.description, required this.vehicles});
  final String title;
  final String description;
  final List<String> vehicles;

  @override
  Widget build(BuildContext context) {
    final muted = context.isDark ? const Color(0xFFA3A3A3) : const Color(0xFF6B6B6B);
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 24, 20, 24),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: context.divider)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 60,
                height: 60,
                decoration: BoxDecoration(
                  color: TmColors.yellow,
                  borderRadius: BorderRadius.circular(16),
                ),
                child: const Icon(Icons.local_shipping_outlined, size: 30, color: TmColors.black),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Text(
                  title,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 24,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -0.5,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Text(
            description,
            style: GoogleFonts.inter(color: context.textPrimary, fontSize: 16, height: 1.45),
          ),
          if (vehicles.isNotEmpty) ...[
            const SizedBox(height: 16),
            Text(
              'COMMON VEHICLES',
              style: GoogleFonts.inter(
                color: muted,
                fontSize: 12,
                fontWeight: FontWeight.w700,
                letterSpacing: 1.2,
              ),
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final name in vehicles)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: context.divider),
                    ),
                    child: Text(
                      name,
                      style: GoogleFonts.inter(color: context.textPrimary, fontSize: 13.5),
                    ),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _StillNotSure extends StatelessWidget {
  const _StillNotSure();

  @override
  Widget build(BuildContext context) {
    final muted = context.isDark ? const Color(0xFFA3A3A3) : const Color(0xFF6B6B6B);
    final band = context.isDark ? const Color(0xFF1C1C1C) : const Color(0xFFF6F7F9);
    return Container(
      width: double.infinity,
      color: band,
      padding: const EdgeInsets.fromLTRB(20, 26, 20, 26),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: context.surface,
                ),
                child: Icon(Icons.help_outline, size: 20, color: context.textPrimary),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  'Still not sure?',
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 19,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Text(
            'Choose your vehicle when creating a towing request and TowMate will help determine the appropriate towing option.',
            style: GoogleFonts.inter(color: muted, fontSize: 15.5, height: 1.45),
          ),
        ],
      ),
    );
  }
}
