import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('the Services header and bottom CTA are wired to servicesImageUrl, not the Home hero image', () {
    final source = File(
      'lib/screens/customer/services_screen.dart',
    ).readAsStringSync();

    expect(source.contains("content?['services_image_url'] as String?"), isTrue);
    expect(source.contains("content?['hero_image_url']"), isFalse);

    expect(
      RegExp(r'_ServicesHeader\(imageUrl:\s*_servicesImageUrl').hasMatch(source),
      isTrue,
    );
    expect(
      RegExp(r'_BottomCta\(imageUrl:\s*_servicesImageUrl').hasMatch(source),
      isTrue,
    );
    expect(source.contains('_heroImageUrl'), isFalse);
  });
}
