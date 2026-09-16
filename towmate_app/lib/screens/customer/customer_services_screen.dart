import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/service.dart';
import '../../services/api_service.dart';
import '../../widgets/cms_image.dart';
import '../../widgets/customer_secondary_header.dart';
import '../../widgets/skeleton_box.dart';
import '../../widgets/tm_button.dart';

Color _secondary(BuildContext context) =>
    context.isDark ? TmColors.grey500 : const Color(0xFF6B6B6B);

class CustomerServicesScreen extends StatefulWidget {
  const CustomerServicesScreen({super.key});

  @override
  State<CustomerServicesScreen> createState() => _CustomerServicesScreenState();
}

class _CustomerServicesScreenState extends State<CustomerServicesScreen> {
  List<Service> _services = [];
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
    final content = await ApiService.fetchCustomerContent();
    if (!mounted) return;
    if (content == null) {
      setState(() {
        _loading = false;
        _error = true;
      });
      return;
    }
    final rawServices = content['services'] as List<dynamic>? ?? [];
    setState(() {
      _services = rawServices
          .map((s) => Service.fromJson(s as Map<String, dynamic>))
          .toList();
      _loading = false;
      _error = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            const CustomerSecondaryHeader(title: 'Services'),
            Expanded(
              child: _loading
                  ? const _ServicesAllSkeleton()
                  : _error
                      ? CustomerRetryState(
                          message: 'Services are unavailable right now.',
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
                                'Towing Services',
                                style: GoogleFonts.inter(
                                  color: context.textPrimary,
                                  fontSize: 19,
                                  fontWeight: FontWeight.w600,
                                  letterSpacing: -0.4,
                                ),
                              ),
                              const SizedBox(height: 4),
                              Text(
                                'Choose the service that fits your towing needs.',
                                style: GoogleFonts.inter(
                                  color: _secondary(context),
                                  fontSize: 13,
                                  letterSpacing: 0.1,
                                ),
                              ),
                              const SizedBox(height: 18),
                              if (_services.isEmpty)
                                Text(
                                  'No services are currently listed.',
                                  style: GoogleFonts.inter(
                                    color: _secondary(context),
                                    fontSize: 13,
                                  ),
                                )
                              else
                                for (final service in _services) ...[
                                  _ServiceRow(service: service),
                                  const SizedBox(height: 12),
                                ],
                            ],
                          ),
                        ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 12),
          child: TmButton.yellowPrimary(
            'Book Now',
            () => Navigator.pushNamed(context, '/book-now'),
          ),
        ),
      ),
    );
  }
}

class _ServiceRow extends StatelessWidget {
  const _ServiceRow({required this.service});
  final Service service;

  bool get _hasImage => service.imageUrl != null && service.imageUrl!.isNotEmpty;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: context.card,
        border: Border.all(color: context.divider),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 48,
            height: 48,
            child: _hasImage
                ? ClipRRect(
                    borderRadius: BorderRadius.circular(10),
                    child: CmsImage(imageUrl: service.imageUrl),
                  )
                : Icon(Icons.local_shipping, color: context.textPrimary, size: 30),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  service.title,
                  style: GoogleFonts.inter(
                    color: context.textPrimary,
                    fontSize: 15.5,
                    fontWeight: FontWeight.w600,
                    letterSpacing: -0.2,
                  ),
                ),
                if (service.description.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    service.description,
                    style: GoogleFonts.inter(
                      color: _secondary(context),
                      fontSize: 13,
                      height: 1.4,
                      letterSpacing: 0.1,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _ServicesAllSkeleton extends StatelessWidget {
  const _ServicesAllSkeleton();

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
          const SkeletonBox(width: 220, height: 13),
          const SizedBox(height: 18),
          for (var i = 0; i < 3; i++) ...[
            SkeletonBox(
              width: double.infinity,
              height: 78,
              borderRadius: BorderRadius.circular(14),
            ),
            const SizedBox(height: 12),
          ],
        ],
      ),
    );
  }
}
