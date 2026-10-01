import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

IconData vehicleIconFor(String name) {
  final n = name.toLowerCase();
  if (n.contains('e-scooter') || n.contains('scooter')) return Icons.electric_scooter;
  if (n.contains('bicycle') || n.contains('e-bike')) return Icons.pedal_bike;
  if (n.contains('motorcycle')) return Icons.two_wheeler;
  if (n.contains('tricycle')) return Icons.electric_rickshaw_outlined;
  if (n.contains('elf') || n.contains('wheeler') || n.contains('truck')) {
    return Icons.local_shipping_outlined;
  }
  if (n.contains('van') || n.contains('l300')) return Icons.airport_shuttle_outlined;
  if (n.contains('suv')) return Icons.directions_car_outlined;
  if (n.contains('auv') || n.contains('mpv')) return Icons.directions_car_filled_outlined;
  return Icons.directions_car_outlined;
}

class VehicleTypeLabel extends StatelessWidget {
  const VehicleTypeLabel({
    super.key,
    required this.name,
    required this.color,
    this.fontSize = 12,
    this.fontWeight = FontWeight.w500,
    this.textAlign = TextAlign.center,
  });

  final String name;
  final Color color;
  final double fontSize;
  final FontWeight fontWeight;
  final TextAlign textAlign;

  @override
  Widget build(BuildContext context) {
    final style = GoogleFonts.inter(
      color: color,
      fontSize: fontSize,
      fontWeight: fontWeight,
      letterSpacing: 0.1,
      height: 1.25,
    );

    return LayoutBuilder(
      builder: (context, constraints) {
        final scaler = MediaQuery.textScalerOf(context);
        double widthOf(String text) {
          final painter = TextPainter(
            text: TextSpan(text: text, style: style),
            textDirection: Directionality.of(context),
            textScaler: scaler,
            maxLines: 1,
          )..layout();
          return painter.width;
        }

        final parts = name.split(' / ');
        final fitsOneLine = !constraints.hasBoundedWidth || widthOf(name) <= constraints.maxWidth;
        final display = (!fitsOneLine && parts.length == 2) ? '${parts[0]} /\n${parts[1]}' : name;

        return Text(
          display,
          textAlign: textAlign,
          maxLines: 2,
          softWrap: true,
          overflow: TextOverflow.ellipsis,
          style: style,
        );
      },
    );
  }
}

String plainVehicleLabel(String displayed) => displayed.replaceAll('\n', ' ');
