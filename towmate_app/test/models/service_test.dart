import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/models/service.dart';

void main() {
  group('Service.fromJson', () {
    test('maps image_url when present', () {
      final service = Service.fromJson({
        'title': 'Towing',
        'description': 'Standard towing service',
        'availability_note': '24/7',
        'category': 'Towing',
        'image_url': 'https://example.com/storage/mobile/towing.jpg',
      });

      expect(service.title, 'Towing');
      expect(service.imageUrl, 'https://example.com/storage/mobile/towing.jpg');
    });

    test('imageUrl is null when image_url is absent', () {
      final service = Service.fromJson({
        'title': 'Roadside Help',
        'description': 'Battery jumpstart and tire change',
        'availability_note': '',
        'category': 'Roadside',
      });

      expect(service.imageUrl, isNull);
    });

    test('falls back to safe defaults for missing text fields', () {
      final service = Service.fromJson(const {});

      expect(service.title, '');
      expect(service.description, '');
      expect(service.availability, '');
      expect(service.category, 'Services');
      expect(service.imageUrl, isNull);
    });

    test('maps the CORS-safe /api/media/mobile/ URL shape returned by the API', () {
      final service = Service.fromJson({
        'title': 'Emergency Towing',
        'description': 'D',
        'image_url': 'http://127.0.0.1:8000/api/media/mobile/abc123.jpg',
      });

      expect(service.imageUrl, 'http://127.0.0.1:8000/api/media/mobile/abc123.jpg');
    });
  });
}
