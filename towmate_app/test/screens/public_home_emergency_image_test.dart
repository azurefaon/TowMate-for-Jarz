import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('the Emergency Towing CTA is wired to its own emergencyImageUrl, not the Home hero image', () {
    final source = File(
      'lib/screens/customer/public_home_screen.dart',
    ).readAsStringSync();

    expect(source.contains("_content?['emergency_image_url'] as String?"), isTrue);
    expect(
      RegExp(r'_EmergencySection\(\s*imageUrl:\s*emergencyImageUrl').hasMatch(source),
      isTrue,
    );
    expect(
      RegExp(r'_EmergencySection\(\s*imageUrl:\s*heroImageUrl').hasMatch(source),
      isFalse,
    );
  });
}
