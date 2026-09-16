import 'package:flutter/material.dart';
import '../core/theme.dart';

class CmsImage extends StatelessWidget {
  const CmsImage({
    super.key,
    required this.imageUrl,
    this.borderRadius = BorderRadius.zero,
    this.fallbackIcon = Icons.local_shipping,
  });

  final String? imageUrl;
  final BorderRadius borderRadius;
  final IconData fallbackIcon;

  @override
  Widget build(BuildContext context) {
    final url = imageUrl;
    return ClipRRect(
      borderRadius: borderRadius,
      child: (url == null || url.isEmpty)
          ? _Fallback(icon: fallbackIcon)
          : Image.network(
              url,
              fit: BoxFit.cover,
              width: double.infinity,
              height: double.infinity,
              errorBuilder: (_, _, _) => _Fallback(icon: fallbackIcon),
              loadingBuilder: (context, child, progress) {
                if (progress == null) return child;
                return Container(color: context.surface);
              },
            ),
    );
  }
}

class _Fallback extends StatelessWidget {
  const _Fallback({required this.icon});

  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: context.surface,
      alignment: Alignment.center,
      child: Icon(icon, color: context.textTertiary, size: 28),
    );
  }
}
