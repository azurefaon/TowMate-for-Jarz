import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme.dart';
import '../../models/service.dart';
import '../../services/api_service.dart';
import '../../widgets/tm_drawer.dart';
import '../../widgets/service_card.dart';

class ServicesScreen extends StatefulWidget {
  const ServicesScreen({super.key});

  @override
  State<ServicesScreen> createState() => _ServicesScreenState();
}

class _ServicesScreenState extends State<ServicesScreen> {
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
                      _ServicesHeader(),
                      _ServicesByCategory(onBookTap: _onBookTap),
                      const _ServicesCta(),
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

class _ServicesHeader extends StatelessWidget {
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
            'Services',
            style: GoogleFonts.inter(
              color: TmColors.white,
              fontSize: 36,
              letterSpacing: -1.2,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'Everything you need on the road,\ncovered by our professional team.',
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

class _ServicesByCategory extends StatelessWidget {
  const _ServicesByCategory({required this.onBookTap});
  final VoidCallback onBookTap;

  @override
  Widget build(BuildContext context) {
    final categories = serviceCategories;

    return Column(
      children: categories.map((category) {
        final services = servicesByCategory(category);
        return _CategorySection(
          category: category,
          services: services,
          onBookTap: onBookTap,
        );
      }).toList(),
    );
  }
}

class _CategorySection extends StatelessWidget {
  const _CategorySection({
    required this.category,
    required this.services,
    required this.onBookTap,
  });

  final String category;
  final List<Service> services;
  final VoidCallback onBookTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(24, 40, 24, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 4,
                height: 18,
                decoration: BoxDecoration(
                  color: TmColors.yellow,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              const SizedBox(width: 10),
              Text(
                category,
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 20,
                  letterSpacing: -0.5,
                ),
              ),
            ],
          ),
          const SizedBox(height: 20),
          ...services.map(
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

class _ServicesCta extends StatelessWidget {
  const _ServicesCta();

  @override
  Widget build(BuildContext context) {
    return Container(
      color: TmColors.black,
      margin: const EdgeInsets.only(top: 40),
      padding: const EdgeInsets.fromLTRB(24, 40, 24, 40),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Need something specific?',
            style: GoogleFonts.inter(
              color: TmColors.white,
              fontSize: 20,
              letterSpacing: -0.4,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'Contact our team for specialized assistance not listed above.',
            style: GoogleFonts.inter(
              color: TmColors.grey500,
              fontSize: 13,
              letterSpacing: 0.1,
              height: 1.55,
            ),
          ),
          const SizedBox(height: 24),
          GestureDetector(
            onTap: () => Navigator.pushNamed(context, '/about'),
            child: Text(
              'Contact us →',
              style: GoogleFonts.inter(
                color: TmColors.yellow,
                fontSize: 14,
                letterSpacing: 0.1,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
