import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/widgets/cms_image.dart';

void main() {
  group('CmsImage', () {
    testWidgets('shows fallback icon when imageUrl is null', (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: SizedBox(
              width: 200,
              height: 200,
              child: CmsImage(imageUrl: null),
            ),
          ),
        ),
      );

      expect(find.byIcon(Icons.local_shipping_outlined), findsOneWidget);
      expect(find.byType(Image), findsNothing);
    });

    testWidgets('shows fallback icon when imageUrl is empty', (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: SizedBox(
              width: 200,
              height: 200,
              child: CmsImage(imageUrl: ''),
            ),
          ),
        ),
      );

      expect(find.byIcon(Icons.local_shipping_outlined), findsOneWidget);
    });

    testWidgets('uses the provided fallbackIcon', (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: SizedBox(
              width: 200,
              height: 200,
              child: CmsImage(imageUrl: null, fallbackIcon: Icons.groups_outlined),
            ),
          ),
        ),
      );

      expect(find.byIcon(Icons.groups_outlined), findsOneWidget);
    });

    testWidgets('attempts to load a network image when imageUrl is set', (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: SizedBox(
              width: 200,
              height: 200,
              child: CmsImage(imageUrl: 'https://example.com/photo.jpg'),
            ),
          ),
        ),
      );

      expect(find.byType(Image), findsOneWidget);
    });

    testWidgets('resolves the CORS-safe /api/media/mobile/ URL shape as a real network image, not fallback', (
      tester,
    ) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: SizedBox(
              width: 200,
              height: 200,
              child: CmsImage(imageUrl: 'http://127.0.0.1:8000/api/media/mobile/abc123.jpg'),
            ),
          ),
        ),
      );

      expect(find.byType(Image), findsOneWidget);
      expect(find.byIcon(Icons.local_shipping_outlined), findsNothing);
    });
  });
}
