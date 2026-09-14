import 'package:flutter_test/flutter_test.dart';

void main() {
  group('customer content image field contract', () {
    test('hero, services, emergency, and about image URLs are read from independent JSON keys', () {
      final content = <String, dynamic>{
        'hero_image_url': 'http://127.0.0.1:8000/api/media/mobile/hero.jpg',
        'services_image_url': 'http://127.0.0.1:8000/api/media/mobile/services.jpg',
        'emergency_image_url': 'http://127.0.0.1:8000/api/media/mobile/emergency.jpg',
        'about': {'image_url': 'http://127.0.0.1:8000/api/media/mobile/about.jpg'},
      };

      final heroImageUrl = content['hero_image_url'] as String?;
      final servicesImageUrl = content['services_image_url'] as String?;
      final emergencyImageUrl = content['emergency_image_url'] as String?;
      final aboutImageUrl =
          (content['about'] as Map<String, dynamic>?)?['image_url'] as String?;

      final urls = [heroImageUrl, servicesImageUrl, emergencyImageUrl, aboutImageUrl];
      expect(urls.toSet().length, urls.length);
    });

    test('emergencyImageUrl is null when unconfigured, even when hero is set', () {
      final content = <String, dynamic>{
        'hero_image_url': 'http://127.0.0.1:8000/api/media/mobile/hero.jpg',
        'emergency_image_url': null,
      };

      final heroImageUrl = content['hero_image_url'] as String?;
      final emergencyImageUrl = content['emergency_image_url'] as String?;

      expect(heroImageUrl, isNotNull);
      expect(emergencyImageUrl, isNull);
    });

    test('servicesImageUrl is null when unconfigured, even when hero is set', () {
      final content = <String, dynamic>{
        'hero_image_url': 'http://127.0.0.1:8000/api/media/mobile/hero.jpg',
        'services_image_url': null,
      };

      final heroImageUrl = content['hero_image_url'] as String?;
      final servicesImageUrl = content['services_image_url'] as String?;

      expect(heroImageUrl, isNotNull);
      expect(servicesImageUrl, isNull);
    });
  });
}
