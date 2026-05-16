import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/theme.dart';
import '../models/service.dart';

class ServiceCard extends StatelessWidget {
  const ServiceCard({
    super.key,
    required this.service,
    required this.onBookTap,
  });

  final Service service;
  final VoidCallback onBookTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onBookTap,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 14),
        decoration: const BoxDecoration(
          border: Border(
            bottom: BorderSide(color: TmColors.grey300, width: 0.5),
          ),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 2,
              height: 42,
              margin: const EdgeInsets.only(right: 16, top: 2),
              color: TmColors.yellow,
            ),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    service.title,
                    style: GoogleFonts.inter(
                      color: TmColors.black,
                      fontSize: 15,
                      letterSpacing: -0.2,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    service.description,
                    style: GoogleFonts.inter(
                      color: TmColors.grey500,
                      fontSize: 13,
                      letterSpacing: 0.1,
                      height: 1.5,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    service.availability,
                    style: GoogleFonts.inter(
                      color: TmColors.grey700,
                      fontSize: 11,
                      letterSpacing: 0.3,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
