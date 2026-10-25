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

  group('customer Live Tracking route', () {
    test('the /live-tracking route only builds the map while the switch is on', () {
      final routeIndex = source.indexOf("settings.name == '/live-tracking'");
      expect(routeIndex, greaterThan(-1));
      final block = source.substring(
        routeIndex,
        source.indexOf("settings.name == '/booking-success'"),
      );
      expect(block, contains('CustomerLiveTracking.enabled'));
      expect(block, contains(': BookingDetailScreen(bookingCode: code)'));
    });
  });
}
