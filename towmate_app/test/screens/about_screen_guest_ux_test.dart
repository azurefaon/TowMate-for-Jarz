import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  late String source;

  setUpAll(() {
    source = File('lib/screens/customer/about_screen.dart').readAsStringSync();
  });

  group('about screen guest UX invariants', () {
    test('has no CircularProgressIndicator', () {
      expect(source.contains('CircularProgressIndicator'), isFalse);
    });

    test('has no persistent bottom navigation', () {
      expect(source.contains('BottomNavigationBar'), isFalse);
      expect(source.contains('NavigationBar('), isFalse);
    });

    test('renders real fallback copy immediately instead of a loading gap, by design', () {
      expect(source.contains('_fallback'), isTrue);
    });
  });
}
