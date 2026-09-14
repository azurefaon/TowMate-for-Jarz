import 'package:flutter/material.dart';
import '../core/theme.dart';

class SkeletonBox extends StatefulWidget {
  const SkeletonBox({
    super.key,
    this.width,
    this.height = 14,
    this.borderRadius = const BorderRadius.all(Radius.circular(6)),
  });

  final double? width;
  final double height;
  final BorderRadius borderRadius;

  @override
  State<SkeletonBox> createState() => _SkeletonBoxState();
}

class _SkeletonBoxState extends State<SkeletonBox>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1000),
  )..repeat(reverse: true);

  late final Animation<double> _opacity = Tween<double>(
    begin: 0.55,
    end: 1.0,
  ).animate(CurvedAnimation(parent: _controller, curve: Curves.easeInOut));

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _opacity,
      builder: (context, _) => Opacity(
        opacity: _opacity.value,
        child: Container(
          width: widget.width,
          height: widget.height,
          decoration: BoxDecoration(
            color: context.surface,
            borderRadius: widget.borderRadius,
          ),
        ),
      ),
    );
  }
}
