import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';
import '../../widgets/customer_secondary_header.dart';
import '../../widgets/skeleton_box.dart';

Color _secondary(BuildContext context) =>
    context.isDark ? TmColors.grey500 : const Color(0xFF6B6B6B);

class CustomerVehicleTypesScreen extends StatefulWidget {
  const CustomerVehicleTypesScreen({super.key});

  @override
  State<CustomerVehicleTypesScreen> createState() => _CustomerVehicleTypesScreenState();
}

class _CustomerVehicleTypesScreenState extends State<CustomerVehicleTypesScreen> {
  List<Map<String, dynamic>> _twoWheelers = [];
  List<Map<String, dynamic>> _fourWheelers = [];
  List<Map<String, dynamic>> _heavyVehicles = [];
  bool _loading = true;
  bool _error = false;

  @override
  void initState() {
    super.initState();
    _fetch();
  }

  Future<void> _fetch() async {
    setState(() {
      _loading = true;
      _error = false;
    });
    final results = await Future.wait([
      ApiService.fetchVehicleTypesByCategory('2_wheeler'),
      ApiService.fetchVehicleTypesByCategory('4_wheeler'),
      ApiService.fetchVehicleTypesByCategory('heavy_vehicle'),
    ]);
    if (!mounted) return;
    final twoWheelers = results[0];
    final fourWheelers = results[1];
    final heavyVehicles = results[2];
    setState(() {
      _twoWheelers = twoWheelers;
      _fourWheelers = fourWheelers;
      _heavyVehicles = heavyVehicles;
      _loading = false;
      _error = twoWheelers.isEmpty && fourWheelers.isEmpty && heavyVehicles.isEmpty;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      body: SafeArea(
        child: Column(
          children: [
            const CustomerSecondaryHeader(title: 'Vehicle Types'),
            Expanded(
              child: _loading
                  ? const _VehicleTypesAllSkeleton()
                  : _error
                      ? CustomerRetryState(
                          message: 'Vehicle types are unavailable right now.',
                          onRetry: _fetch,
                        )
                      : RefreshIndicator(
                          color: TmColors.black,
                          onRefresh: _fetch,
                          child: ListView(
                            physics: const AlwaysScrollableScrollPhysics(),
                            padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
                            children: [
                              Text(
                                'Supported Vehicles',
                                style: GoogleFonts.inter(
                                  color: context.textPrimary,
                                  fontSize: 19,
                                  fontWeight: FontWeight.w600,
                                  letterSpacing: -0.4,
                                ),
                              ),
                              const SizedBox(height: 4),
                              Text(
                                'Vehicles currently supported by our towing services.',
                                style: GoogleFonts.inter(
                                  color: _secondary(context),
                                  fontSize: 13,
                                  letterSpacing: 0.1,
                                ),
                              ),
                              const SizedBox(height: 20),
                              _VehicleGroup(
                                label: '2-Wheeler',
                                icon: Icons.two_wheeler,
                                items: _twoWheelers,
                              ),
                              _VehicleGroup(
                                label: '4-Wheeler',
                                icon: Icons.directions_car_outlined,
                                items: _fourWheelers,
                              ),
                              _VehicleGroup(
                                label: 'Heavy Vehicle',
                                icon: Icons.local_shipping_outlined,
                                items: _heavyVehicles,
                              ),
                            ],
                          ),
                        ),
            ),
          ],
        ),
      ),
    );
  }
}

class _VehicleGroup extends StatelessWidget {
  const _VehicleGroup({required this.label, required this.icon, required this.items});
  final String label;
  final IconData icon;
  final List<Map<String, dynamic>> items;

  @override
  Widget build(BuildContext context) {
    final names = items
        .map((v) => (v['name'] as String?) ?? '')
        .where((n) => n.isNotEmpty)
        .toList();
    if (names.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(bottom: 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 15,
              fontWeight: FontWeight.w600,
              letterSpacing: -0.2,
            ),
          ),
          const SizedBox(height: 8),
          Container(
            decoration: BoxDecoration(
              color: context.card,
              border: Border.all(color: context.divider),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Column(
              children: [
                for (var i = 0; i < names.length; i++) ...[
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
                    child: Row(
                      children: [
                        Icon(icon, color: context.textPrimary, size: 19),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Text(
                            names[i],
                            style: GoogleFonts.inter(
                              color: context.textPrimary,
                              fontSize: 14.5,
                              letterSpacing: 0.1,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  if (i != names.length - 1)
                    const Divider(height: 1, thickness: 1, color: Color(0xFFE5E5E5), indent: 14, endIndent: 14),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _VehicleTypesAllSkeleton extends StatelessWidget {
  const _VehicleTypesAllSkeleton();

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(20, 20, 20, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SkeletonBox(width: 160, height: 19),
          const SizedBox(height: 8),
          const SkeletonBox(width: 240, height: 13),
          const SizedBox(height: 20),
          for (var g = 0; g < 3; g++) ...[
            const SkeletonBox(width: 90, height: 15),
            const SizedBox(height: 8),
            SkeletonBox(
              width: double.infinity,
              height: 3 * 46.0,
              borderRadius: BorderRadius.circular(14),
            ),
            const SizedBox(height: 24),
          ],
        ],
      ),
    );
  }
}
