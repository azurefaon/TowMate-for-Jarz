import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/customer/book_now_screen.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

http.Client _client() {
  var autocompleteCalls = 0;
  return MockClient((request) async {
    final path = request.url.path;
    if (path.contains('truck-types')) {
      return _json([
        {
          'id': 1,
          'name': 'Light Duty',
          'truck_class': 'light',
          'base_rate': 1500.0,
          'per_km_rate': 60.0,
          'vehicle_types': [],
        },
      ]);
    }
    if (path.contains('availability')) {
      return _json({'book_now_enabled': true, 'ready_units_count': 3, 'ready_by_class': {}});
    }
    if (path.contains('autocomplete')) {
      autocompleteCalls++;
      final lat = autocompleteCalls == 1 ? 14.5832 : 14.6905;
      return _json({
        'suggestions': [
          {'label': 'Rizal Park, Manila', 'coordinates': [120.9822, lat]},
        ],
      });
    }
    return _json({}, status: 404);
  });
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pumpBookNow(WidgetTester tester, {Size size = const Size(390, 844)}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
  await http.runWithClient(() async {
    await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
    await _settle(tester);
  }, _client);
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  group('BookNowScreen Step 0', () {
    testWidgets('renders the hero, booking mode and location fields without Quick Search', (tester) async {
      await _pumpBookNow(tester);

      expect(find.text('Where to?'), findsOneWidget);
      expect(find.text('Book Your Towing Service'), findsOneWidget);
      expect(find.text('BOOKING MODE'), findsOneWidget);
      expect(find.text('Book Now'), findsOneWidget);
      expect(find.text('Get a unit as soon as possible'), findsOneWidget);
      expect(find.text('Schedule Later'), findsOneWidget);
      expect(find.text('Choose a date and time'), findsOneWidget);
      expect(find.text('Pickup location'), findsOneWidget);
      expect(find.text('Drop-off location'), findsOneWidget);
      expect(find.text('Search pickup location'), findsOneWidget);
      expect(find.text('Search drop-off location'), findsOneWidget);
      expect(find.text('QUICK SEARCH'), findsNothing);
      expect(find.text('Gas Station'), findsNothing);
      expect(find.text('Route'), findsOneWidget);
      expect(find.text('Select your locations to see the route.'), findsOneWidget);
      expect(find.text('Use current location'), findsOneWidget);
      expect(find.text('Continue'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('the Continue action uses the yellow primary-action treatment', (tester) async {
      await _pumpBookNow(tester);

      final button = tester.widget<ElevatedButton>(
        find.widgetWithText(ElevatedButton, 'Continue'),
      );
      final resolvedBg = button.style?.backgroundColor?.resolve(<WidgetState>{});
      final resolvedFg = button.style?.foregroundColor?.resolve(<WidgetState>{});

      expect(resolvedBg, TmColors.yellow);
      expect(resolvedFg, TmColors.black);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('switching to Schedule Later reveals date and time fields with no default time', (tester) async {
      await _pumpBookNow(tester);

      expect(find.text('Preferred Date'), findsNothing);

      await tester.tap(find.text('Schedule Later'));
      await _settle(tester);

      expect(find.text('Preferred Date'), findsOneWidget);
      expect(find.text('Preferred Time'), findsOneWidget);
      expect(find.text('Select date'), findsOneWidget);
      expect(find.text('Select time'), findsOneWidget);

      await tester.tap(find.text('Book Now'));
      await _settle(tester);
      expect(find.text('Preferred Date'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('the map disables pan, zoom, rotate, tilt and tap-to-select gestures', (tester) async {
      await _pumpBookNow(tester);

      final map = tester.widget<GoogleMap>(find.byType(GoogleMap));

      expect(map.scrollGesturesEnabled, isFalse);
      expect(map.zoomGesturesEnabled, isFalse);
      expect(map.rotateGesturesEnabled, isFalse);
      expect(map.tiltGesturesEnabled, isFalse);
      expect(map.zoomControlsEnabled, isFalse);
      expect(map.onTap, isNull);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('typing a pickup query overlays suggestions without shifting the Route section', (tester) async {
      await http.runWithClient(() async {
        await _pumpBookNow(tester);

        final routeTopBefore = tester.getTopLeft(find.text('Route')).dy;

        await tester.enterText(find.byType(TextField).first, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);

        expect(find.text('Rizal Park'), findsOneWidget);
        expect(find.text('Manila'), findsOneWidget);
        expect(find.byType(CompositedTransformFollower), findsAtLeastNWidgets(1));

        final routeTopAfter = tester.getTopLeft(find.text('Route')).dy;
        expect(routeTopAfter, routeTopBefore);
      }, _client);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('selecting a suggestion then tapping x clears the pickup field', (tester) async {
      await http.runWithClient(() async {
        await _pumpBookNow(tester);

        await tester.enterText(find.byType(TextField).first, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);

        await tester.tap(find.text('Rizal Park'));
        await _settle(tester);

        expect(find.text('Use current location'), findsNothing);
        expect(find.byIcon(Icons.close_rounded), findsOneWidget);

        await tester.tap(find.byIcon(Icons.close_rounded));
        await _settle(tester);

        expect(find.byIcon(Icons.close_rounded), findsNothing);
        expect(find.text('Use current location'), findsOneWidget);

        final field = tester.widget<TextField>(find.byType(TextField).first);
        expect(field.controller?.text, isEmpty);
      }, _client);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('the stepper shows a solid yellow current dot and neutral, readable upcoming dots', (tester) async {
      await _pumpBookNow(tester);

      expect(find.text('Location'), findsOneWidget);
      expect(find.text('Vehicle'), findsOneWidget);
      expect(find.text('Review'), findsOneWidget);

      final currentDot = tester.widget<AnimatedContainer>(
        find.ancestor(of: find.text('1'), matching: find.byType(AnimatedContainer)).first,
      );
      final currentDecoration = currentDot.decoration as BoxDecoration;
      expect(currentDecoration.color, TmColors.yellow);
      final currentNumber = tester.widget<Text>(find.text('1'));
      expect(currentNumber.style?.color, TmColors.black);

      final upcomingDot = tester.widget<AnimatedContainer>(
        find.ancestor(of: find.text('2'), matching: find.byType(AnimatedContainer)).first,
      );
      final upcomingDecoration = upcomingDot.decoration as BoxDecoration;
      expect(upcomingDecoration.color, isNot(Colors.transparent));
      expect(upcomingDecoration.border, isNotNull);
      final upcomingNumber = tester.widget<Text>(find.text('2'));
      expect(upcomingNumber.style?.color, const Color(0xFF6B6B6B));

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('Reset uses a neutral dark treatment instead of yellow', (tester) async {
      await http.runWithClient(() async {
        await _pumpBookNow(tester);

        await tester.enterText(find.byType(TextField).first, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('Rizal Park'));
        await _settle(tester);

        final reset = tester.widget<Text>(find.text('Reset'));
        expect(reset.style?.color, isNot(TmColors.yellow));
      }, _client);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('the disabled Continue button remains readable, not low-contrast', (tester) async {
      await _pumpBookNow(tester);

      final button = tester.widget<ElevatedButton>(
        find.widgetWithText(ElevatedButton, 'Continue'),
      );
      expect(button.onPressed, isNull);
      final disabledFg = button.style?.foregroundColor?.resolve(<WidgetState>{WidgetState.disabled});
      expect(disabledFg, isNotNull);
      expect(disabledFg, isNot(Colors.transparent));

      await tester.pumpWidget(const SizedBox());
    });

    for (final width in [320.0, 360.0, 390.0, 412.0]) {
      testWidgets('renders without overflow at ${width.toInt()}px width', (tester) async {
        await _pumpBookNow(tester, size: Size(width, 800));
        expect(tester.takeException(), isNull);
        await tester.pumpWidget(const SizedBox());
      });
    }
  });

  group('BookNowScreen Step 0 pickup/drop-off exclusivity', () {
    http.Client sameSuggestionClient() {
      return MockClient((request) async {
        final path = request.url.path;
        if (path.contains('truck-types')) return _json([]);
        if (path.contains('availability')) {
          return _json({'book_now_enabled': true, 'ready_units_count': 3, 'ready_by_class': {}});
        }
        if (path.contains('autocomplete')) {
          return _json({
            'suggestions': [
              {'label': 'Rizal Park, Manila', 'coordinates': [120.9822, 14.5832]},
            ],
          });
        }
        return _json({}, status: 404);
      });
    }

    testWidgets('excludes the selected pickup location from drop-off suggestions', (tester) async {
      await http.runWithClient(() async {
        await _pumpBookNow(tester);

        await tester.enterText(find.byType(TextField).first, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('Rizal Park'));
        await _settle(tester);

        await tester.enterText(find.byType(TextField).last, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);

        expect(find.text('Rizal Park'), findsNothing);
      }, sameSuggestionClient);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('excludes the selected drop-off location from pickup suggestions', (tester) async {
      await http.runWithClient(() async {
        await _pumpBookNow(tester);

        await tester.enterText(find.byType(TextField).last, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('Rizal Park'));
        await _settle(tester);

        await tester.enterText(find.byType(TextField).first, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);

        expect(find.text('Rizal Park'), findsNothing);
      }, sameSuggestionClient);

      await tester.pumpWidget(const SizedBox());
    });

    http.Client bypassClient() {
      return MockClient((request) async {
        final path = request.url.path;
        if (path.contains('truck-types')) return _json([]);
        if (path.contains('availability')) {
          return _json({'book_now_enabled': true, 'ready_units_count': 3, 'ready_by_class': {}});
        }
        if (path.contains('place-details')) {
          return _json({
            'label': request.url.queryParameters['place_id'] == 'place-a'
                ? 'SM Novaliches'
                : 'SM Novaliches Entrance 2',
            'coordinates': [121.0362, 14.7357],
          });
        }
        if (path.contains('autocomplete')) {
          final q = request.url.queryParameters['q'] ?? '';
          if (q.contains('Entrance')) {
            return _json({
              'suggestions': [
                {'label': 'SM Novaliches Entrance 2', 'place_id': 'place-b'},
              ],
            });
          }
          return _json({
            'suggestions': [
              {'label': 'SM Novaliches', 'place_id': 'place-a'},
            ],
          });
        }
        return _json({}, status: 404);
      });
    }

    testWidgets('blocks confirming a drop-off that resolves to the same coordinates as pickup under a different place id', (tester) async {
      await http.runWithClient(() async {
        await _pumpBookNow(tester);

        await tester.enterText(find.byType(TextField).first, 'SM Novalic');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('SM Novaliches'));
        await _settle(tester);

        await tester.enterText(find.byType(TextField).last, 'SM Novaliches Entrance');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('SM Novaliches Entrance 2'));
        await _settle(tester);

        expect(
          find.text('Pickup and drop-off locations must be different.'),
          findsOneWidget,
        );
        final dropoffField = tester.widget<TextField>(find.byType(TextField).last);
        expect(dropoffField.controller?.text, isNot('SM Novaliches Entrance 2'));
      }, bypassClient);

      await tester.pumpWidget(const SizedBox());
    });
  });
}
