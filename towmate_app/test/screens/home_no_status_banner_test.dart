import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/route_observer.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/customer/home_screen.dart';

http.Response _json(Object body) =>
    http.Response(jsonEncode(body), 200, headers: {'content-type': 'application/json'});

Map<String, dynamic> _booking(String status) => {
      'id': 1,
      'booking_code': 'TM-0001',
      'status': status,
      'pickup_address': '123 Main St',
      'dropoff_address': '456 Side St',
      'distance_km': 5.2,
      'computed_total': 850.0,
    };

/// Real booking status comes from the API; push is delivered by Android now,
/// so Home must never raise its own top-of-screen banner for status changes.
Future<void> _runScenario(
  WidgetTester tester,
  List<String> statuses,
  Map<String, String> expectedLabels, {
  ThemeData? theme,
  Map<String, dynamic>? Function(int poll)? quotation,
}) async {
  SharedPreferences.setMockInitialValues({'auth_token': 't', 'user_role': 'Customer', 'user_name': 'Faon'});
  tester.view.physicalSize = const Size(390, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  var poll = 0;
  final client = MockClient((request) async {
    final path = request.url.path;
    if (path.endsWith('/v1/bookings/current')) {
      return _json({'data': _booking(statuses[poll.clamp(0, statuses.length - 1)])});
    }
    if (path.endsWith('/v1/quotations/pending')) return _json({'data': quotation?.call(poll)});
    if (path.endsWith('/v1/customer/content')) return _json({'announcement': null, 'services': []});
    if (path.contains('/vehicle-types/by-category/')) return _json({'vehicleTypes': []});
    if (path.endsWith('/v1/notifications')) return _json({'success': true, 'unread_count': 0, 'data': []});
    return _json({});
  });

  await http.runWithClient(() async {
    await tester.pumpWidget(MaterialApp(
      theme: theme,
      navigatorObservers: [appRouteObserver],
      home: const HomeScreen(),
    ));
    for (var i = 0; i < 10; i++) {
      await tester.pump(const Duration(milliseconds: 50));
    }
    expect(find.text(expectedLabels[statuses.first]!), findsWidgets);

    for (var i = 1; i < statuses.length; i++) {
      poll = i;
      await tester.pump(const Duration(seconds: 31)); // Home's 30s poll
      for (var j = 0; j < 10; j++) {
        await tester.pump(const Duration(milliseconds: 50));
      }
      // Real status is reflected by Current Booking...
      expect(find.text(expectedLabels[statuses[i]]!), findsWidgets, reason: statuses[i]);
      // ...and no custom banner/snackbar announces it.
      expect(find.byType(MaterialBanner), findsNothing, reason: 'banner for ${statuses[i]}');
      expect(find.byType(SnackBar), findsNothing);
      expect(find.textContaining('Booking status updated'), findsNothing);
    }

    await tester.pumpWidget(const SizedBox());
  }, () => client);
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  const labels = {
    'accepted': 'Accepted',
    'on_the_way': 'On the way',
    'arrived_pickup': 'Arrived at pickup',
    'in_progress': 'Towing in progress',
    'on_job': 'On the way to drop-off',
    'arrived_dropoff': 'Arrived at destination',
  };

  testWidgets('1/3/4: TL status progression updates Current Booking without a top banner', (tester) async {
    await _runScenario(
      tester,
      ['accepted', 'on_the_way', 'arrived_pickup', 'in_progress', 'on_job', 'arrived_dropoff'],
      labels,
    );
  });

  testWidgets('2: a demo-style jump straight to arrived_pickup raises no banner', (tester) async {
    await _runScenario(tester, ['on_the_way', 'arrived_pickup'], labels);
  });

  testWidgets('5: stays stable without a banner in dark mode', (tester) async {
    await _runScenario(tester, ['on_the_way', 'arrived_pickup'], labels, theme: AppTheme.dark);
  });

  testWidgets('5: stays stable without a banner in light mode', (tester) async {
    await _runScenario(tester, ['on_the_way', 'arrived_pickup'], labels, theme: AppTheme.light);
  });
}
