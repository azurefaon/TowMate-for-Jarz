import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  late String publicHomeSource;
  late String servicesSource;
  late String aboutSource;
  late String drawerSource;

  setUpAll(() {
    publicHomeSource = File('lib/screens/customer/public_home_screen.dart').readAsStringSync();
    servicesSource = File('lib/screens/customer/services_screen.dart').readAsStringSync();
    aboutSource = File('lib/screens/customer/about_screen.dart').readAsStringSync();
    drawerSource = File('lib/widgets/tm_drawer.dart').readAsStringSync();
  });

  final extendedStylePattern = RegExp(r'Icons\.[a-zA-Z_]*_(rounded|outlined|sharp|two_tone)\b');
  final emojiOrPictogramPattern = RegExp(
    r'[←-⇿⌀-➿⬀-⯿\u{1F300}-\u{1FAFF}]',
    unicode: true,
  );

  group('Public Home icon stability', () {
    test('the menu control uses a real, classic Icons constant', () {
      expect(publicHomeSource.contains('Icons.menu,'), isTrue);
    });

    test('the trust strip icons exist for Fast Response, Trusted Professionals, and Wide Coverage', () {
      expect(publicHomeSource.contains("icon: Icons.bolt,\n              label: 'Fast Response'"), isTrue);
      expect(
        publicHomeSource.contains("icon: Icons.verified_user,\n              label: 'Trusted Professionals'"),
        isTrue,
      );
      expect(publicHomeSource.contains("icon: Icons.map,\n              label: 'Wide Coverage'"), isTrue);
    });

    test('the hero and emergency CmsImage fallbacks use a classic, stable icon', () {
      expect(publicHomeSource.contains('fallbackIcon: Icons.local_shipping'), isTrue);
    });

    test('no extended-style (_rounded/_outlined/_sharp/_two_tone) icon variant remains', () {
      expect(extendedStylePattern.hasMatch(publicHomeSource), isFalse);
    });

    test('no emoji or Unicode pictogram is used as an icon substitute', () {
      expect(emojiOrPictogramPattern.hasMatch(publicHomeSource), isFalse);
    });
  });

  group('Services page icon stability', () {
    test('no extended-style icon variant remains', () {
      expect(extendedStylePattern.hasMatch(servicesSource), isFalse);
    });

    test('no emoji or Unicode pictogram is used as an icon substitute', () {
      expect(emojiOrPictogramPattern.hasMatch(servicesSource), isFalse);
    });
  });

  group('About page icon stability', () {
    test('no extended-style icon variant remains', () {
      expect(extendedStylePattern.hasMatch(aboutSource), isFalse);
    });

    test('no emoji or Unicode pictogram is used as an icon substitute', () {
      expect(emojiOrPictogramPattern.hasMatch(aboutSource), isFalse);
    });
  });

  group('Shared public drawer icon stability', () {
    test('no extended-style icon variant remains', () {
      expect(extendedStylePattern.hasMatch(drawerSource), isFalse);
    });

    test('no emoji or Unicode pictogram is used as an icon substitute', () {
      expect(emojiOrPictogramPattern.hasMatch(drawerSource), isFalse);
    });
  });
}
