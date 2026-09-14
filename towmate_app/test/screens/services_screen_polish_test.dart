import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  late String source;

  setUpAll(() {
    source = File('lib/screens/customer/services_screen.dart').readAsStringSync();
  });

  group('services screen polish invariants', () {
    test('has no CircularProgressIndicator on the public loading path', () {
      expect(source.contains('CircularProgressIndicator'), isFalse);
    });

    test('has no persistent bottom navigation', () {
      expect(source.contains('BottomNavigationBar'), isFalse);
      expect(source.contains('NavigationBar('), isFalse);
    });

    test('a service card with a CMS image uses the image-led layout, not the compact fallback', () {
      final match = RegExp(
        r'Widget _imageLayout\(BuildContext context\) \{.*?\n  \}',
        dotAll: true,
      ).firstMatch(source);
      expect(match, isNotNull);
      expect(match!.group(0)!.contains('CmsImage(imageUrl: service.imageUrl)'), isTrue);
    });

    test('a service card without a CMS image renders a compact row and never a giant image block', () {
      final match = RegExp(
        r'Widget _compactLayout\(BuildContext context\) \{.*?\n  \}',
        dotAll: true,
      ).firstMatch(source);
      expect(match, isNotNull);
      final compactSource = match!.group(0)!;

      expect(compactSource.contains('CmsImage(imageUrl: null)'), isTrue);
      expect(compactSource.contains('width: 72'), isTrue);
      expect(compactSource.contains('height: 72'), isTrue);
      expect(compactSource.contains('height: 140'), isFalse);
    });

    test('the section is titled Supported Vehicles, not Vehicle Assistance', () {
      expect(source.contains("'Supported Vehicles'"), isTrue);
      expect(source.contains('Vehicle Assistance'), isFalse);
    });

    test('vehicle types are grouped by the real backend categories, not one flat list', () {
      expect(source.contains('twoWheelers'), isTrue);
      expect(source.contains('fourWheelers'), isTrue);
      expect(source.contains('heavyVehicles'), isTrue);
      expect(source.contains("ApiService.fetchVehicleTypesByCategory('2_wheeler')"), isTrue);
      expect(source.contains("ApiService.fetchVehicleTypesByCategory('4_wheeler')"), isTrue);
      expect(source.contains("ApiService.fetchVehicleTypesByCategory('heavy_vehicle')"), isTrue);
    });

    test('vehicle group labels match the backend\'s own authoritative category labels', () {
      expect(source.contains("label: '2-Wheeler'"), isTrue);
      expect(source.contains("label: '4-Wheeler'"), isTrue);
      expect(source.contains("label: 'Heavy Vehicle'"), isTrue);
    });

    test('vehicle names do not initiate booking, they are purely informational', () {
      final match = RegExp(
        r'class _VehicleName extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(match, isNotNull);
      final nameSource = match!.group(0)!;
      expect(nameSource.contains('onTap'), isFalse);
      expect(nameSource.contains('GestureDetector'), isFalse);
      expect(nameSource.contains('Navigator'), isFalse);
    });

    test('the old chip/button/pill styling around each vehicle name is removed', () {
      expect(source.contains('class _VehicleChip'), isFalse);
      final match = RegExp(
        r'class _VehicleName extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(match, isNotNull);
      final nameSource = match!.group(0)!;

      expect(nameSource.contains('Container('), isFalse);
      expect(nameSource.contains('BoxDecoration'), isFalse);
      expect(nameSource.contains('border:'), isFalse);
      expect(nameSource.contains('borderRadius'), isFalse);
    });

    test('vehicle names within a group wrap in a responsive layout for long labels', () {
      final match = RegExp(
        r'class _VehicleGroup extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(match, isNotNull);
      expect(match!.group(0)!.contains('Wrap('), isTrue);
    });

    test('Coverage still reads from the real CMS coverage_areas field', () {
      expect(source.contains("content?['coverage_areas'] as List<dynamic>?"), isTrue);
      expect(source.contains("'Areas We Cover'"), isTrue);
    });

    test('the bottom CTA uses the existing auth-gate sheet, not a direct booking route', () {
      final match = RegExp(
        r'class _BottomCta extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(match, isNotNull);
      final ctaSource = match!.group(0)!;

      expect(ctaSource.contains("'Request Towing'"), isTrue);
      expect(ctaSource.contains('showAuthGateSheet(context)'), isTrue);
      expect(ctaSource.contains("'/book-now'"), isFalse);
      expect(ctaSource.contains('Book Now'), isFalse);
    });

    test('no /book-now guest navigation exists anywhere on this screen', () {
      expect(source.contains("'/book-now'"), isFalse);
    });

    test('no permanent Login or Create Account button is duplicated in the page body', () {
      expect(source.contains("'Login'"), isFalse);
      expect(source.contains("'Create Account'"), isFalse);
    });

    test('the header matches the approved Public Home compact 52px treatment', () {
      final match = RegExp(
        r'class _TopBar extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(match, isNotNull);
      final topBarSource = match!.group(0)!;

      expect(topBarSource.contains('height: 52'), isTrue);
      expect(topBarSource.contains('Icons.person'), isFalse);
      expect(topBarSource.contains('Icons.call'), isFalse);
      expect(topBarSource.contains('CircleAvatar'), isFalse);
    });

    test('skeleton service placeholders match the compact card height, not the old oversized block', () {
      final match = RegExp(
        r'class _ServicesSkeleton extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(match, isNotNull);
      final skeletonSource = match!.group(0)!;

      expect(skeletonSource.contains('height: 72'), isTrue);
      expect(skeletonSource.contains('height: 220'), isFalse);
    });

    test('skeleton no longer simulates pill/chip buttons for vehicle names', () {
      final match = RegExp(
        r'class _ServicesSkeleton extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(match, isNotNull);
      final skeletonSource = match!.group(0)!;

      expect(skeletonSource.contains('Wrap('), isFalse);
      final lineCount = RegExp(r'SkeletonBox\(width: \d+, height: 1[24]\)').allMatches(skeletonSource).length;
      expect(lineCount, greaterThanOrEqualTo(3));
    });

    test('the bottom CTA card is compact (approximately 180-190px) and still uses the CMS services image', () {
      final match = RegExp(
        r'class _BottomCta extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(match, isNotNull);
      final ctaSource = match!.group(0)!;

      expect(ctaSource.contains('height: 186'), isTrue);
      expect(ctaSource.contains('CmsImage(imageUrl: imageUrl'), isTrue);
      expect(ctaSource.contains('this.imageUrl'), isTrue);
    });
  });

  group('guest drawer wording consistency', () {
    late String drawerSource;

    setUpAll(() {
      drawerSource = File('lib/widgets/tm_drawer.dart').readAsStringSync();
    });

    test('guest Create Account label still routes to the existing /signup route', () {
      expect(drawerSource.contains("label: 'Create Account'"), isTrue);
      expect(drawerSource.contains("label: 'Sign up'"), isFalse);
      expect(
        RegExp(r"label: 'Create Account',\s*route: '/signup'").hasMatch(drawerSource),
        isTrue,
      );
    });

    test('guest drawer still offers exactly Home, Services, About, Login, Create Account', () {
      final guestBranchMatch = RegExp(
        r'\] else \.\.\.\[(.*?)\n            \],',
        dotAll: true,
      ).firstMatch(drawerSource);
      expect(guestBranchMatch, isNotNull);
      final guestSource = guestBranchMatch!.group(1)!;

      expect(guestSource.contains("label: 'Home'"), isTrue);
      expect(guestSource.contains("label: 'Services'"), isTrue);
      expect(guestSource.contains("label: 'About'"), isTrue);
      expect(guestSource.contains("label: 'Login'"), isTrue);
      expect(guestSource.contains("label: 'Create Account'"), isTrue);
      expect(guestSource.contains('Profile'), isFalse);
      expect(guestSource.contains('Activity'), isFalse);
      expect(guestSource.contains('Vehicles'), isFalse);
      expect(guestSource.contains('Icons.call'), isFalse);
    });
  });
}
