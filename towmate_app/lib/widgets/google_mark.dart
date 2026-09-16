import 'dart:math' as math;
import 'package:flutter/material.dart';

class GoogleMark extends StatelessWidget {
  const GoogleMark({super.key, this.size = 18});

  final double size;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: CustomPaint(painter: _GoogleMarkPainter()),
    );
  }
}

class _GoogleMarkPainter extends CustomPainter {
  static const Color _blue = Color(0xFF4285F4);
  static const Color _green = Color(0xFF34A853);
  static const Color _yellow = Color(0xFFFBBC05);
  static const Color _red = Color(0xFFEA4335);

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);
    final radius = size.width / 2;
    final rect = Rect.fromCircle(center: center, radius: radius);
    const start = -math.pi / 2;

    final arcPaint = Paint()..style = PaintingStyle.fill;

    void drawArc(double sweepDegrees, double fromDegrees, Color color) {
      arcPaint.color = color;
      canvas.drawArc(
        rect,
        start + fromDegrees * math.pi / 180,
        sweepDegrees * math.pi / 180,
        true,
        arcPaint,
      );
    }

    drawArc(130, 0, _blue);
    drawArc(95, 130, _green);
    drawArc(65, 225, _yellow);
    drawArc(70, 290, _red);

    final holePaint = Paint()..color = Colors.white;
    canvas.drawCircle(center, radius * 0.55, holePaint);

    final barPaint = Paint()..color = _blue;
    canvas.drawRect(
      Rect.fromLTWH(
        center.dx,
        center.dy - radius * 0.16,
        radius * 0.98,
        radius * 0.32,
      ),
      barPaint,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
