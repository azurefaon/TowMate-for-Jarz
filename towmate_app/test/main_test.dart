import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  late String source;

  setUpAll(() {
    source = File('lib/main.dart').readAsStringSync();
  });

  group('TL presence session start invariants', () {
    test('a Team Leader session starts the presence controller before routing', () {
      final roleCheckIndex = source.indexOf("role == 'Team Leader'");
      final startIndex = source.indexOf('TlPresenceController.start()');
      expect(roleCheckIndex, greaterThan(-1));
      expect(startIndex, greaterThan(roleCheckIndex));
    });

    test('presence start happens before navigating away from the auth gate', () {
      final startIndex = source.indexOf('TlPresenceController.start()');
      final navigateIndex = source.indexOf(
        "mustChange ? '/tl-force-password' : '/tl-home'",
      );
      expect(startIndex, greaterThan(-1));
      expect(navigateIndex, greaterThan(startIndex));
    });
  });
}
