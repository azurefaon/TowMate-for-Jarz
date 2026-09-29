import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/customer/book_now_screen.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(
    jsonEncode(body),
    status,
    headers: {'content-type': 'application/json'},
  );
}

http.Client _client(List<int> autocompleteCallCount) {
  return MockClient((request) async {
    final path = request.url.path;
    if (path.contains('vehicle-types')) {
      return _json([
        {
          'id': 1,
          'name': 'Sedan',
          'category': '4_wheeler',
          'description': 'Standard 4-door sedans and small cars.',
          'icon_path': null,
          'required_truck_type_id': 2,
        },
      ]);
    }
    if (path.contains('availability')) {
      return _json({
        'book_now_enabled': true,
        'ready_units_count': 1,
        'ready_by_class': {},
        'ready_truck_type_ids': [2],
      });
    }
    if (path.contains('autocomplete')) {
      autocompleteCallCount[0]++;
      final lat = autocompleteCallCount[0] == 1 ? 14.5832 : 14.6905;
      return _json({
        'suggestions': [
          {
            'label': autocompleteCallCount[0] == 1
                ? 'Rizal Park, Manila'
                : 'Fairview, Quezon City',
            'coordinates': [120.9822, lat],
          },
        ],
      });
    }
    if (path.contains('check-duplicate-route')) {
      return _json({'duplicate': false});
    }
    return _json({}, status: 404);
  });
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 12; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

String _textFieldValue(WidgetTester tester, Finder finder) {
  return tester.widget<TextField>(finder).controller!.text;
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  setUp(() {
    SharedPreferences.setMockInitialValues({
      'auth_token': 'test-token',
      'user_role': 'Customer',
    });
  });

  group('BookNowScreen pickup/drop-off address text persistence', () {
    testWidgets(
      'REGRESSION: address text fields still show the saved addresses after going to Step 2 (Vehicle) and back to Step 1 (Location)',
      (tester) async {
        tester.view.physicalSize = const Size(390, 844);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        final autocompleteCallCount = [0];
        await http.runWithClient(() async {
          await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
          await _settle(tester);

          await tester.enterText(find.byType(TextField).first, 'Rizal');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);

          await tester.enterText(find.byType(TextField).last, 'Fair');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Fairview').first);
          await _settle(tester);

          expect(
            _textFieldValue(tester, find.byType(TextField).first),
            'Rizal Park, Manila',
          );
          expect(
            _textFieldValue(tester, find.byType(TextField).last),
            'Fairview, Quezon City',
          );

          await tester.tap(find.text('Continue'));
          await _settle(tester);

          expect(find.text('What vehicle are we towing?'), findsOneWidget);

          await tester.tap(find.byIcon(Icons.arrow_back_ios_new_rounded));
          await _settle(tester);

          expect(find.text('PICKUP & DROP-OFF'), findsOneWidget);
          expect(
            _textFieldValue(tester, find.byType(TextField).first),
            'Rizal Park, Manila',
          );
          expect(
            _textFieldValue(tester, find.byType(TextField).last),
            'Fairview, Quezon City',
          );
        }, () => _client(autocompleteCallCount));

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets(
      'REGRESSION: address text fields still show the saved addresses after a draft is restored',
      (tester) async {
        tester.view.physicalSize = const Size(390, 844);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        SharedPreferences.setMockInitialValues({
          'auth_token': 'test-token',
          'user_role': 'Customer',
          'user_id': 42,
          'booking_draft_v1': jsonEncode({
            'user_id': 42,
            'step': 0,
            'service_type': 'book_now',
            'pickup_lat': 14.5832,
            'pickup_lng': 120.9822,
            'pickup_address': 'Rizal Park, Manila',
            'dropoff_lat': 14.6905,
            'dropoff_lng': 120.9822,
            'dropoff_address': 'Fairview, Quezon City',
          }),
        });

        await http.runWithClient(() async {
          await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
          await _settle(tester);

          expect(find.text('Unfinished Booking'), findsOneWidget);
          await tester.tap(find.text('Resume'));
          await _settle(tester);

          expect(
            _textFieldValue(tester, find.byType(TextField).first),
            'Rizal Park, Manila',
          );
          expect(
            _textFieldValue(tester, find.byType(TextField).last),
            'Fairview, Quezon City',
          );
        }, () => _client([0]));

        await tester.pumpWidget(const SizedBox());
      },
    );
  });
}
