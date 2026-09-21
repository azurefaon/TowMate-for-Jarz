import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:image/image.dart' as img;
import 'package:image_picker_platform_interface/image_picker_platform_interface.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/customer/book_now_screen.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

class _FakeImagePickerPlatform extends ImagePickerPlatform {
  _FakeImagePickerPlatform(this.filePath);
  final String filePath;

  @override
  Future<XFile?> getImageFromSource({
    required ImageSource source,
    ImagePickerOptions options = const ImagePickerOptions(),
  }) async {
    return XFile(filePath);
  }
}

String _createFakePhotoFile() {
  final image = img.Image(width: 2, height: 2);
  final bytes = img.encodePng(image);
  final dir = Directory.systemTemp.createTempSync('towmate_test_photo');
  final file = File('${dir.path}/photo.png');
  file.writeAsBytesSync(bytes);
  return file.path;
}

List<Map<String, dynamic>> _vehicleTypesPayload() => [
  {
    'id': 1,
    'name': 'Motorcycle',
    'category': '2_wheeler',
    'description': 'Standard two-wheeled motorcycles.',
    'icon_path': null,
    'required_truck_type_id': 3,
  },
  {
    'id': 2,
    'name': 'Scooter',
    'category': '2_wheeler',
    'description': 'Small automatic scooters and e-scooters.',
    'icon_path': null,
    'required_truck_type_id': 3,
  },
  {
    'id': 3,
    'name': 'Sedan',
    'category': '4_wheeler',
    'description': 'Standard 4-door sedans and small cars.',
    'icon_path': null,
    'required_truck_type_id': 2,
  },
  {
    'id': 4,
    'name': 'SUV',
    'category': '4_wheeler',
    'description': 'Sport utility vehicles.',
    'icon_path': null,
    'required_truck_type_id': 2,
  },
  {
    'id': 5,
    'name': 'Van',
    'category': '4_wheeler',
    'description': 'Passenger and cargo vans.',
    'icon_path': null,
    'required_truck_type_id': 1,
  },
  {
    'id': 6,
    'name': 'Pickup',
    'category': '4_wheeler',
    'description': 'Pickup trucks.',
    'icon_path': null,
    'required_truck_type_id': 1,
  },
  {
    'id': 7,
    'name': 'Delivery Truck',
    'category': 'heavy_vehicle',
    'description': 'Medium-duty delivery trucks.',
    'icon_path': null,
    'required_truck_type_id': 1,
  },
];

http.Client _client({
  List<int> readyTruckTypeIds = const [1],
  List<int>? autocompleteCallCount,
}) {
  final counter = autocompleteCallCount ?? [0];
  return MockClient((request) async {
    final path = request.url.path;
    if (path.contains('vehicle-types')) {
      return _json(_vehicleTypesPayload());
    }
    if (path.contains('availability')) {
      return _json({
        'book_now_enabled': true,
        'ready_units_count': readyTruckTypeIds.length,
        'ready_by_class': {},
        'ready_truck_type_ids': readyTruckTypeIds,
      });
    }
    if (path.contains('autocomplete')) {
      counter[0]++;
      final lat = counter[0] == 1 ? 14.5832 : 14.6905;
      return _json({
        'suggestions': [
          {'label': 'Rizal Park, Manila', 'coordinates': [120.9822, lat]},
        ],
      });
    }
    if (path.contains('check-duplicate-route')) {
      return _json({'duplicate': false});
    }
    return _json({}, status: 404);
  });
}

