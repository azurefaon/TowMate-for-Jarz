import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

/// The official Google "G" logo, rendered from Google's own brand asset.
class GoogleMark extends StatelessWidget {
  const GoogleMark({super.key, this.size = 18});

  final double size;

  @override
  Widget build(BuildContext context) {
    return SvgPicture.asset(
      'assets/icons/google_logo.svg',
      width: size,
      height: size,
    );
  }
}
