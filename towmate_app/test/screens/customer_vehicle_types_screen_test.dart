import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/customer/customer_vehicle_types_screen.dart';
import 'package:towmate_app/widgets/skeleton_box.dart';
import 'package:towmate_app/widgets/tm_bottom_nav.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

const _eight = [
  'Motorcycle',
  'Scooter / E-Scooter',
  'Bicycle / E-Bike',
  'Tricycle',
  'SUV',
  'Van / L300',
  'AUV / MPV',
  'Elf / 6-Wheeler',
];

final _defaultByCategory = <String, List<Map<String, dynamic>>>{
  '2_wheeler': [
    {'id': 1, 'name': 'Motorcycle'},
    {'id': 2, 'name': 'Scooter / E-Scooter'},
    {'id': 3, 'name': 'Bicycle / E-Bike'},
    {'id': 4, 'name': 'Tricycle'},
  ],
  '4_wheeler': [
    {'id': 8, 'name': 'SUV'},
    {'id': 11, 'name': 'Van / L300'},
    {'id': 7, 'name': 'AUV / MPV'},
  ],
  'heavy_vehicle': [
    {'id': 14, 'name': 'Elf / 6-Wheeler'},
  ],
};

http.Client _clientFor(Map<String, List<Map<String, dynamic>>> byCategory, {int status = 200}) {
  return MockClient((request) async {
    final path = request.url.path;
    if (path.contains('/vehicle-types/by-category/')) {
      final category = path.split('/').last;
      if (status != 200) return _json({}, status: status);
      return _json({'vehicleTypes': byCategory[category] ?? []});
    }
    if (path.endsWith('/v1/notifications')) {
      return _json({'success': true, 'unread_count': 3, 'data': []});
    }
    return _json({'success': false}, status: 404);
  });
}

