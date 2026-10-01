import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../services/api_service.dart';
import '../../widgets/customer_secondary_header.dart';
import '../../widgets/detail_header.dart';
import '../../widgets/skeleton_box.dart';
import '../../widgets/tm_bottom_nav.dart';
import '../../widgets/vehicle_type_widgets.dart';

Color _secondary(BuildContext context) =>
    context.isDark ? const Color(0xFFA3A3A3) : const Color(0xFF6B6B6B);

class CustomerVehicleTypesScreen extends StatefulWidget {
  const CustomerVehicleTypesScreen({super.key});

  @override
  State<CustomerVehicleTypesScreen> createState() => _CustomerVehicleTypesScreenState();
}

class _CustomerVehicleTypesScreenState extends State<CustomerVehicleTypesScreen> {
  List<String> _twoThreeWheels = [];
  List<String> _fourWheelsUp = [];
  bool _loading = true;
  bool _error = false;
  int _unreadCount = 0;

  @override
  void initState() {
    super.initState();
    _fetch();
    _fetchUnread();
  }

  Future<void> _fetchUnread() async {
    final result = await ApiService.fetchNotifications();
    if (!mounted) return;
    final count = (result['unread_count'] as int?) ?? 0;
    if (count != _unreadCount) setState(() => _unreadCount = count);
  }

  List<String> _names(List<Map<String, dynamic>> items) => items
      .map((v) => (v['name'] as String?) ?? '')
      .where((n) => n.isNotEmpty)
      .toList();

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
    final twoThree = _names(results[0]);
    final fourUp = [..._names(results[1]), ..._names(results[2])];
    setState(() {
      _twoThreeWheels = twoThree;
      _fourWheelsUp = fourUp;
      _loading = false;
      _error = twoThree.isEmpty && fourUp.isEmpty;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      bottomNavigationBar: TmBottomNav(
        currentRoute: '/vehicle-types',
        highlightedRoute: '/home',
        unreadCount: _unreadCount,
      ),
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            const DetailScreenHeader(
              title: 'Vehicle Types',
              subtitle: 'Vehicles supported by TowMate',
            ),
            Expanded(
              child: _loading
                  ? const _VehicleTypesSkeleton()
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
                            padding: const EdgeInsets.fromLTRB(16, 24, 16, 24),
                            children: [
                              _VehicleGroup(label: 'TWO & THREE WHEELS', names: _twoThreeWheels),
                              _VehicleGroup(label: 'FOUR WHEELS & UP', names: _fourWheelsUp),
                              Padding(
                                padding: const EdgeInsets.symmetric(horizontal: 4),
                                child: Text(
                                  'You will choose your vehicle type when you book a tow.',
                                  style: GoogleFonts.inter(
                                    color: _secondary(context),
                                    fontSize: 15,
                                    height: 1.4,
                                  ),
                                ),
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
  const _VehicleGroup({required this.label, required this.names});
  final String label;
  final List<String> names;

  @override
  Widget build(BuildContext context) {
    if (names.isEmpty) return const SizedBox.shrink();

    final rows = <Widget>[];
    for (var i = 0; i < names.length; i += 2) {
      rows.add(
        Padding(
          padding: EdgeInsets.only(bottom: i + 2 >= names.length ? 0 : 12),
          child: Row(
            children: [
              Expanded(child: _VehicleTile(name: names[i])),
              const SizedBox(width: 12),
              Expanded(
                child: i + 1 < names.length ? _VehicleTile(name: names[i + 1]) : const SizedBox.shrink(),
              ),
            ],
          ),
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.only(bottom: 28),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(left: 4, bottom: 12),
            child: Text(
              label,
              style: GoogleFonts.inter(
                color: _secondary(context),
                fontSize: 13,
                fontWeight: FontWeight.w700,
                letterSpacing: 1.2,
              ),
            ),
          ),
          ...rows,
        ],
      ),
    );
  }
}

class _VehicleTile extends StatelessWidget {
  const _VehicleTile({required this.name});
  final String name;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 72,
      padding: const EdgeInsets.symmetric(horizontal: 14),
      decoration: BoxDecoration(
        color: context.surface,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          Icon(vehicleIconFor(name), size: 30, color: context.textPrimary),
          const SizedBox(width: 12),
          Expanded(
            child: VehicleTypeLabel(
              name: name,
              color: context.textPrimary,
              fontSize: 14.5,
              fontWeight: FontWeight.w700,
              textAlign: TextAlign.start,
            ),
          ),
        ],
      ),
    );
  }
}

class _VehicleTypesSkeleton extends StatelessWidget {
  const _VehicleTypesSkeleton();

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 24, 16, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (var g = 0; g < 2; g++) ...[
            const SkeletonBox(width: 150, height: 13),
            const SizedBox(height: 12),
            for (var r = 0; r < 2; r++) ...[
              Row(
                children: [
                  Expanded(
                    child: SkeletonBox(
                      width: double.infinity,
                      height: 72,
                      borderRadius: BorderRadius.circular(16),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: SkeletonBox(
                      width: double.infinity,
                      height: 72,
                      borderRadius: BorderRadius.circular(16),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
            ],
            const SizedBox(height: 16),
          ],
        ],
      ),
    );
  }
}
