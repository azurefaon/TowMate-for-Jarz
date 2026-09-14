import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  late String source;

  setUpAll(() {
    source = File('lib/screens/customer/public_home_screen.dart').readAsStringSync();
  });

  group('public home content structure invariants', () {
    test('Towing Services preview reads from the real CMS services[] field', () {
      expect(source.contains("_content?['services'] as List<dynamic>?"), isTrue);
    });

    test('Towing Services preview is capped at 3 items', () {
      expect(source.contains('.take(3)'), isTrue);
    });

    test('Towing Services preview hides cleanly when the CMS list is empty, no fake cards', () {
      expect(source.contains('if (services.isNotEmpty)'), isTrue);
    });

    test('no fabricated service names are hardcoded anywhere on this screen', () {
      const fabricated = [
        'Flat Tire Change',
        'Fuel Delivery',
        'Jump Start',
        'Lockout',
      ];
      for (final name in fabricated) {
        expect(source.contains(name), isFalse, reason: '"$name" must not be hardcoded');
      }
    });

    test('View all navigates to the existing /services route', () {
      expect(
        RegExp(r"Navigator\.pushNamed\(context, '/services'\).{0,200}?'View all'", dotAll: true).hasMatch(source),
        isTrue,
      );
    });

    test('How It Works reads from the real CMS how_it_works field', () {
      expect(source.contains("_content?['how_it_works'] as List<dynamic>?"), isTrue);
    });

    test('How It Works hides cleanly when the CMS list is empty', () {
      expect(source.contains('if (howItWorks.isNotEmpty)'), isTrue);
    });

    test('How It Works renders steps in CMS order without client-side sorting or shuffling', () {
      expect(source.contains('.sort('), isFalse);
      expect(source.contains('.shuffle('), isFalse);
    });

    test('Coverage reads from the real CMS coverage_areas field', () {
      expect(source.contains("_content?['coverage_areas'] as List<dynamic>?"), isTrue);
    });

    test('Coverage hides cleanly when the CMS list is empty', () {
      expect(source.contains('if (coverageAreas.isNotEmpty)'), isTrue);
    });

    test('no hardcoded coverage locations are introduced on this screen', () {
      const fabricatedAreas = ['Metro Manila', 'Rizal', 'Cavite', 'Bulacan', 'Laguna'];
      for (final area in fabricatedAreas) {
        expect(source.contains(area), isFalse, reason: '"$area" must not be hardcoded here');
      }
    });

    test('Coverage has its own section heading, matching the other CMS sections', () {
      expect(source.contains("'Coverage'"), isTrue);
    });

    test('final section order matches the approved target structure', () {
      final order = [
        '_TopBar(',
        '_HeroSection(',
        '_TrustSection()',
        '_ServicesPreview(',
        '_EmergencySection(',
        '_HowItWorksSection(',
        '_CoverageSection(',
        '_Footer(',
      ];
      var lastIndex = -1;
      for (final marker in order) {
        final index = source.indexOf(marker);
        expect(index, greaterThan(lastIndex), reason: '$marker out of order');
        lastIndex = index;
      }
      expect(source.contains('_GuestAccountSection'), isFalse);
    });

    test('the skeleton includes a placeholder for every visible CMS section, and no more', () {
      final skeletonMatch = RegExp(
        r'class _HomeSkeleton extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(skeletonMatch, isNotNull);
      final skeletonSource = skeletonMatch!.group(0)!;

      expect(skeletonSource.contains('_SkeletonTrustItem'), isTrue);
      expect(skeletonSource.contains('_SkeletonStepRow'), isTrue);
      expect(RegExp(r'for \(var i = 0; i < 3; i\+\+\) \.\.\.\[\s*SkeletonBox\(\s*height: 76').hasMatch(skeletonSource), isTrue);
      expect(skeletonSource.contains('height: 208'), isTrue);
      expect(skeletonSource.contains('height: 64'), isTrue);
      expect(skeletonSource.contains('height: 154'), isFalse);
      expect(skeletonSource.contains('CircularProgressIndicator'), isFalse);
    });

    test('skeleton geometry for Towing Services cards and Emergency card matches the real components', () {
      expect(source.contains('width: 76,\n              height: 76,'), isTrue);
      expect(source.contains('height: 208,'), isTrue);
    });

    test('the Coverage summary never produces the awkward singular "1 more area" wording', () {
      expect(source.contains("more area\${"), isFalse);
      expect(source.contains('String _naturalJoin('), isTrue);
      expect(
        source.contains("names.length <= 4\n        ? _naturalJoin(names)"),
        isTrue,
      );
    });
  });
}
