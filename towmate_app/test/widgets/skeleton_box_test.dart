import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/widgets/skeleton_box.dart';

void main() {
  group('SkeletonBox', () {
    testWidgets('renders without a CircularProgressIndicator', (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(body: SkeletonBox(width: 100, height: 20)),
        ),
      );

      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(find.byType(Container), findsWidgets);
    });

    testWidgets('animates opacity smoothly without throwing', (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(body: SkeletonBox(width: 100, height: 20)),
        ),
      );

      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));

      expect(tester.takeException(), isNull);
    });
  });
}