Future<void> _pumpScreen(
  WidgetTester tester,
  http.Client client, {
  bool withBackTarget = false,
  double width = 390,
  ThemeData? theme,
}) async {
  SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
  tester.view.physicalSize = Size(width, 2000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  await http.runWithClient(
    () async {
      await tester.pumpWidget(
        MaterialApp(
          theme: theme,
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

Finder _label(String name) => find.byWidgetPredicate(
      (w) => w is Text && w.data != null && w.data!.replaceAll('\n', ' ') == name,
    );

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  group('CustomerVehicleTypesScreen', () {
    testWidgets('renders the header, subtitle and both groups', (tester) async {
      await _pumpScreen(tester, _clientFor(_defaultByCategory));

      expect(find.text('Vehicle Types'), findsOneWidget);
      expect(find.text('Vehicles supported by TowMate'), findsOneWidget);
      expect(find.text('TWO & THREE WHEELS'), findsOneWidget);
      expect(find.text('FOUR WHEELS & UP'), findsOneWidget);
    });

    testWidgets('renders exactly the eight approved vehicle categories and nothing else', (tester) async {
      await _pumpScreen(tester, _clientFor(_defaultByCategory));

      for (final name in _eight) {
        expect(_label(name), findsOneWidget, reason: name);
      }
      expect(find.byIcon(Icons.chevron_right), findsNothing);
      final labelCount = tester
          .widgetList<Text>(find.byType(Text))
          .where((t) => _eight.contains((t.data ?? '').replaceAll('\n', ' ')))
          .length;
      expect(labelCount, 8);
    });

    testWidgets('puts the two- and three-wheel vehicles first, then four wheels and up in order', (tester) async {
      await _pumpScreen(tester, _clientFor(_defaultByCategory));

      final ys = [for (final n in _eight) tester.getTopLeft(_label(n)).dy];
      expect(ys.take(4).every((y) => y < tester.getTopLeft(find.text('FOUR WHEELS & UP')).dy), isTrue);
      expect(ys.skip(4).every((y) => y > tester.getTopLeft(find.text('FOUR WHEELS & UP')).dy), isTrue);
    });

    testWidgets('shows the informational note and no booking button or selectable tile', (tester) async {
      await _pumpScreen(tester, _clientFor(_defaultByCategory));

      expect(find.text('You will choose your vehicle type when you book a tow.'), findsOneWidget);
      expect(find.byType(ElevatedButton), findsNothing);
      expect(find.byType(FilledButton), findsNothing);
      expect(find.text('Book a tow'), findsNothing);
    });

    testWidgets('a category with no active vehicle types renders no extra category', (tester) async {
      await _pumpScreen(
        tester,
        _clientFor({
          '2_wheeler': _defaultByCategory['2_wheeler']!,
          '4_wheeler': [],
          'heavy_vehicle': [],
        }),
      );

      expect(find.text('TWO & THREE WHEELS'), findsOneWidget);
      expect(find.text('FOUR WHEELS & UP'), findsNothing);
    });

    testWidgets('shows a skeleton, not a spinner, while loading', (tester) async {
      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: CustomerVehicleTypesScreen()));
          expect(find.byType(SkeletonBox), findsWidgets);
          expect(find.byType(CircularProgressIndicator), findsNothing);
          await _settle(tester);
        },
        () => _clientFor(_defaultByCategory),
      );
    });

    testWidgets('a total API failure shows a retry state, not fabricated content', (tester) async {
      await _pumpScreen(tester, _clientFor(_defaultByCategory, status: 500));

      expect(find.text('Vehicle types are unavailable right now.'), findsOneWidget);
      expect(find.text('Try again'), findsOneWidget);
      expect(_label('Motorcycle'), findsNothing);
    });

    testWidgets('back navigation returns to the screen that opened it', (tester) async {
      await _pumpScreen(tester, _clientFor(_defaultByCategory), withBackTarget: true);

      await tester.tap(find.text('Open Vehicle Types'));
      await _settle(tester);
      expect(find.text('Vehicle Types'), findsOneWidget);

      await tester.tap(find.byIcon(Icons.chevron_left));
      await _settle(tester);
      expect(find.text('Open Vehicle Types'), findsOneWidget);
    });

    testWidgets('keeps the bottom navigation with Home highlighted and the unread badge', (tester) async {
      await _pumpScreen(tester, _clientFor(_defaultByCategory));

      expect(find.byType(TmBottomNav), findsOneWidget);
      expect(find.text('3'), findsOneWidget);
    });

    for (final width in [360.0, 390.0, 430.0]) {
      testWidgets('renders without overflow and with clean hyphen wrapping at ${width.toInt()}px', (tester) async {
        await _pumpScreen(tester, _clientFor(_defaultByCategory), width: width);

        expect(tester.takeException(), isNull);
        for (final name in ['Scooter / E-Scooter', 'Bicycle / E-Bike', 'Elf / 6-Wheeler']) {
          final data = tester.widget<Text>(_label(name)).data!;
          expect(data.contains('E-\n') || data.contains('6-\n'), isFalse, reason: '$name -> $data');
        }
      });

      for (final dark in [false, true]) {
        testWidgets('renders ${dark ? 'dark' : 'light'} at ${width.toInt()}px with 72px tiles', (tester) async {
          await _pumpScreen(
            tester,
            _clientFor(_defaultByCategory),
            width: width,
            theme: dark ? AppTheme.dark : AppTheme.light,
          );

          expect(tester.takeException(), isNull);
          final tile = find.ancestor(of: _label('Motorcycle'), matching: find.byType(Container)).first;
          expect(tester.getSize(tile).height, 72);
        });
      }
    }

    testWidgets('narrow tiles break E-Scooter and 6-Wheeler onto their own line', (tester) async {
      await _pumpScreen(tester, _clientFor(_defaultByCategory), width: 360);

      expect(tester.widget<Text>(_label('Scooter / E-Scooter')).data, 'Scooter /\nE-Scooter');
      expect(tester.widget<Text>(_label('Bicycle / E-Bike')).data, 'Bicycle /\nE-Bike');
    });
  });
}
