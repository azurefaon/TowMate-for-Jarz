import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:towmate_app/screens/customer/customer_vehicle_types_screen.dart';
import 'package:towmate_app/widgets/skeleton_box.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

http.Client _clientFor(Map<String, List<Map<String, dynamic>>> byCategory, {int status = 200}) {
  return MockClient((request) async {
    final path = request.url.path;
    if (path.contains('/vehicle-types/by-category/')) {
      final category = path.split('/').last;
      if (status != 200) return _json({}, status: status);
      return _json({'vehicleTypes': byCategory[category] ?? []});
    }
    return _json({'success': false}, status: 404);
  });
}

Future<void> _pumpScreen(
  WidgetTester tester,
  http.Client client, {
  bool withBackTarget = false,
}) async {
  await http.runWithClient(
    () async {
      await tester.pumpWidget(
        MaterialApp(
          home: withBackTarget
              ? Builder(
                  builder: (context) => Scaffold(
                    body: Center(
                      child: ElevatedButton(
                        onPressed: () => Navigator.push(
                          context,
                          MaterialPageRoute(builder: (_) => const CustomerVehicleTypesScreen()),
                        ),
                        child: const Text('Open Vehicle Types'),
                      ),
                    ),
                  ),
                )
              : const CustomerVehicleTypesScreen(),
        ),
      );
      await _settle(tester);
    },
    () => client,
  );
}

void main() {
  group('CustomerVehicleTypesScreen', () {
    testWidgets('renders all real mocked vehicle records grouped by category', (tester) async {
      final originalSize = tester.view.physicalSize;
      final originalRatio = tester.view.devicePixelRatio;
      tester.view.physicalSize = const Size(400, 2200);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() {
        tester.view.physicalSize = originalSize;
        tester.view.devicePixelRatio = originalRatio;
      });

      final client = _clientFor({
        '2_wheeler': [
          {'id': 1, 'name': 'Motorcycle'},
          {'id': 2, 'name': 'Scooter / E-Scooter'},
          {'id': 3, 'name': 'Tricycle'},
        ],
        '4_wheeler': [
          {'id': 4, 'name': 'Sedan'},
          {'id': 5, 'name': 'SUV'},
          {'id': 6, 'name': 'Pickup Truck'},
          {'id': 7, 'name': 'Van / L300'},
        ],
        'heavy_vehicle': [
          {'id': 8, 'name': 'Minibus'},
          {'id': 9, 'name': '10-Wheeler Truck'},
        ],
      });

      await _pumpScreen(tester, client);

      expect(find.text('2-Wheeler'), findsOneWidget);
      expect(find.text('4-Wheeler'), findsOneWidget);
      expect(find.text('Heavy Vehicle'), findsOneWidget);

      expect(find.text('Motorcycle'), findsOneWidget);
      expect(find.text('Scooter / E-Scooter'), findsOneWidget);
      expect(find.text('Tricycle'), findsOneWidget);
      expect(find.text('Sedan'), findsOneWidget);
      expect(find.text('SUV'), findsOneWidget);
      expect(find.text('Pickup Truck'), findsOneWidget);
      expect(find.text('Van / L300'), findsOneWidget);
      expect(find.text('Minibus'), findsOneWidget);
      expect(find.text('10-Wheeler Truck'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a category with no active vehicle types renders no group heading for it', (tester) async {
      final client = _clientFor({
        '2_wheeler': [
          {'id': 1, 'name': 'Motorcycle'},
        ],
        '4_wheeler': [],
        'heavy_vehicle': [],
      });

      await _pumpScreen(tester, client);

      expect(find.text('2-Wheeler'), findsOneWidget);
      expect(find.text('4-Wheeler'), findsNothing);
      expect(find.text('Heavy Vehicle'), findsNothing);
      expect(find.text('Vehicle types are unavailable right now.'), findsNothing);
    });

    testWidgets('shows a skeleton, not a spinner, while loading', (tester) async {
      final client = MockClient((request) async {
        await Future<void>.delayed(const Duration(seconds: 2));
        return _json({'vehicleTypes': []});
      });

      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: CustomerVehicleTypesScreen()));
          for (var i = 0; i < 3; i++) {
            await tester.pump(const Duration(milliseconds: 50));
          }
        },
        () => client,
      );

      expect(find.byType(SkeletonBox), findsWidgets);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(tester.takeException(), isNull);

      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('a total API failure shows a retry state, not fabricated content', (tester) async {
      final client = _clientFor({}, status: 500);

      await _pumpScreen(tester, client);

      expect(find.text('Vehicle types are unavailable right now.'), findsOneWidget);
      expect(find.text('Try again'), findsOneWidget);
      expect(find.text('Motorcycle'), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('back navigation returns to the screen that opened it', (tester) async {
      final client = _clientFor({
        '2_wheeler': [
          {'id': 1, 'name': 'Motorcycle'},
        ],
      });

      await _pumpScreen(tester, client, withBackTarget: true);

      await tester.tap(find.text('Open Vehicle Types'));
      await _settle(tester);
      expect(find.text('Vehicle Types'), findsOneWidget);

      await tester.tap(find.byIcon(Icons.arrow_back_rounded));
      for (var i = 0; i < 20; i++) {
        await tester.pump(const Duration(milliseconds: 50));
      }

      expect(find.text('Open Vehicle Types'), findsOneWidget);
      expect(find.text('Vehicle Types'), findsNothing);
    });

    for (final width in [320.0, 340.0, 360.0, 375.0, 390.0, 412.0]) {
      testWidgets('renders without horizontal overflow at $width px width', (tester) async {
        final originalSize = tester.view.physicalSize;
        final originalRatio = tester.view.devicePixelRatio;
        tester.view.physicalSize = Size(width, 800);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(() {
          tester.view.physicalSize = originalSize;
          tester.view.devicePixelRatio = originalRatio;
        });

        final client = _clientFor({
          '2_wheeler': [
            {'id': 1, 'name': 'Scooter / E-Scooter With A Very Long Descriptive Name'},
          ],
          '4_wheeler': [
            {'id': 2, 'name': 'AUV / MPV'},
          ],
          'heavy_vehicle': [
            {'id': 3, 'name': 'Elf / 6-Wheeler'},
          ],
        });

        await _pumpScreen(tester, client);

        expect(tester.takeException(), isNull);
      });
    }
  });
}