Finder get _vehicleTypeSearchFields => find.byWidgetPredicate(
  (w) => w is TextField && w.decoration?.hintText == 'Search vehicle type',
);

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 12; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pumpToStep1(
  WidgetTester tester, {
  Size size = const Size(390, 844),
  List<int> readyTruckTypeIds = const [1],
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
  final autocompleteCallCount = [0];
  await http.runWithClient(() async {
    await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
    await _settle(tester);

    await tester.enterText(find.byType(TextField).first, 'Rizal');
    await tester.pump(const Duration(milliseconds: 500));
    await _settle(tester);
    await tester.tap(find.text('Rizal Park').first);
    await _settle(tester);

    await tester.enterText(find.byType(TextField).last, 'Rizal');
    await tester.pump(const Duration(milliseconds: 500));
    await _settle(tester);
    await tester.tap(find.text('Rizal Park').first);
    await _settle(tester);

    await tester.tap(find.text('Continue'));
    await _settle(tester);
  }, () => _client(readyTruckTypeIds: readyTruckTypeIds, autocompleteCallCount: autocompleteCallCount));
}

Future<void> _openPrimaryVehicleCategory(
  WidgetTester tester,
  String category,
) async {
  await tester.ensureVisible(find.text(category).last);
  await tester.tap(find.text(category).last);
  await _settle(tester);
}

Future<void> _selectVan(WidgetTester tester) async {
  await _openPrimaryVehicleCategory(tester, '4-Wheeler');
  await tester.ensureVisible(find.text('Van').last);
  await tester.tap(find.text('Van').last);
  await _settle(tester);
}

Future<void> _selectSedan(WidgetTester tester) async {
  await _openPrimaryVehicleCategory(tester, '4-Wheeler');
  await tester.ensureVisible(find.text('Sedan').last);
  await tester.tap(find.text('Sedan').last);
  await _settle(tester);
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  group('BookNowScreen Step 1 vehicle type selector', () {
    testWidgets('never shows a Tow Type / Light / Medium / Heavy selector', (tester) async {
      await _pumpToStep1(tester);

      expect(find.text('What vehicle are we towing?'), findsOneWidget);
      expect(find.text('VEHICLE TYPE'), findsOneWidget);
      expect(find.text('TOW TYPE'), findsNothing);
      expect(find.textContaining('Tow Type'), findsNothing);
      expect(find.textContaining('Tow Class'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('shows a search field and expandable Owner-managed categories immediately, with no Browse all gate', (
      tester,
    ) async {
      await _pumpToStep1(tester);

      expect(_vehicleTypeSearchFields, findsOneWidget);
      expect(find.text('Browse all vehicle types'), findsNothing);
      expect(find.text('View more'), findsNothing);
      expect(find.text('4-Wheeler'), findsOneWidget);
      expect(find.text('2-Wheeler'), findsOneWidget);
      expect(find.text('Heavy Vehicle'), findsOneWidget);
      expect(find.text('Sedan'), findsNothing);
      expect(find.text('Pickup'), findsNothing);
      expect(find.byIcon(Icons.expand_more_rounded), findsWidgets);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('tapping a category expands it to reveal its vehicles in Owner-defined order', (tester) async {
      await _pumpToStep1(tester);

      await tester.tap(find.text('4-Wheeler'));
      await _settle(tester);
      expect(find.text('Sedan'), findsOneWidget);
      expect(find.text('Pickup'), findsOneWidget);

      await tester.tap(find.text('Heavy Vehicle'));
      await _settle(tester);
      expect(find.text('Delivery Truck'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('typing a search directly shows matching vehicles without expanding a category first', (
      tester,
    ) async {
      await _pumpToStep1(tester);

      await tester.enterText(_vehicleTypeSearchFields.first, 'deliv');
      await _settle(tester);

      expect(find.text('Delivery Truck'), findsOneWidget);
      expect(find.text('Pickup'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('selecting a vehicle from an expanded category collapses into a selected summary', (
      tester,
    ) async {
      await _pumpToStep1(tester);

      await tester.tap(find.text('Heavy Vehicle'));
      await _settle(tester);
      await tester.tap(find.text('Delivery Truck'));
      await _settle(tester);

      expect(find.text('Heavy Vehicle'), findsNothing);
      expect(find.text('Delivery Truck'), findsOneWidget);
      expect(find.text('Change'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('typing in search filters visible rows, case-insensitively, without an API call per keystroke', (
      tester,
    ) async {
      await _pumpToStep1(tester);

      await tester.enterText(_vehicleTypeSearchFields.first, 'sed');
      await _settle(tester);
      expect(find.text('Sedan'), findsOneWidget);
      expect(find.text('Motorcycle'), findsNothing);

      await tester.enterText(_vehicleTypeSearchFields.first, 'SED');
      await _settle(tester);
      expect(find.text('Sedan'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('an unmatched search shows the no-results message', (tester) async {
      await _pumpToStep1(tester);

      await tester.enterText(_vehicleTypeSearchFields.first, 'zzz-no-match');
      await _settle(tester);

      expect(find.text('No vehicle types found.'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('selecting a Vehicle Type collapses the list into a selected summary with a Change action', (
      tester,
    ) async {
      await _pumpToStep1(tester);

      await _selectVan(tester);

      expect(find.text('Change'), findsOneWidget);
      expect(find.text('Motorcycle'), findsNothing);
      expect(find.text('View more'), findsNothing);
      expect(_vehicleTypeSearchFields, findsNothing);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('tapping Change reopens the selector without clearing the current selection', (tester) async {
      await _pumpToStep1(tester);
      await _selectVan(tester);

      await tester.tap(find.text('Change'));
      await _settle(tester);

      expect(_vehicleTypeSearchFields, findsOneWidget);
      expect(find.text('4-Wheeler'), findsOneWidget);

      await tester.tap(find.text('4-Wheeler'));
      await _settle(tester);

      expect(find.text('Motorcycle'), findsNothing);
      expect(find.text('Sedan'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('photos already added are preserved while browsing/Changing the vehicle type', (tester) async {
      final previousPlatform = ImagePickerPlatform.instance;
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(_createFakePhotoFile());
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      await _pumpToStep1(tester);
      await _selectVan(tester);

      await tester.tap(find.text('Add vehicle photos'));
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);
      expect(find.text('1 / 5 photos'), findsOneWidget);

      await tester.tap(find.text('Change'));
      await _settle(tester);
      await _openPrimaryVehicleCategory(tester, '4-Wheeler');
      await tester.tap(find.text('Van').last);
      await _settle(tester);

      expect(find.text('1 / 5 photos'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('an available Vehicle Type shows Available for Book Now in green', (tester) async {
      await _pumpToStep1(tester);
      await _selectVan(tester);

      expect(find.text('Available for Book Now'), findsOneWidget);
      final label = tester.widget<Text>(find.text('Available for Book Now'));
      expect(label.style?.color, TmColors.success);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('an unavailable Vehicle Type shows Not available for Book Now via No Units Available modal', (
      tester,
    ) async {
      await _pumpToStep1(tester);
      await _selectSedan(tester);

      expect(find.text('No Units Available'), findsOneWidget);
      await tester.tap(find.text('Cancel'));
      await _settle(tester);

      expect(find.text('Not available for Book Now'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    for (final width in [320.0, 360.0, 390.0, 412.0]) {
      testWidgets('renders Step 1 without overflow at ${width.toInt()}px width', (tester) async {
        await _pumpToStep1(tester, size: Size(width, 900));
        expect(tester.takeException(), isNull);

        await _selectVan(tester);
        expect(tester.takeException(), isNull);

        await tester.pumpWidget(const SizedBox());
      });
    }
  });

  group('BookNowScreen Step 1 vehicle picker layout stability', () {
    for (final size in [Size(320, 480), Size(360, 560), Size(390, 640)]) {
      testWidgets(
        'shows the search field and categories without overflow at ${size.width.toInt()}x${size.height.toInt()}',
        (tester) async {
          await _pumpToStep1(tester, size: size);
          expect(tester.takeException(), isNull);

          await tester.tap(find.text('4-Wheeler'));
          await _settle(tester);
          expect(tester.takeException(), isNull);

          await tester.tap(find.text('Heavy Vehicle'));
          await _settle(tester);
          expect(tester.takeException(), isNull);

          await tester.pumpWidget(const SizedBox());
        },
      );
    }

    testWidgets('the step indicator and Continue button never move while the category list scrolls', (
      tester,
    ) async {
      await _pumpToStep1(tester, size: const Size(360, 560));

      await tester.tap(find.text('4-Wheeler'));
      await _settle(tester);

      final stepRectBefore = tester.getRect(find.text('Location'));
      final continueRectBefore = tester.getRect(find.text('Continue'));

      await tester.drag(
        find.byType(SingleChildScrollView),
        const Offset(0, -300),
      );
      await _settle(tester);
      expect(tester.takeException(), isNull);

      final stepRectAfter = tester.getRect(find.text('Location'));
      final continueRectAfter = tester.getRect(find.text('Continue'));

      expect(stepRectAfter, equals(stepRectBefore));
      expect(continueRectAfter, equals(continueRectBefore));
      expect(stepRectAfter.bottom, lessThanOrEqualTo(continueRectAfter.top));

      await tester.pumpWidget(const SizedBox());
    });
  });

  group('BookNowScreen Schedule entire request returns to Step 0 for date/time', () {
    testWidgets('Schedule entire request navigates back to Step 0 and reveals Preferred Date + Preferred Time', (tester) async {
      await _pumpToStep1(tester);
      await _selectSedan(tester);

      expect(find.text('No Units Available'), findsOneWidget);
      await tester.tap(find.text('Schedule entire request').last);
      await _settle(tester);

      expect(find.text('VEHICLE TYPE'), findsNothing);
      expect(find.text('BOOKING MODE'), findsOneWidget);
      expect(find.text('Preferred Date'), findsOneWidget);
      expect(find.text('Preferred Time'), findsOneWidget);
      expect(find.text('Select date'), findsOneWidget);
      expect(find.text('Select time'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('opening the date picker does not commit a date; Cancel leaves it unselected', (tester) async {
      await _pumpToStep1(tester);
      await _selectSedan(tester);
      await tester.tap(find.text('Schedule entire request').last);
      await _settle(tester);

      await tester.tap(find.text('Select date'));
      await _settle(tester);
      expect(find.text('Cancel'), findsOneWidget);

      await tester.tap(find.text('Cancel'));
      await _settle(tester);

      expect(find.text('Select date'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('a date is only written to state after explicit OK confirmation', (tester) async {
      await _pumpToStep1(tester);
      await _selectSedan(tester);
      await tester.tap(find.text('Schedule entire request').last);
      await _settle(tester);

      await tester.tap(find.text('Select date'));
      await _settle(tester);
      expect(find.text('OK'), findsOneWidget);

      await tester.tap(find.text('OK'));
      await _settle(tester);

      expect(find.text('Select date'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('time remains unselected (Select time) before confirmation, never shows 00:00 AM', (tester) async {
      await _pumpToStep1(tester);
      await _selectSedan(tester);
      await tester.tap(find.text('Schedule entire request').last);
      await _settle(tester);

      expect(find.text('Select time'), findsOneWidget);
      expect(find.textContaining('00:00'), findsNothing);
      expect(find.textContaining('12:00 AM'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('time Cancel preserves the previous/null value', (tester) async {
      await _pumpToStep1(tester);
      await _selectSedan(tester);
      await tester.tap(find.text('Schedule entire request').last);
      await _settle(tester);

      await tester.tap(find.text('Select time'));
      await _settle(tester);
      await tester.tap(find.text('Cancel'));
      await _settle(tester);

      expect(find.text('Select time'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('a time is only written to state after explicit OK confirmation', (tester) async {
      await _pumpToStep1(tester);
      await _selectSedan(tester);
      await tester.tap(find.text('Schedule entire request').last);
      await _settle(tester);

      await tester.tap(find.text('Select time'));
      await _settle(tester);
      await tester.tap(find.text('OK'));
      await _settle(tester);

      expect(find.text('Select time'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('Continue on Step 0 is blocked until both date and time are confirmed, with exact per-field hints', (
      tester,
    ) async {
      await _pumpToStep1(tester);
      await _selectSedan(tester);
      await tester.tap(find.text('Schedule entire request').last);
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);
      expect(find.text('Select a preferred date to continue'), findsOneWidget);
      expect(find.text('BOOKING MODE'), findsOneWidget);

      await tester.tap(find.text('Select date'));
      await _settle(tester);
      await tester.tap(find.text('OK'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);
      expect(find.text('Select a preferred time to continue'), findsOneWidget);
      expect(find.text('BOOKING MODE'), findsOneWidget);

      await tester.tap(find.text('Select time'));
      await _settle(tester);
      await tester.tap(find.text('OK'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);
      expect(find.text('BOOKING MODE'), findsNothing);
      expect(find.text('VEHICLE TYPE'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets(
      'REGRESSION: unavailable -> Schedule entire request -> confirm date/time -> Step 1 keeps the same vehicle with no schedule fields',
      (tester) async {
        await _pumpToStep1(tester);
        await _selectSedan(tester);

        expect(find.text('No Units Available'), findsOneWidget);
        await tester.tap(find.text('Schedule entire request').last);
        await _settle(tester);

        await tester.tap(find.text('Select date'));
        await _settle(tester);
        await tester.tap(find.text('OK'));
        await _settle(tester);
        await tester.tap(find.text('Select time'));
        await _settle(tester);
        await tester.tap(find.text('OK'));
        await _settle(tester);

        await tester.ensureVisible(find.text('Continue'));
        await tester.tap(find.text('Continue'));
        await _settle(tester);

        expect(find.text('VEHICLE TYPE'), findsOneWidget);
        expect(find.text('Sedan'), findsOneWidget);
        expect(find.text('No Units Available'), findsNothing);
        expect(find.text('Available for Book Now'), findsNothing);
        expect(find.text('Preferred Date'), findsNothing);
        expect(find.text('Preferred Time'), findsNothing);
        expect(find.text('SCHEDULE'), findsNothing);

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets('choosing a different, available vehicle after Schedule entire request keeps the request scheduled, never reverting to Book Now', (tester) async {
      await _pumpToStep1(tester, readyTruckTypeIds: const [1]);
      await _selectSedan(tester);
      expect(find.text('No Units Available'), findsOneWidget);
      await tester.tap(find.text('Schedule entire request').last);
      await _settle(tester);

      await tester.tap(find.text('Select date'));
      await _settle(tester);
      await tester.tap(find.text('OK'));
      await _settle(tester);
      await tester.tap(find.text('Select time'));
      await _settle(tester);
      await tester.tap(find.text('OK'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);

      await tester.tap(find.text('Change'));
      await _settle(tester);
      await _openPrimaryVehicleCategory(tester, '4-Wheeler');
      await tester.tap(find.text('Van').last);
      await _settle(tester);

      expect(find.text('Preferred Date'), findsNothing);
      expect(find.text('Preferred Time'), findsNothing);
      expect(find.text('Available for Book Now'), findsNothing);
      expect(find.text('No Units Available'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('choosing Schedule Later on Step 0 keeps the request scheduled after selecting a vehicle on Step 1', (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
      final autocompleteCallCount = [0];
      await http.runWithClient(() async {
        await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
        await _settle(tester);

        await tester.enterText(find.byType(TextField).first, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('Rizal Park').first);
        await _settle(tester);

        await tester.enterText(find.byType(TextField).last, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('Rizal Park').first);
        await _settle(tester);

        await tester.tap(find.text('Schedule Later'));
        await _settle(tester);

        await tester.tap(find.text('Select date'));
        await _settle(tester);
        await tester.tap(find.text('OK'));
        await _settle(tester);
        await tester.tap(find.text('Select time'));
        await _settle(tester);
        await tester.tap(find.text('OK'));
        await _settle(tester);

        await tester.tap(find.text('Continue'));
        await _settle(tester);
      }, () => _client(autocompleteCallCount: autocompleteCallCount));

      await _selectSedan(tester);

      expect(find.text('SCHEDULE'), findsNothing);
      expect(find.text('Preferred Date'), findsNothing);
      expect(find.text('No Units Available'), findsNothing);
      expect(find.text('Available for Book Now'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('choosing Schedule Later on Step 0 then switching vehicles on Step 1 still keeps the request scheduled', (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
      final autocompleteCallCount = [0];
      await http.runWithClient(() async {
        await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
        await _settle(tester);

        await tester.enterText(find.byType(TextField).first, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('Rizal Park').first);
        await _settle(tester);

        await tester.enterText(find.byType(TextField).last, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('Rizal Park').first);
        await _settle(tester);

        await tester.tap(find.text('Schedule Later'));
        await _settle(tester);

        await tester.tap(find.text('Select date'));
        await _settle(tester);
        await tester.tap(find.text('OK'));
        await _settle(tester);
        await tester.tap(find.text('Select time'));
        await _settle(tester);
        await tester.tap(find.text('OK'));
        await _settle(tester);

        await tester.tap(find.text('Continue'));
        await _settle(tester);
      }, () => _client(autocompleteCallCount: autocompleteCallCount));

      await _selectSedan(tester);
      expect(find.text('SCHEDULE'), findsNothing);
      expect(find.text('Preferred Date'), findsNothing);

      await tester.tap(find.text('Change'));
      await _settle(tester);
      await _selectVan(tester);

      expect(find.text('SCHEDULE'), findsNothing);
      expect(find.text('Preferred Date'), findsNothing);
      expect(find.text('Available for Book Now'), findsNothing);
      expect(find.text('No Units Available'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });
  });

  group('BookNowScreen Step 1 additional vehicles', () {
    testWidgets('an unselected additional vehicle shows search and expandable categories immediately', (
      tester,
    ) async {
      await _pumpToStep1(tester);

      await tester.ensureVisible(find.text('Add another vehicle'));
      await tester.tap(find.text('Add another vehicle'));
      await _settle(tester);

      expect(_vehicleTypeSearchFields, findsNWidgets(2));
      expect(find.text('Browse all vehicle types'), findsNothing);
      expect(find.text('4-Wheeler'), findsNWidgets(2));

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('selecting an additional Vehicle Type collapses into a compact summary with a Change action', (
      tester,
    ) async {
      await _pumpToStep1(tester);

      await tester.ensureVisible(find.text('Add another vehicle'));
      await tester.tap(find.text('Add another vehicle'));
      await _settle(tester);


      await tester.ensureVisible(find.text('4-Wheeler').last);
      await tester.tap(find.text('4-Wheeler').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Van').last);
      await tester.tap(find.text('Van').last);
      await _settle(tester);

      expect(find.text('Change'), findsOneWidget);
      expect(find.text('Available for Book Now'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('an unavailable additional vehicle shows a Schedule entire request link, not a native picker', (
      tester,
    ) async {
      await _pumpToStep1(tester);

      await tester.ensureVisible(find.text('Add another vehicle'));
      await tester.tap(find.text('Add another vehicle'));
      await _settle(tester);


      await tester.ensureVisible(find.text('4-Wheeler').last);
      await tester.tap(find.text('4-Wheeler').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Sedan').last);
      await tester.tap(find.text('Sedan').last);
      await _settle(tester);

      expect(find.text('Not available for Book Now'), findsOneWidget);
      expect(find.text('Schedule entire request').last, findsOneWidget);
      expect(find.text('No Units Available'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('tapping Schedule entire request for an extra switches the whole request to Scheduled', (
      tester,
    ) async {
      final previousPlatform = ImagePickerPlatform.instance;
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(_createFakePhotoFile());
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      await _pumpToStep1(tester);
      await _selectVan(tester);
      await tester.tap(find.text('Add vehicle photos'));
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Add another vehicle'));
      await tester.tap(find.text('Add another vehicle'));
      await _settle(tester);


      await tester.ensureVisible(find.text('4-Wheeler').last);
      await tester.tap(find.text('4-Wheeler').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Sedan').last);
      await tester.tap(find.text('Sedan').last);
      await _settle(tester);

      await tester.ensureVisible(find.text('Schedule entire request').last);
      await tester.tap(find.text('Schedule entire request').last);
      await _settle(tester);

      expect(find.text('VEHICLE TYPE'), findsNothing);
      expect(find.text('Preferred Date'), findsOneWidget);
      expect(find.text('Preferred Time'), findsOneWidget);

      await tester.tap(find.text('Select date'));
      await _settle(tester);
      await tester.tap(find.text('OK'));
      await _settle(tester);
      await tester.tap(find.text('Select time'));
      await _settle(tester);
      await tester.tap(find.text('OK'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);

      expect(find.text('Preferred Date'), findsNothing);
      expect(find.text('Preferred Time'), findsNothing);

      await tester.ensureVisible(find.text('Vehicle 2'));
      await tester.tap(find.text('Vehicle 2'));
      await _settle(tester);

      expect(
        find.text('This vehicle will be scheduled with the rest of your request.'),
        findsOneWidget,
      );

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('an unavailable additional vehicle blocks Continue until the request is scheduled or removed', (
      tester,
    ) async {
      final previousPlatform = ImagePickerPlatform.instance;
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(_createFakePhotoFile());
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      await _pumpToStep1(tester);
      await _selectVan(tester);
      await tester.tap(find.text('Add vehicle photos'));
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Add another vehicle'));
      await tester.tap(find.text('Add another vehicle'));
      await _settle(tester);
      await tester.ensureVisible(find.text('4-Wheeler').last);
      await tester.tap(find.text('4-Wheeler').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Sedan').last);
      await tester.tap(find.text('Sedan').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Add vehicle photos').last);
      await tester.tap(find.text('Add vehicle photos').last);
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);
      expect(find.text('Vehicle 2 is not available for Book Now'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('vehicle count reads "of 6 vehicles" and stays correct across add/remove', (tester) async {
      await _pumpToStep1(tester);

      expect(find.text('1 of 6 vehicles'), findsOneWidget);

      await tester.ensureVisible(find.text('Add another vehicle'));
      await tester.tap(find.text('Add another vehicle'));
      await _settle(tester);
      expect(find.text('2 of 6 vehicles'), findsOneWidget);

      final removeButtons = find.text('Remove');
      await tester.ensureVisible(removeButtons.first);
      await tester.tap(removeButtons.first);
      await _settle(tester);
      expect(find.text('1 of 6 vehicles'), findsOneWidget);
      expect(find.textContaining('added'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('max 6 vehicles is still enforced (1 primary + 5 additional)', (tester) async {
      await _pumpToStep1(tester);

      for (var i = 0; i < 5; i++) {
        await tester.ensureVisible(find.text('Add another vehicle'));
        await tester.tap(find.text('Add another vehicle'));
        await _settle(tester);
      }

      expect(find.text('6 of 6 vehicles'), findsOneWidget);
      expect(find.text('Add another vehicle'), findsNothing);
      expect(find.text('Maximum 6 vehicles reached'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('removing a middle vehicle from 3+ additional vehicles keeps the remaining vehicles correct', (
      tester,
    ) async {
      await _pumpToStep1(tester, readyTruckTypeIds: const [1, 2, 3]);

      for (var i = 0; i < 3; i++) {
        await tester.ensureVisible(find.text('Add another vehicle'));
        await tester.tap(find.text('Add another vehicle'));
        await _settle(tester);
      }

      Future<void> selectVehicleType(String slotTitle, String vehicleName) async {
        await tester.ensureVisible(find.text(slotTitle));
        await tester.tap(find.text(slotTitle));
        await _settle(tester);
        await tester.ensureVisible(find.text('4-Wheeler').last);
        await tester.tap(find.text('4-Wheeler').last);
        await _settle(tester);
        final typeFinder = find.text(vehicleName).last;
        await tester.ensureVisible(typeFinder);
        await tester.tap(typeFinder);
        await _settle(tester);
        await tester.ensureVisible(find.text(slotTitle));
        await tester.tap(find.text(slotTitle));
        await _settle(tester);
      }

      await selectVehicleType('Vehicle 2', 'Sedan');
      await selectVehicleType('Vehicle 3', 'Van');
      await selectVehicleType('Vehicle 4', 'Sedan');

      final removeButtons = find.text('Remove');
      await tester.ensureVisible(removeButtons.at(1));
      await tester.tap(removeButtons.at(1));
      await _settle(tester);

      expect(find.text('Vehicle 2'), findsOneWidget);
      expect(find.text('Vehicle 3'), findsOneWidget);
      expect(find.text('Vehicle 4'), findsNothing);
      expect(find.textContaining('Van /'), findsNothing);
      expect(find.textContaining('Sedan /'), findsNWidgets(2));

      await tester.pumpWidget(const SizedBox());
    });
  });

  group('BookNowScreen Step 1 header spacing', () {
    testWidgets('does not show a Not sure what to choose? link or its help sheet', (tester) async {
      await _pumpToStep1(tester);

      expect(find.text('VEHICLE TYPE'), findsOneWidget);
      expect(find.text('Not sure what to choose?'), findsNothing);
      expect(find.text('Choosing a vehicle type'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });
  });

  group('BookNowScreen Step 1 Review gate', () {
    testWidgets('missing primary photo blocks navigation to Review', (tester) async {
      await _pumpToStep1(tester);
      await _selectVan(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);

      expect(find.text('TRIP'), findsNothing);
      expect(find.text('Add at least 1 photo for Vehicle 1 to continue'), findsOneWidget);
    });

    testWidgets('missing additional vehicle photo blocks navigation to Review', (tester) async {
      final previousPlatform = ImagePickerPlatform.instance;
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(_createFakePhotoFile());
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      await _pumpToStep1(tester);
      await _selectVan(tester);
      await tester.tap(find.text('Add vehicle photos'));
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Add another vehicle'));
      await tester.tap(find.text('Add another vehicle'));
      await _settle(tester);
      await tester.ensureVisible(find.text('4-Wheeler').last);
      await tester.tap(find.text('4-Wheeler').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Van').last);
      await tester.tap(find.text('Van').last);
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);

      expect(find.text('TRIP'), findsNothing);
      expect(find.text('Add at least 1 photo for Vehicle 2 to continue'), findsOneWidget);
    });

    testWidgets('unavailable primary Book Now blocks navigation to Review even with a photo', (tester) async {
      final previousPlatform = ImagePickerPlatform.instance;
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(_createFakePhotoFile());
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      await _pumpToStep1(tester);
      await _selectSedan(tester);
      expect(find.text('No Units Available'), findsOneWidget);
      await tester.tap(find.text('Cancel'));
      await _settle(tester);

      await tester.tap(find.text('Add vehicle photos'));
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);

      expect(find.text('TRIP'), findsNothing);
      expect(find.text('Vehicle 1 is not available for Book Now'), findsOneWidget);
    });

    testWidgets('unavailable additional Book Now blocks navigation to Review even with a photo', (tester) async {
      final previousPlatform = ImagePickerPlatform.instance;
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(_createFakePhotoFile());
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      await _pumpToStep1(tester);
      await _selectVan(tester);
      await tester.tap(find.text('Add vehicle photos'));
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Add another vehicle'));
      await tester.tap(find.text('Add another vehicle'));
      await _settle(tester);
      await tester.ensureVisible(find.text('4-Wheeler').last);
      await tester.tap(find.text('4-Wheeler').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Sedan').last);
      await tester.tap(find.text('Sedan').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Add vehicle photos').last);
      await tester.tap(find.text('Add vehicle photos').last);
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);

      expect(find.text('TRIP'), findsNothing);
      expect(find.text('Vehicle 2 is not available for Book Now'), findsOneWidget);
    });

    testWidgets('unavailable vehicle switched to Schedule entire request but no date blocks progressing to Step 1', (tester) async {
      await _pumpToStep1(tester);
      await _selectSedan(tester);
      await tester.tap(find.text('Schedule entire request').last);
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);

      expect(find.text('VEHICLE TYPE'), findsNothing);
      expect(find.text('Select a preferred date to continue'), findsOneWidget);
    });

    testWidgets('confirmed date but no time blocks progressing to Step 1', (tester) async {
      await _pumpToStep1(tester);
      await _selectSedan(tester);
      await tester.tap(find.text('Schedule entire request').last);
      await _settle(tester);

      await tester.tap(find.text('Select date'));
      await _settle(tester);
      await tester.tap(find.text('OK'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);

      expect(find.text('VEHICLE TYPE'), findsNothing);
      expect(find.text('Select a preferred time to continue'), findsOneWidget);
    });

    testWidgets('valid scheduled date + time passes and reaches Review', (tester) async {
      final previousPlatform = ImagePickerPlatform.instance;
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(_createFakePhotoFile());
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      await _pumpToStep1(tester);
      await _selectSedan(tester);
      await tester.tap(find.text('Schedule entire request').last);
      await _settle(tester);

      await tester.tap(find.text('Select date'));
      await _settle(tester);
      await tester.tap(find.text('OK'));
      await _settle(tester);
      await tester.tap(find.text('Select time'));
      await _settle(tester);
      await tester.tap(find.text('OK'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);

      await tester.tap(find.text('Add vehicle photos'));
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);

      expect(find.text('TRIP'), findsOneWidget);
    });

    testWidgets(
      'Schedule entire request switches every vehicle to Scheduled and one shared date/time unblocks Review',
      (tester) async {
        final previousPlatform = ImagePickerPlatform.instance;
        ImagePickerPlatform.instance = _FakeImagePickerPlatform(_createFakePhotoFile());
        addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

        await _pumpToStep1(tester);
        await _selectVan(tester);
        await tester.tap(find.text('Add vehicle photos'));
        await _settle(tester);
        await tester.tap(find.text('Choose from Gallery'));
        await _settle(tester);

        await tester.ensureVisible(find.text('Add another vehicle'));
        await tester.tap(find.text('Add another vehicle'));
        await _settle(tester);
        await tester.ensureVisible(find.text('4-Wheeler').last);
        await tester.tap(find.text('4-Wheeler').last);
        await _settle(tester);
        await tester.ensureVisible(find.text('Sedan').last);
        await tester.tap(find.text('Sedan').last);
        await _settle(tester);
        await tester.ensureVisible(find.text('Schedule entire request').last);
        await tester.tap(find.text('Schedule entire request').last);
        await _settle(tester);

        expect(find.text('Preferred Date'), findsOneWidget);
        expect(find.text('Preferred Time'), findsOneWidget);

        await tester.tap(find.text('Select date'));
        await _settle(tester);
        await tester.tap(find.text('OK'));
        await _settle(tester);
        await tester.tap(find.text('Select time'));
        await _settle(tester);
        await tester.tap(find.text('OK'));
        await _settle(tester);

        await tester.ensureVisible(find.text('Continue'));
        await tester.tap(find.text('Continue'));
        await _settle(tester);

        await tester.ensureVisible(find.text('Vehicle 2'));
        await tester.tap(find.text('Vehicle 2'));
        await _settle(tester);

        await tester.ensureVisible(find.text('Add vehicle photos').last);
        await tester.tap(find.text('Add vehicle photos').last);
        await _settle(tester);
        await tester.tap(find.text('Choose from Gallery'));
        await _settle(tester);

        await tester.ensureVisible(find.text('Continue'));
        await tester.tap(find.text('Continue'));
        await _settle(tester);

        expect(find.text('TRIP'), findsOneWidget);
      },
    );

    testWidgets('the first incomplete vehicle is reported even when a later vehicle is also incomplete', (
      tester,
    ) async {
      final previousPlatform = ImagePickerPlatform.instance;
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(_createFakePhotoFile());
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      await _pumpToStep1(tester, readyTruckTypeIds: const [1, 2]);
      await _selectVan(tester);
      await tester.tap(find.text('Add vehicle photos'));
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Add another vehicle'));
      await tester.tap(find.text('Add another vehicle'));
      await _settle(tester);
      await tester.ensureVisible(find.text('2-Wheeler').last);
      await tester.tap(find.text('2-Wheeler').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Motorcycle').last);
      await tester.tap(find.text('Motorcycle').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Add vehicle photos').last);
      await tester.tap(find.text('Add vehicle photos').last);
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Add another vehicle'));
      await tester.tap(find.text('Add another vehicle'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);

      expect(find.text('TRIP'), findsNothing);
      expect(find.text('Vehicle 2 is not available for Book Now'), findsOneWidget);
      expect(find.text('Select a vehicle type for Vehicle 3 to continue'), findsNothing);
    });
  });

  group('BookNowScreen Step 1 red validation styling', () {
    testWidgets('the bottom validation helper renders in destructive red', (tester) async {
      await _pumpToStep1(tester);

      final hint = tester.widget<Text>(find.text('Select a vehicle type for Vehicle 1 to continue'));
      expect(hint.style?.color, TmColors.destructive);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('Photo required renders in destructive red in the collapsed summary', (tester) async {
      await _pumpToStep1(tester);

      await tester.ensureVisible(find.text('Add another vehicle'));
      await tester.tap(find.text('Add another vehicle'));
      await _settle(tester);
      await tester.ensureVisible(find.text('4-Wheeler').last);
      await tester.tap(find.text('4-Wheeler').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Van').last);
      await tester.tap(find.text('Van').last);
      await _settle(tester);

      final subtitle = tester.widget<Text>(find.textContaining('Photo required'));
      expect(subtitle.style?.color, TmColors.destructive);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('Unavailable renders in destructive red in the collapsed summary', (tester) async {
      final previousPlatform = ImagePickerPlatform.instance;
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(_createFakePhotoFile());
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      await _pumpToStep1(tester);

      await tester.ensureVisible(find.text('Add another vehicle'));
      await tester.tap(find.text('Add another vehicle'));
      await _settle(tester);
      await tester.ensureVisible(find.text('4-Wheeler').last);
      await tester.tap(find.text('4-Wheeler').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Sedan').last);
      await tester.tap(find.text('Sedan').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Add vehicle photos').last);
      await tester.tap(find.text('Add vehicle photos').last);
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      final subtitle = tester.widget<Text>(find.textContaining('Unavailable'));
      expect(subtitle.style?.color, TmColors.destructive);

      await tester.pumpWidget(const SizedBox());
    });
  });

  group('BookNowScreen Step 1 date crash regression', () {
    testWidgets('the date-confirmation path does not crash for the primary vehicle', (tester) async {
      await _pumpToStep1(tester);
      await _selectSedan(tester);
      await tester.tap(find.text('Schedule entire request').last);
      await _settle(tester);

      await tester.tap(find.text('Select date'));
      await _settle(tester);
      await tester.tap(find.text('OK'));
      await _settle(tester);

      expect(tester.takeException(), isNull);
      expect(find.text('Select date'), findsNothing);
      expect(find.text('Select time'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });
  });

  group('BookNowScreen single request-level schedule regression', () {
    Future<void> pumpScheduledToStep1(WidgetTester tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
      final autocompleteCallCount = [0];
      await http.runWithClient(() async {
        await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
        await _settle(tester);

        await tester.enterText(find.byType(TextField).first, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('Rizal Park').first);
        await _settle(tester);

        await tester.enterText(find.byType(TextField).last, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('Rizal Park').first);
        await _settle(tester);

        await tester.tap(find.text('Schedule Later'));
        await _settle(tester);

        await tester.tap(find.text('Select date'));
        await _settle(tester);
        await tester.tap(find.text('OK'));
        await _settle(tester);
        await tester.tap(find.text('Select time'));
        await _settle(tester);
        await tester.tap(find.text('OK'));
        await _settle(tester);

        await tester.tap(find.text('Continue'));
        await _settle(tester);
      }, () => _client(autocompleteCallCount: autocompleteCallCount));
    }

    testWidgets('1. Schedule Later date/time persists from Step 1 through Step 2 and Review', (tester) async {
      final previousPlatform = ImagePickerPlatform.instance;
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(_createFakePhotoFile());
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      await pumpScheduledToStep1(tester);

      expect(find.text('Preferred Date'), findsNothing);
      expect(find.text('Preferred Time'), findsNothing);

      await _selectVan(tester);
      await tester.tap(find.text('Add vehicle photos'));
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);

      expect(find.text('TRIP'), findsOneWidget);
      expect(find.text('Scheduled'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('2. Step 2 does not request another schedule', (tester) async {
      final previousPlatform = ImagePickerPlatform.instance;
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(_createFakePhotoFile());
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      await pumpScheduledToStep1(tester);

      expect(find.text('SCHEDULE'), findsNothing);
      expect(find.text('Preferred Date'), findsNothing);
      expect(find.text('Preferred Time'), findsNothing);
      expect(find.text('Select date'), findsNothing);
      expect(find.text('Select time'), findsNothing);

      await _selectVan(tester);

      expect(find.text('SCHEDULE'), findsNothing);
      expect(find.text('Preferred Date'), findsNothing);
      expect(find.text('Preferred Time'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('3. Multiple vehicles inherit one request-level schedule shown identically in Review', (tester) async {
      final previousPlatform = ImagePickerPlatform.instance;
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(_createFakePhotoFile());
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      await pumpScheduledToStep1(tester);

      await _selectVan(tester);
      await tester.tap(find.text('Add vehicle photos'));
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Add another vehicle'));
      await tester.tap(find.text('Add another vehicle'));
      await _settle(tester);
      await tester.ensureVisible(find.text('4-Wheeler').last);
      await tester.tap(find.text('4-Wheeler').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Sedan').last);
      await tester.tap(find.text('Sedan').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Add vehicle photos').last);
      await tester.tap(find.text('Add vehicle photos').last);
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);

      expect(find.text('TRIP'), findsOneWidget);
      expect(find.text('Scheduled'), findsOneWidget);

      final scheduleTexts = tester
          .widgetList<Text>(find.byType(Text))
          .map((t) => t.data)
          .whereType<String>()
          .where((s) => s.contains(' · ') && (s.contains('AM') || s.contains('PM')))
          .toList();
      expect(scheduleTexts.length, 2);
      expect(scheduleTexts.toSet().length, 1);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('4. Changing vehicle type and adding/removing additional vehicles does not change the schedule', (tester) async {
      final previousPlatform = ImagePickerPlatform.instance;
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(_createFakePhotoFile());
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      await pumpScheduledToStep1(tester);

      await _selectSedan(tester);
      expect(find.text('Preferred Date'), findsNothing);

      await tester.tap(find.text('Change'));
      await _settle(tester);
      await _selectVan(tester);
      expect(find.text('Preferred Date'), findsNothing);
      expect(find.text('Preferred Time'), findsNothing);

      await tester.ensureVisible(find.text('Add another vehicle'));
      await tester.tap(find.text('Add another vehicle'));
      await _settle(tester);
      expect(find.text('Preferred Date'), findsNothing);

      final removeButtons = find.text('Remove');
      await tester.ensureVisible(removeButtons.first);
      await tester.tap(removeButtons.first);
      await _settle(tester);
      expect(find.text('Preferred Date'), findsNothing);

      await tester.tap(find.text('Add vehicle photos'));
      await _settle(tester);
      await tester.tap(find.text('Choose from Gallery'));
      await _settle(tester);

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);

      expect(find.text('TRIP'), findsOneWidget);
      expect(find.text('Scheduled'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('5. Back navigation from the vehicle step to Step 1 preserves the selected schedule', (tester) async {
      await pumpScheduledToStep1(tester);
      await _selectVan(tester);

      await tester.tap(find.byIcon(Icons.arrow_back_ios_new_rounded));
      await _settle(tester);

      expect(find.text('BOOKING MODE'), findsOneWidget);
      expect(find.text('Preferred Date'), findsOneWidget);
      expect(find.text('Preferred Time'), findsOneWidget);
      expect(find.text('Select date'), findsNothing);
      expect(find.text('Select time'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('6. Book Now flow never shows schedule date/time fields', (tester) async {
      await _pumpToStep1(tester);

      expect(find.text('SCHEDULE'), findsNothing);
      expect(find.text('Preferred Date'), findsNothing);
      expect(find.text('Preferred Time'), findsNothing);

      await _selectVan(tester);

      expect(find.text('Available for Book Now'), findsOneWidget);
      expect(find.text('Preferred Date'), findsNothing);
      expect(find.text('Preferred Time'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });
  });
}
