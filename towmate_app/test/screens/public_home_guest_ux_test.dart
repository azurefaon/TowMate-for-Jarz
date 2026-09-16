import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  late String source;

  setUpAll(() {
    source = File('lib/screens/customer/public_home_screen.dart').readAsStringSync();
  });

  group('public home guest UX invariants', () {
    test('has no CircularProgressIndicator on the public loading path', () {
      expect(source.contains('CircularProgressIndicator'), isFalse);
    });

    test('has no persistent bottom navigation', () {
      expect(source.contains('BottomNavigationBar'), isFalse);
      expect(source.contains('NavigationBar('), isFalse);
    });

    test('the top bar shows only a menu control and the wordmark, no profile or call icon', () {
      final topBarMatch = RegExp(
        r'class _TopBar extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(topBarMatch, isNotNull);
      final topBarSource = topBarMatch!.group(0)!;

      expect(topBarSource.contains('Icons.menu'), isTrue);
      expect(topBarSource.contains('Icons.menu_rounded'), isFalse);
      expect(topBarSource.contains('Icons.menu_outlined'), isFalse);
      expect(topBarSource.contains('Icons.person'), isFalse);
      expect(topBarSource.contains('Icons.account_circle'), isFalse);
      expect(topBarSource.contains('Icons.call'), isFalse);
      expect(topBarSource.contains('Icons.phone'), isFalse);
      expect(topBarSource.contains('CircleAvatar'), isFalse);
    });

    test('renders a skeleton while CMS content is loading, real content once loaded', () {
      expect(source.contains('bool _loading = true;'), isTrue);
      expect(
        RegExp(r'_loading\s*\?\s*const SingleChildScrollView\(child:\s*_HomeSkeleton\(\)\)').hasMatch(source),
        isTrue,
      );
    });

    test('the Emergency CTA opens the auth-gate sheet rather than a direct booking route', () {
      final emergencyMatch = RegExp(
        r'class _EmergencySection extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(emergencyMatch, isNotNull);
      final emergencySource = emergencyMatch!.group(0)!;

      expect(emergencySource.contains('showAuthGateSheet(context)'), isTrue);
      expect(emergencySource.contains("'/book-now'"), isFalse);
    });

    test('no public CTA on this screen routes directly to a booking screen', () {
      expect(source.contains("'/book-now'"), isFalse);
    });

    test('Get Started opens the auth-gate sheet, giving guests a Login/Create Account choice', () {
      expect(source.contains('onGetStarted: () => showAuthGateSheet(context)'), isTrue);
    });

    test('Get Started does not route directly to /signup', () {
      final heroMatch = RegExp(
        r'class _HeroSection extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(heroMatch, isNotNull);
      expect(heroMatch!.group(0)!.contains("'/signup'"), isFalse);
    });

    test('no standalone guest account CTA section exists in the page body', () {
      expect(source.contains('class _GuestAccountSection'), isFalse);
      expect(source.contains('Ready to request towing?'), isFalse);
      expect(source.contains("'Create Account'"), isFalse);
    });

    test('the towing services section is not labeled with fake prices', () {
      expect(RegExp(r'\$\d').hasMatch(source), isFalse);
    });

    test('the Emergency CTA is labeled Request Towing, not Get Help', () {
      expect(source.contains("'Request Towing'"), isTrue);
      expect(source.contains("'Get Help'"), isFalse);
    });

    test('the Home body renders no Login or Create Account button text', () {
      expect(source.contains("'Login'"), isFalse);
      expect(source.contains("'Create Account'"), isFalse);
    });

    test('the guest account CTA skeleton block was removed', () {
      final skeletonMatch = RegExp(
        r'class _HomeSkeleton extends StatelessWidget \{.*?\n\}',
        dotAll: true,
      ).firstMatch(source);
      expect(skeletonMatch, isNotNull);
      expect(skeletonMatch!.group(0)!.contains('height: 154'), isFalse);
    });

    test('Explore Services still routes to /services for public browsing', () {
      expect(
        RegExp(r"onExplore:\s*\(\)\s*=>\s*Navigator\.pushNamed\(context, '/services'\)").hasMatch(source),
        isTrue,
      );
    });

    test('the guest drawer still offers Login and Create Account, routing correctly', () {
      final drawerSource = File('lib/widgets/tm_drawer.dart').readAsStringSync();
      expect(drawerSource.contains("label: 'Login'"), isTrue);
      expect(drawerSource.contains("label: 'Create Account'"), isTrue);
      expect(drawerSource.contains("route: '/login'"), isTrue);
      expect(drawerSource.contains("route: '/signup'"), isTrue);
    });
  });
}
