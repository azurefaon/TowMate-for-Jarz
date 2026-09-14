import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  late String source;

  setUpAll(() {
    source = File('lib/screens/customer/services_screen.dart').readAsStringSync();
  });

  group('services screen guest UX invariants', () {
    test('has no CircularProgressIndicator on the public loading path', () {
      expect(source.contains('CircularProgressIndicator'), isFalse);
    });

    test('has no persistent bottom navigation', () {
      expect(source.contains('BottomNavigationBar'), isFalse);
      expect(source.contains('NavigationBar('), isFalse);
    });

    test('renders a skeleton for the service list while CMS content is loading', () {
      expect(source.contains('class _ServicesSkeleton'), isTrue);
      expect(
        RegExp(r'if \(_loading\)\s*\n\s*const _ServicesSkeleton\(\)').hasMatch(source),
        isTrue,
      );
    });

    test('a guest tapping a service card is routed to login, not an open booking flow', () {
      final onBookTapMatch = RegExp(
        r'void _onBookTap\(\) \{.*?\n  \}',
        dotAll: true,
      ).firstMatch(source);
      expect(onBookTapMatch, isNotNull);
      expect(onBookTapMatch!.group(0)!.contains("Navigator.pushNamed(context, '/login')"), isTrue);
    });

    test('the towing services page is not labeled with fake prices', () {
      expect(RegExp(r'\$\d').hasMatch(source), isFalse);
    });
  });
}
