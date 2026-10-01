import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/customer/towing_guide_screen.dart';
import 'package:towmate_app/widgets/tm_bottom_nav.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

final _truckTypes = [
  {'id': 15, 'name': 'Light Duty', 'class': 'light', 'base_rate': 1, 'per_km_rate': 1},
  {'id': 16, 'name': 'Medium Duty', 'class': 'medium', 'base_rate': 1, 'per_km_rate': 1},
  {'id': 17, 'name': 'Heavy Duty', 'class': 'heavy', 'base_rate': 1, 'per_km_rate': 1},
  {'id': 34, 'name': 'Unclassified', 'class': null, 'base_rate': 1, 'per_km_rate': 1},
];

final _vehicleTypes = [
  {'id': 1, 'name': 'Motorcycle', 'category': '2_wheeler', 'required_truck_type_id': 15},
  {'id': 2, 'name': 'Scooter / E-Scooter', 'category': '2_wheeler', 'required_truck_type_id': 15},
  {'id': 8, 'name': 'SUV', 'category': '4_wheeler', 'required_truck_type_id': 16},
  {'id': 11, 'name': 'Van / L300', 'category': '4_wheeler', 'required_truck_type_id': 17},
  {'id': 14, 'name': 'Elf / 6-Wheeler', 'category': 'heavy_vehicle', 'required_truck_type_id': 17},
  {'id': 5, 'name': 'Sedan', 'category': 'cars_suvs', 'required_truck_type_id': 15},
  {'id': 40, 'name': 'Unmapped Thing', 'category': '2_wheeler', 'required_truck_type_id': 34},
  {'id': 41, 'name': 'No Truck', 'category': '2_wheeler', 'required_truck_type_id': null},
];

http.Client _client({bool fail = false}) => MockClient((request) async {
      final path = request.url.path;
      if (fail && (path.endsWith('/v1/truck-types') || path.endsWith('/v1/vehicle-types'))) {
        return _json({}, status: 500);
      }
      if (path.endsWith('/v1/truck-types')) return _json(_truckTypes);
      if (path.endsWith('/v1/vehicle-types')) return _json(_vehicleTypes);
      if (path.endsWith('/v1/notifications')) {
        return _json({'success': true, 'unread_count': 4, 'data': []});
      }
      return _json({}, status: 404);
    });

Future<void> _pump(WidgetTester tester, {bool fail = false, double width = 390, ThemeData? theme}) async {
  SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
  tester.view.physicalSize = Size(width, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  await http.runWithClient(
    () async {
      await tester.pumpWidget(MaterialApp(theme: theme, home: const TowingGuideScreen()));
      await _settle(tester);
    },
    () => _client(fail: fail),
  );
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  group('TowingGuideScreen', () {
    testWidgets('renders the header, subtitle and the three approved categories', (tester) async {
      await _pump(tester);

      expect(find.text('Towing Guide'), findsOneWidget);
      expect(find.text('Find the right towing option for your vehicle.'), findsOneWidget);
      for (final title in ['Light Duty', 'Medium Duty', 'Heavy Duty']) {
        expect(find.text(title), findsOneWidget);
      }
      expect(find.text('For smaller passenger vehicles that typically need standard towing support.'), findsOneWidget);
      expect(find.text('For larger vehicles that may require additional towing capacity.'), findsOneWidget);
      expect(
        find.text('For larger commercial or heavy vehicles that require specialized towing equipment.'),
        findsOneWidget,
      );
    });

    testWidgets('populates Common Vehicles only from the real vehicle-to-truck-class mapping', (tester) async {
      await _pump(tester);

      expect(find.text('COMMON VEHICLES'), findsNWidgets(3));
      final light = find.text('Motorcycle');
      final medium = find.text('SUV');
      final heavyVan = find.text('Van / L300');
      expect(light, findsOneWidget);
      expect(medium, findsOneWidget);
      expect(heavyVan, findsOneWidget);
      expect(find.text('Elf / 6-Wheeler'), findsOneWidget);

      final lightY = tester.getTopLeft(find.text('Light Duty')).dy;
      final mediumY = tester.getTopLeft(find.text('Medium Duty')).dy;
      final heavyY = tester.getTopLeft(find.text('Heavy Duty')).dy;
      expect(tester.getTopLeft(light).dy, inInclusiveRange(lightY, mediumY));
      expect(tester.getTopLeft(medium).dy, inInclusiveRange(mediumY, heavyY));
      expect(tester.getTopLeft(heavyVan).dy, greaterThan(heavyY));
    });

    testWidgets('never shows placeholder, unmapped, unclassified or out-of-catalogue vehicles', (tester) async {
      await _pump(tester);

      expect(find.text('[Vehicle type]'), findsNothing);
      expect(find.text('Sedan'), findsNothing);
      expect(find.text('Unmapped Thing'), findsNothing);
      expect(find.text('No Truck'), findsNothing);
    });

    testWidgets('invents no weight limits or capacities', (tester) async {
      await _pump(tester);

      expect(find.textContaining(RegExp(r'\d+\s?(kg|ton|t\b)', caseSensitive: false)), findsNothing);
    });

    testWidgets('when the mapping cannot be loaded the guide still renders without chips', (tester) async {
      await _pump(tester, fail: true);

      expect(find.text('Light Duty'), findsOneWidget);
      expect(find.text('COMMON VEHICLES'), findsNothing);
      expect(find.text('Still not sure?'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('shows the Still not sure section and no redundant booking CTA', (tester) async {
      await _pump(tester);

      expect(find.text('Still not sure?'), findsOneWidget);
      expect(
        find.text('Choose your vehicle when creating a towing request and TowMate will help determine the appropriate towing option.'),
        findsOneWidget,
      );
      expect(find.byType(ElevatedButton), findsNothing);
      expect(find.byType(FilledButton), findsNothing);
      expect(find.text('Book a tow'), findsNothing);
    });

    testWidgets('has working back navigation and the bottom navigation with the unread badge', (tester) async {
      SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
      tester.view.physicalSize = const Size(390, 2400);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);
      await http.runWithClient(
        () async {
          await tester.pumpWidget(MaterialApp(
            home: Builder(
              builder: (context) => Scaffold(
                body: ElevatedButton(
                  onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const TowingGuideScreen())),
                  child: const Text('open'),
                ),
              ),
            ),
          ));
          await tester.tap(find.text('open'));
          await _settle(tester);

          expect(find.byType(TmBottomNav), findsOneWidget);
          expect(find.text('4'), findsOneWidget);

          await tester.tap(find.byIcon(Icons.chevron_left));
          await _settle(tester);
          expect(find.text('open'), findsOneWidget);
        },
        () => _client(),
      );
    });

    for (final width in [360.0, 390.0, 430.0]) {
      for (final dark in [false, true]) {
        testWidgets('renders ${dark ? 'dark' : 'light'} at ${width.toInt()}px without overflow', (tester) async {
          await _pump(tester, width: width, theme: dark ? AppTheme.dark : AppTheme.light);

          expect(tester.takeException(), isNull);
        });
      }
    }
  });
}
