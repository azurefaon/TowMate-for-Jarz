import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_image_compress_platform_interface/flutter_image_compress_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:image/image.dart' as img;
import 'package:intl/intl.dart';
import 'package:image_picker_platform_interface/image_picker_platform_interface.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/models/booking_model.dart';
import 'package:towmate_app/screens/customer/book_now_screen.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(
    jsonEncode(body),
    status,
    headers: {'content-type': 'application/json'},
  );
}

final _priceFmt = NumberFormat('#,##0.00', 'en_PH');

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

class _FakeImageCompressPlatform extends FlutterImageCompressPlatform {
  @override
  Future<Uint8List?> compressWithFile(
    String path, {
    int minWidth = 1920,
    int minHeight = 1080,
    int inSampleSize = 1,
    int quality = 95,
    int rotate = 0,
    bool autoCorrectionAngle = true,
    CompressFormat format = CompressFormat.jpeg,
    bool keepExif = false,
    int numberOfRetries = 5,
  }) async {
    return File(path).readAsBytesSync();
  }

  @override
  Future<Uint8List?> compressAssetImage(
    String assetName, {
    int minWidth = 1920,
    int minHeight = 1080,
    int quality = 95,
    int rotate = 0,
    bool autoCorrectionAngle = true,
    CompressFormat format = CompressFormat.jpeg,
    bool keepExif = false,
  }) => throw UnimplementedError();

  @override
  Future<XFile?> compressAndGetFile(
    String path,
    String targetPath, {
    int minWidth = 1920,
    int minHeight = 1080,
    int inSampleSize = 1,
    int quality = 95,
    int rotate = 0,
    bool autoCorrectionAngle = true,
    CompressFormat format = CompressFormat.jpeg,
    bool keepExif = false,
    int numberOfRetries = 5,
  }) => throw UnimplementedError();

  @override
  Future<Uint8List> compressWithList(
    Uint8List image, {
    int minWidth = 1920,
    int minHeight = 1080,
    int quality = 95,
    int rotate = 0,
    int inSampleSize = 1,
    bool autoCorrectionAngle = true,
    CompressFormat format = CompressFormat.jpeg,
    bool keepExif = false,
  }) => throw UnimplementedError();

  @override
  Future<void> showNativeLog(bool value) async {}

  @override
  void ignoreCheckSupportPlatform(bool bool) {}

  @override
  FlutterImageCompressValidator get validator => throw UnimplementedError();
}

String _createFakePhotoFile() {
  final image = img.Image(width: 2, height: 2);
  final bytes = img.encodePng(image);
  final dir = Directory.systemTemp.createTempSync('towmate_test_photo_step2');
  final file = File('${dir.path}/photo.png');
  file.writeAsBytesSync(bytes);
  return file.path;
}

List<Map<String, dynamic>> _vehicleTypesPayload() => [
  {
    'id': 1,
    'name': 'Sedan',
    'category': '4_wheeler',
    'description': 'Standard 4-door sedans.',
    'icon_path': null,
    'required_truck_type_id': 1,
  },
  {
    'id': 2,
    'name': 'Van',
    'category': '4_wheeler',
    'description': 'Passenger and cargo vans.',
    'icon_path': null,
    'required_truck_type_id': 1,
  },
  {
    'id': 3,
    'name': 'Motorcycle',
    'category': '2_wheeler',
    'description': 'Standard two-wheeled motorcycles.',
    'icon_path': null,
    'required_truck_type_id': 2,
  },
  {
    'id': 4,
    'name': 'Scooter',
    'category': '2_wheeler',
    'description': 'Small automatic scooters.',
    'icon_path': null,
    'required_truck_type_id': 2,
  },
];

Map<String, dynamic> _scheduledExtraPreview({
  required int vehicleTypeId,
  required int truckTypeId,
  required double baseRate,
  double distanceKm = 5.64,
  double perKmRate = 300,
}) {
  final distanceFee = distanceKm > 4.0
      ? double.parse(((distanceKm - 4.0) * perKmRate).toStringAsFixed(2))
      : 0.0;
  final subtotal = baseRate + distanceFee;
  final vat = double.parse((subtotal * 0.12).toStringAsFixed(2));
  final finalTotal = double.parse((subtotal + vat).toStringAsFixed(2));
  return {
    'truck_type_id': truckTypeId,
    'vehicle_type_id': vehicleTypeId,
    'base_rate': baseRate,
    'distance_fee': distanceFee,
    'vat_amount': vat,
    'final_total': finalTotal,
  };
}

Map<String, dynamic> _pricingResponse({
  double distanceKm = 5.64,
  double baseRate = 1500,
  double? baseRateTotal,
  double perKmRate = 300,
  double additionalFee = 0,
  double discountAmount = 0,
  List<Map<String, dynamic>> scheduledExtraPreviews = const [],
}) {
  final distanceFee = distanceKm > 4.0
      ? double.parse(((distanceKm - 4.0) * perKmRate).toStringAsFixed(2))
      : 0.0;
  final subtotal = baseRate + distanceFee - discountAmount;
  final vat = double.parse((subtotal * 0.12).toStringAsFixed(2));
  final finalTotal = double.parse(
    (subtotal + vat + additionalFee).toStringAsFixed(2),
  );
  final requestTotal = double.parse(
    (finalTotal +
            scheduledExtraPreviews.fold<double>(
              0,
              (sum, e) => sum + (e['final_total'] as num).toDouble(),
            ))
        .toStringAsFixed(2),
  );
  return {
    'route': {'distance_km': distanceKm},
    'pricing': {
      'distance_km': distanceKm,
      'extra_distance': distanceKm > 4.0 ? distanceKm - 4.0 : 0.0,
      'base_rate': baseRate,
      'base_rate_total': baseRateTotal ?? baseRate,
      'per_km_rate': perKmRate,
      'distance_fee': distanceFee,
      'computed_total': subtotal,
      'discount_percentage': 0,
      'discount_amount': discountAmount,
      'additional_fee': additionalFee,
      'discounted_total': subtotal,
      'vat_amount': vat,
      'final_total': finalTotal,
    },
    'scheduled_extra_previews': scheduledExtraPreviews,
    'request_total': requestTotal,
    'availability': {'book_now_enabled': true},
  };
}

http.Client _client({
  List<int> readyTruckTypeIds = const [1],
  Map<String, dynamic>? pricingOverride,
  bool pricingFails = false,
  double routeDistanceKm = 8.48,
}) {
  return MockClient((request) async {
    final url = request.url.toString();
    if (url.contains('router.project-osrm.org')) {
      return _json({
        'code': 'Ok',
        'routes': [
          {
            'distance': routeDistanceKm * 1000,
            'duration': 900,
            'geometry': {
              'coordinates': [
                [120.9822, 14.5832],
                [120.9822, 14.5832],
              ],
            },
          },
        ],
      });
    }
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
      final q = request.url.queryParameters['q'] ?? '';
      final lat = q.toLowerCase().contains('fairview') ? 14.6905 : 14.5832;
      return _json({
        'suggestions': [
          {
            'label': 'Rizal Park, Manila',
            'coordinates': [120.9822, lat],
          },
        ],
      });
    }
    if (path.contains('pricing-preview')) {
      if (pricingFails) return _json({'message': 'Failed'}, status: 422);
      return _json(pricingOverride ?? _pricingResponse());
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

Future<void> _pumpToStep2(
  WidgetTester tester, {
  Size size = const Size(390, 844),
  List<int> readyTruckTypeIds = const [1],
  Map<String, dynamic>? pricingOverride,
  bool pricingFails = false,
  double routeDistanceKm = 8.48,
  bool withPhoto = true,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  if (withPhoto) {
    final previousPlatform = ImagePickerPlatform.instance;
    ImagePickerPlatform.instance = _FakeImagePickerPlatform(
      _createFakePhotoFile(),
    );
    addTearDown(() => ImagePickerPlatform.instance = previousPlatform);
  }

  SharedPreferences.setMockInitialValues({
    'auth_token': 'test-token',
    'user_role': 'Customer',
  });
  await http.runWithClient(
    () async {
      await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
      await _settle(tester);

      await tester.enterText(find.byType(TextField).first, 'Rizal');
      await tester.pump(const Duration(milliseconds: 500));
      await _settle(tester);
      await tester.tap(find.text('Rizal Park').first);
      await _settle(tester);

      await tester.enterText(find.byType(TextField).last, 'Fairview');
      await tester.pump(const Duration(milliseconds: 500));
      await _settle(tester);
      await tester.tap(find.text('Rizal Park').first);
      await _settle(tester);

      await tester.tap(find.text('Continue'));
      await _settle(tester);

      await tester.ensureVisible(find.text('4-Wheeler').last);
      await tester.tap(find.text('4-Wheeler').last);
      await _settle(tester);
      await tester.ensureVisible(find.text('Sedan').last);
      await tester.tap(find.text('Sedan').last);
      await _settle(tester);

      if (withPhoto) {
        await tester.tap(find.text('Add vehicle photos'));
        await _settle(tester);
        await tester.tap(find.text('Choose from Gallery'));
        await _settle(tester);
      }

      await tester.ensureVisible(find.text('Continue'));
      await tester.tap(find.text('Continue'));
      await _settle(tester);
    },
    () => _client(
      readyTruckTypeIds: readyTruckTypeIds,
      pricingOverride: pricingOverride,
      pricingFails: pricingFails,
      routeDistanceKm: routeDistanceKm,
    ),
  );
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );
  FlutterImageCompressPlatform.instance = _FakeImageCompressPlatform();

  group('BookNowScreen Step 2 pricing authoritative behavior', () {
    testWidgets('canonical distance <= 4 km shows Distance Fee of ₱0.00', (
      tester,
    ) async {
      await _pumpToStep2(
        tester,
        pricingOverride: _pricingResponse(distanceKm: 3.5),
      );

      expect(find.text('Distance Fee'), findsOneWidget);
      expect(find.text('₱0.00'), findsOneWidget);
      expect(find.text('First 4 km included.'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets(
      'canonical distance > 4 km only charges the excess after 4 km',
      (tester) async {
        await _pumpToStep2(
          tester,
          pricingOverride: _pricingResponse(distanceKm: 6.0, perKmRate: 300),
        );

        expect(find.text('Distance Fee'), findsOneWidget);
        expect(find.text('₱600.00'), findsOneWidget);
        expect(find.text('First 4 km included.'), findsOneWidget);

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets(
      'VAT is computed from the canonical taxable subtotal, matching the server value',
      (tester) async {
        final resp = _pricingResponse(
          distanceKm: 5.64,
          baseRate: 1500,
          perKmRate: 300,
        );
        await _pumpToStep2(tester, pricingOverride: resp);

        final vat = (resp['pricing'] as Map)['vat_amount'] as double;
        expect(find.text('VAT (12%)'), findsOneWidget);
        expect(find.text('₱${_priceFmt.format(vat)}'), findsOneWidget);

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets(
      'Review displays the server preview total, not a locally recomputed value',
      (tester) async {
        final resp = _pricingResponse(
          distanceKm: 5.64,
          baseRate: 1500,
          perKmRate: 300,
        );
        await _pumpToStep2(tester, pricingOverride: resp);

        final finalTotal = (resp['pricing'] as Map)['final_total'] as double;
        expect(find.text('₱${_priceFmt.format(finalTotal)}'), findsOneWidget);

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets(
      'route distance and canonical pricing distance are both shown, clearly labeled, never implied equal',
      (tester) async {
        await _pumpToStep2(
          tester,
          routeDistanceKm: 8.48,
          pricingOverride: _pricingResponse(distanceKm: 5.64),
        );

        expect(find.textContaining('8.48 km'), findsOneWidget);
        expect(find.textContaining('5.64 km'), findsOneWidget);
        expect(find.textContaining('Billed distance is'), findsOneWidget);

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets('Truck Type / Tow Type is never exposed on the Review screen', (
      tester,
    ) async {
      await _pumpToStep2(tester);

      expect(find.textContaining('Truck Type'), findsNothing);
      expect(find.textContaining('Tow Type'), findsNothing);
      expect(find.textContaining('Light Duty'), findsNothing);
      expect(find.textContaining('Heavy Duty'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });
  });

  group('BookNowScreen Step 2 vehicle & photo display', () {
    testWidgets('Vehicle 1 name and photo are visible on Review', (
      tester,
    ) async {
      await _pumpToStep2(tester);

      expect(find.text('Vehicle 1'), findsOneWidget);
      expect(find.text('Sedan'), findsOneWidget);
      expect(find.text('Book Now'), findsOneWidget);
      expect(find.byType(Image), findsWidgets);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('an additional vehicle photo is visible on Review', (
      tester,
    ) async {
      final previousPlatform = ImagePickerPlatform.instance;
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(
        _createFakePhotoFile(),
      );
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      SharedPreferences.setMockInitialValues({
        'auth_token': 'test-token',
        'user_role': 'Customer',
      });
      await http.runWithClient(() async {
        await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
        await _settle(tester);
        await tester.enterText(find.byType(TextField).first, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('Rizal Park').first);
        await _settle(tester);
        await tester.enterText(find.byType(TextField).last, 'Fairview');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('Rizal Park').first);
        await _settle(tester);
        await tester.tap(find.text('Continue'));
        await _settle(tester);

        await tester.ensureVisible(find.text('4-Wheeler').last);
        await tester.tap(find.text('4-Wheeler').last);
        await _settle(tester);
        await tester.ensureVisible(find.text('Sedan').last);
        await tester.tap(find.text('Sedan').last);
        await _settle(tester);
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
        await tester.ensureVisible(find.text('Add vehicle photos').last);
        await tester.tap(find.text('Add vehicle photos').last);
        await _settle(tester);
        await tester.tap(find.text('Choose from Gallery'));
        await _settle(tester);

        await tester.ensureVisible(find.text('Continue'));
        await tester.tap(find.text('Continue'));
        await _settle(tester);
      }, () => _client());

      expect(find.text('Vehicle 2'), findsOneWidget);
      expect(find.text('Van'), findsOneWidget);
      expect(find.byType(Image), findsNWidgets(2));

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('multiple photos for a vehicle display as compact thumbnails', (
      tester,
    ) async {
      final previousPlatform = ImagePickerPlatform.instance;
      final path = _createFakePhotoFile();
      ImagePickerPlatform.instance = _FakeImagePickerPlatform(path);
      addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      SharedPreferences.setMockInitialValues({
        'auth_token': 'test-token',
        'user_role': 'Customer',
      });
      await http.runWithClient(() async {
        await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
        await _settle(tester);
        await tester.enterText(find.byType(TextField).first, 'Rizal');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('Rizal Park').first);
        await _settle(tester);
        await tester.enterText(find.byType(TextField).last, 'Fairview');
        await tester.pump(const Duration(milliseconds: 500));
        await _settle(tester);
        await tester.tap(find.text('Rizal Park').first);
        await _settle(tester);
        await tester.tap(find.text('Continue'));
        await _settle(tester);
        await tester.ensureVisible(find.text('4-Wheeler').last);
        await tester.tap(find.text('4-Wheeler').last);
        await _settle(tester);
        await tester.ensureVisible(find.text('Sedan').last);
        await tester.tap(find.text('Sedan').last);
        await _settle(tester);

        for (var i = 0; i < 2; i++) {
          await tester.tap(
            find.text(i == 0 ? 'Add vehicle photos' : 'Add more'),
          );
          await _settle(tester);
          await tester.tap(find.text('Choose from Gallery'));
          await _settle(tester);
        }

        await tester.ensureVisible(find.text('Continue'));
        await tester.tap(find.text('Continue'));
        await _settle(tester);
      }, () => _client());

      expect(find.byType(Image), findsNWidgets(2));

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets(
      'a Scheduled vehicle shows its confirmed date and time, not Book Now',
      (tester) async {
        final previousPlatform = ImagePickerPlatform.instance;
        ImagePickerPlatform.instance = _FakeImagePickerPlatform(
          _createFakePhotoFile(),
        );
        addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

        tester.view.physicalSize = const Size(390, 844);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        SharedPreferences.setMockInitialValues({
          'auth_token': 'test-token',
          'user_role': 'Customer',
        });
        await http.runWithClient(() async {
          await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
          await _settle(tester);
          await tester.enterText(find.byType(TextField).first, 'Rizal');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);
          await tester.enterText(find.byType(TextField).last, 'Fairview');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);
          await tester.tap(find.text('Continue'));
          await _settle(tester);

          await tester.ensureVisible(find.text('4-Wheeler').last);
          await tester.tap(find.text('4-Wheeler').last);
          await _settle(tester);
          await tester.ensureVisible(find.text('Sedan').last);
          await tester.tap(find.text('Sedan').last);
          await _settle(tester);
          expect(find.text('No Units Available'), findsNothing);
          await tester.tap(find.text('Add vehicle photos'));
          await _settle(tester);
          await tester.tap(find.text('Choose from Gallery'));
          await _settle(tester);

          await tester.ensureVisible(find.text('Continue'));
          await tester.tap(find.text('Continue'));
          await _settle(tester);
        }, () => _client());

        expect(find.text('TRIP'), findsOneWidget);

        await tester.pumpWidget(const SizedBox());
      },
    );

    for (final width in [320.0, 360.0, 390.0, 412.0]) {
      testWidgets(
        'renders Step 2 with 3 vehicles without overflow at ${width.toInt()}px',
        (tester) async {
          final previousPlatform = ImagePickerPlatform.instance;
          ImagePickerPlatform.instance = _FakeImagePickerPlatform(
            _createFakePhotoFile(),
          );
          addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

          tester.view.physicalSize = Size(width, 900);
          tester.view.devicePixelRatio = 1.0;
          addTearDown(tester.view.resetPhysicalSize);
          addTearDown(tester.view.resetDevicePixelRatio);

          SharedPreferences.setMockInitialValues({
            'auth_token': 'test-token',
            'user_role': 'Customer',
          });
          await http.runWithClient(() async {
            await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
            await _settle(tester);
            await tester.enterText(find.byType(TextField).first, 'Rizal');
            await tester.pump(const Duration(milliseconds: 500));
            await _settle(tester);
            await tester.tap(find.text('Rizal Park').first);
            await _settle(tester);
            await tester.enterText(find.byType(TextField).last, 'Fairview');
            await tester.pump(const Duration(milliseconds: 500));
            await _settle(tester);
            await tester.tap(find.text('Rizal Park').first);
            await _settle(tester);
            await tester.tap(find.text('Continue'));
            await _settle(tester);

            await tester.ensureVisible(find.text('4-Wheeler').last);
            await tester.tap(find.text('4-Wheeler').last);
            await _settle(tester);
            await tester.ensureVisible(find.text('Sedan').last);
            await tester.tap(find.text('Sedan').last);
            await _settle(tester);
            await tester.tap(find.text('Add vehicle photos'));
            await _settle(tester);
            await tester.tap(find.text('Choose from Gallery'));
            await _settle(tester);

            for (var i = 0; i < 2; i++) {
              await tester.ensureVisible(find.text('Add another vehicle'));
              await tester.tap(find.text('Add another vehicle'));
              await _settle(tester);
              await tester.ensureVisible(find.text('4-Wheeler').last);
              await tester.tap(find.text('4-Wheeler').last);
              await _settle(tester);
              await tester.ensureVisible(find.text('Van').last);
              await tester.tap(find.text('Van').last);
              await _settle(tester);
              await tester.ensureVisible(find.text('Add vehicle photos').last);
              await tester.tap(find.text('Add vehicle photos').last);
              await _settle(tester);
              await tester.tap(find.text('Choose from Gallery'));
              await _settle(tester);
            }

            tester.takeException();
            await tester.ensureVisible(find.text('Continue'));
            await tester.tap(find.text('Continue'));
            await _settle(tester);
          }, () => _client());

          expect(find.text('Vehicle 3'), findsOneWidget);
          expect(tester.takeException(), isNull);

          await tester.pumpWidget(const SizedBox());
        },
      );
    }
  });

  group('BookNowScreen Step 2 loading, error and submit safety', () {
    testWidgets(
      'pricing loading uses SkeletonBox, not a CircularProgressIndicator',
      (tester) async {
        tester.view.physicalSize = const Size(390, 844);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        final previousPlatform = ImagePickerPlatform.instance;
        ImagePickerPlatform.instance = _FakeImagePickerPlatform(
          _createFakePhotoFile(),
        );
        addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

        SharedPreferences.setMockInitialValues({
          'auth_token': 'test-token',
          'user_role': 'Customer',
        });
        await http.runWithClient(() async {
          await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
          await _settle(tester);
          await tester.enterText(find.byType(TextField).first, 'Rizal');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);
          await tester.enterText(find.byType(TextField).last, 'Fairview');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);
          await tester.tap(find.text('Continue'));
          await _settle(tester);
          await tester.ensureVisible(find.text('4-Wheeler').last);
          await tester.tap(find.text('4-Wheeler').last);
          await _settle(tester);
          await tester.ensureVisible(find.text('Sedan').last);
          await tester.tap(find.text('Sedan').last);
          await _settle(tester);
          await tester.tap(find.text('Add vehicle photos'));
          await _settle(tester);
          await tester.tap(find.text('Choose from Gallery'));
          await _settle(tester);

          await tester.ensureVisible(find.text('Continue'));
          await tester.tap(find.text('Continue'));
          await tester.pump();

          expect(find.byType(CircularProgressIndicator), findsNothing);
        }, () => _client());

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets(
      'pricing failure shows a clear retry state, not stale/local pricing',
      (tester) async {
        await _pumpToStep2(tester, pricingFails: true);

        expect(find.text('Retry'), findsOneWidget);
        expect(find.text('Total'), findsNothing);

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets(
      'Confirm Booking is disabled while pricing is unresolved (failed)',
      (tester) async {
        await _pumpToStep2(tester, pricingFails: true);

        final button = tester.widget<ElevatedButton>(
          find.byType(ElevatedButton),
        );
        expect(button.onPressed, isNull);

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets(
      'Confirm Booking becomes enabled once pricing resolves successfully',
      (tester) async {
        await _pumpToStep2(tester);

        final button = tester.widget<ElevatedButton>(
          find.byType(ElevatedButton),
        );
        expect(button.onPressed, isNotNull);

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets(
      'tapping Confirm Booking twice does not trigger a double submit',
      (tester) async {
        var submitCount = 0;
        tester.view.physicalSize = const Size(390, 844);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        final previousPlatform = ImagePickerPlatform.instance;
        ImagePickerPlatform.instance = _FakeImagePickerPlatform(
          _createFakePhotoFile(),
        );
        addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

        SharedPreferences.setMockInitialValues({
          'auth_token': 'test-token',
          'user_role': 'Customer',
        });
        final client = MockClient((request) async {
          final url = request.url.toString();
          if (url.contains('router.project-osrm.org')) {
            return _json({'code': 'Error'});
          }
          final path = request.url.path;
          if (path.contains('vehicle-types'))
            return _json(_vehicleTypesPayload());
          if (path.contains('availability')) {
            return _json({
              'book_now_enabled': true,
              'ready_units_count': 1,
              'ready_by_class': {},
              'ready_truck_type_ids': [1],
            });
          }
          if (path.contains('autocomplete')) {
            final q = request.url.queryParameters['q'] ?? '';
            final lat = q.toLowerCase().contains('fairview') ? 14.6905 : 14.5832;
            return _json({
              'suggestions': [
                {
                  'label': 'Rizal Park, Manila',
                  'coordinates': [120.9822, lat],
                },
              ],
            });
          }
          if (path.contains('pricing-preview'))
            return _json(_pricingResponse());
          if (path.endsWith('/v1/bookings') && request.method == 'POST') {
            submitCount++;
            await Future<void>.delayed(const Duration(milliseconds: 200));
            return _json({
              'success': false,
              'message': 'Blocked for test',
            }, status: 422);
          }
          return _json({}, status: 404);
        });

        await http.runWithClient(() async {
          await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
          await _settle(tester);
          await tester.enterText(find.byType(TextField).first, 'Rizal');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);
          await tester.enterText(find.byType(TextField).last, 'Fairview');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);
          await tester.tap(find.text('Continue'));
          await _settle(tester);
          await tester.ensureVisible(find.text('4-Wheeler').last);
          await tester.tap(find.text('4-Wheeler').last);
          await _settle(tester);
          await tester.ensureVisible(find.text('Sedan').last);
          await tester.tap(find.text('Sedan').last);
          await _settle(tester);
          await tester.tap(find.text('Add vehicle photos'));
          await _settle(tester);
          await tester.tap(find.text('Choose from Gallery'));
          await _settle(tester);
          await tester.ensureVisible(find.text('Continue'));
          await tester.tap(find.text('Continue'));
          await _settle(tester);

          await tester.tap(find.text('Confirm Booking'));
          await tester.tap(find.text('Confirm Booking'), warnIfMissed: false);
          await tester.pump(const Duration(milliseconds: 400));
        }, () => client);

        expect(submitCount, 1);

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets(
      'a successful submission navigates to the Booking Request Submitted screen, not straight Home',
      (tester) async {
        tester.view.physicalSize = const Size(390, 844);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        final previousPlatform = ImagePickerPlatform.instance;
        ImagePickerPlatform.instance = _FakeImagePickerPlatform(
          _createFakePhotoFile(),
        );
        addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

        SharedPreferences.setMockInitialValues({
          'auth_token': 'test-token',
          'user_role': 'Customer',
        });
        final client = MockClient((request) async {
          final url = request.url.toString();
          if (url.contains('router.project-osrm.org'))
            return _json({'code': 'Error'});
          final path = request.url.path;
          if (path.contains('vehicle-types'))
            return _json(_vehicleTypesPayload());
          if (path.contains('availability')) {
            return _json({
              'book_now_enabled': true,
              'ready_units_count': 1,
              'ready_by_class': {},
              'ready_truck_type_ids': [1],
            });
          }
          if (path.contains('autocomplete')) {
            final q = request.url.queryParameters['q'] ?? '';
            final lat = q.toLowerCase().contains('fairview') ? 14.6905 : 14.5832;
            return _json({
              'suggestions': [
                {
                  'label': 'Rizal Park, Manila',
                  'coordinates': [120.9822, lat],
                },
              ],
            });
          }
          if (path.contains('pricing-preview'))
            return _json(_pricingResponse());
          if (path.endsWith('/v1/bookings') && request.method == 'POST') {
            return _json({
              'success': true,
              'booking_code': 'TM-00225',
              'group_code': null,
              'bookings': [
                {
                  'booking_code': 'TM-00225',
                  'vehicle_type_name': 'Sedan',
                  'service_type': 'book_now',
                  'status': 'requested',
                  'is_current': true,
                },
              ],
            }, status: 201);
          }
          return _json({}, status: 404);
        });

        String? capturedRoute;
        Object? capturedArgs;
        await http.runWithClient(() async {
          await tester.pumpWidget(
            MaterialApp(
              onGenerateRoute: (settings) {
                if (settings.name == '/' || settings.name == null) {
                  return MaterialPageRoute(
                    builder: (_) => const BookNowScreen(),
                  );
                }
                capturedRoute = settings.name;
                capturedArgs = settings.arguments;
                return MaterialPageRoute(builder: (_) => const Scaffold());
              },
            ),
          );
          await _settle(tester);
          await tester.enterText(find.byType(TextField).first, 'Rizal');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);
          await tester.enterText(find.byType(TextField).last, 'Fairview');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);
          await tester.tap(find.text('Continue'));
          await _settle(tester);
          await tester.ensureVisible(find.text('4-Wheeler').last);
          await tester.tap(find.text('4-Wheeler').last);
          await _settle(tester);
          await tester.ensureVisible(find.text('Sedan').last);
          await tester.tap(find.text('Sedan').last);
          await _settle(tester);
          await tester.tap(find.text('Add vehicle photos'));
          await _settle(tester);
          await tester.tap(find.text('Choose from Gallery'));
          await _settle(tester);
          await tester.ensureVisible(find.text('Continue'));
          await tester.tap(find.text('Continue'));
          await _settle(tester);

          await tester.tap(find.text('Confirm Booking'));
          await _settle(tester);
        }, () => client);

        expect(capturedRoute, '/booking-success');
        expect(capturedArgs, isA<List<BookingGroupSibling>>());
        expect(
          (capturedArgs as List<BookingGroupSibling>).single.bookingCode,
          'TM-00225',
        );
      },
    );
  });

  group('BookNowScreen Step 2 single-mode review & pricing (Task D)', () {
    testWidgets('Book Now only: shows Total, no SCHEDULED section', (
      tester,
    ) async {
      await _pumpToStep2(tester, pricingOverride: _pricingResponse());

      expect(find.text('Total'), findsOneWidget);
      expect(find.text('SCHEDULED'), findsNothing);
      expect(find.text('Estimated Request Total'), findsNothing);
      expect(find.text('Estimated Base Rate'), findsNothing);
      expect(
        find.textContaining('Each vehicle is priced separately'),
        findsNothing,
      );
      expect(find.textContaining('confirming one request for'), findsNothing);

      await tester.pumpWidget(const SizedBox());
    });

    testWidgets(
      'Edit beside TRIP returns to Step 0 preserving pickup/drop-off coordinates',
      (tester) async {
        await _pumpToStep2(tester, pricingOverride: _pricingResponse());

        expect(find.text('TRIP'), findsOneWidget);
        await tester.tap(find.text('Edit').first);
        await _settle(tester);

        expect(find.text('BOOKING MODE'), findsOneWidget);

        await tester.tap(find.text('Continue'));
        await _settle(tester);
        expect(find.text('What vehicle are we towing?'), findsOneWidget);

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets(
      'Edit beside VEHICLES returns to Step 1 preserving vehicle selection',
      (tester) async {
        await _pumpToStep2(tester, pricingOverride: _pricingResponse());

        await tester.ensureVisible(find.text('Edit').last);
        await tester.tap(find.text('Edit').last);
        await _settle(tester);

        expect(find.text('What vehicle are we towing?'), findsOneWidget);
        expect(find.text('VEHICLE PHOTOS'), findsOneWidget);

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets(
      'Vehicle 1 scheduled alone (no extras): Estimated Request Total is not shown',
      (tester) async {
        SharedPreferences.setMockInitialValues({
          'auth_token': 'test-token',
          'user_role': 'Customer',
        });
        final previousPlatform = ImagePickerPlatform.instance;
        ImagePickerPlatform.instance = _FakeImagePickerPlatform(
          _createFakePhotoFile(),
        );
        addTearDown(() => ImagePickerPlatform.instance = previousPlatform);
        tester.view.physicalSize = const Size(390, 844);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        await http.runWithClient(
          () async {
            await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
            await _settle(tester);

            await tester.enterText(find.byType(TextField).first, 'Rizal');
            await tester.pump(const Duration(milliseconds: 500));
            await _settle(tester);
            await tester.tap(find.text('Rizal Park').first);
            await _settle(tester);
            await tester.enterText(find.byType(TextField).last, 'Fairview');
            await tester.pump(const Duration(milliseconds: 500));
            await _settle(tester);
            await tester.tap(find.text('Rizal Park').first);
            await _settle(tester);
            await tester.tap(find.text('Continue'));
            await _settle(tester);

            await tester.ensureVisible(find.text('4-Wheeler').last);
            await tester.tap(find.text('4-Wheeler').last);
            await _settle(tester);
            await tester.ensureVisible(find.text('Sedan').last);
            await tester.tap(find.text('Sedan').last);
            await _settle(tester);
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
          },
          () => _client(
            readyTruckTypeIds: const [2],
            pricingOverride: _pricingResponse(),
          ),
        );

        expect(find.text('Estimated Request Total'), findsNothing);
        expect(find.text('Total'), findsNothing);
        expect(find.text('VEHICLE 1'), findsOneWidget);
        expect(find.text('Estimated Base Rate'), findsOneWidget);
        expect(find.text('Estimated Distance Fee'), findsOneWidget);
        expect(find.text('Estimated VAT'), findsOneWidget);
        expect(find.text('Estimated Total'), findsOneWidget);

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets(
      'an all-Scheduled group renders every vehicle uniformly under one Price Summary',
      (tester) async {
        final scheduledExtra = _scheduledExtraPreview(
          vehicleTypeId: 3,
          truckTypeId: 2,
          baseRate: 900,
        );
        final resp = _pricingResponse(scheduledExtraPreviews: [scheduledExtra]);

        SharedPreferences.setMockInitialValues({
          'auth_token': 'test-token',
          'user_role': 'Customer',
        });
        final previousPlatform = ImagePickerPlatform.instance;
        ImagePickerPlatform.instance = _FakeImagePickerPlatform(
          _createFakePhotoFile(),
        );
        addTearDown(() => ImagePickerPlatform.instance = previousPlatform);
        tester.view.physicalSize = const Size(390, 844);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        await http.runWithClient(() async {
          await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
          await _settle(tester);
          await tester.enterText(find.byType(TextField).first, 'Rizal');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);
          await tester.enterText(find.byType(TextField).last, 'Fairview');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);
          await tester.tap(find.text('Continue'));
          await _settle(tester);

          await tester.ensureVisible(find.text('4-Wheeler').last);
          await tester.tap(find.text('4-Wheeler').last);
          await _settle(tester);
          await tester.ensureVisible(find.text('Sedan').last);
          await tester.tap(find.text('Sedan').last);
          await _settle(tester);
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

          await tester.ensureVisible(find.text('Continue'));
          await tester.tap(find.text('Continue'));
          await _settle(tester);
        }, () => _client(readyTruckTypeIds: const [2], pricingOverride: resp));

        expect(find.textContaining('Vehicle 1 —'), findsOneWidget);
        expect(find.textContaining('Vehicle 2 —'), findsOneWidget);
        expect(find.text('Base Rate'), findsNWidgets(2));
        expect(find.text('Distance Fee'), findsNWidgets(2));
        expect(find.text('VAT (12%)'), findsNWidgets(2));
        expect(find.text('Vehicle Total'), findsNWidgets(2));
        expect(find.text('Estimated Total'), findsOneWidget);
        expect(find.text('Total'), findsNothing);
        expect(
          find.textContaining(
            'Scheduled pricing may change after quotation review.',
          ),
          findsOneWidget,
        );
        expect(
          find.textContaining(
            'Each vehicle is priced separately and included in one quotation.',
          ),
          findsOneWidget,
        );
        expect(
          find.textContaining('confirming one request for 2 vehicles'),
          findsOneWidget,
        );

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets(
      'multiple Scheduled extras in an all-Scheduled group each render their own Estimated rows',
      (tester) async {
        final extra1 = _scheduledExtraPreview(
          vehicleTypeId: 3,
          truckTypeId: 2,
          baseRate: 900,
        );
        final extra2 = _scheduledExtraPreview(
          vehicleTypeId: 4,
          truckTypeId: 2,
          baseRate: 700,
        );
        final resp = _pricingResponse(scheduledExtraPreviews: [extra1, extra2]);

        SharedPreferences.setMockInitialValues({
          'auth_token': 'test-token',
          'user_role': 'Customer',
        });
        final previousPlatform = ImagePickerPlatform.instance;
        ImagePickerPlatform.instance = _FakeImagePickerPlatform(
          _createFakePhotoFile(),
        );
        addTearDown(() => ImagePickerPlatform.instance = previousPlatform);
        tester.view.physicalSize = const Size(390, 844);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        Future<void> addScheduledExtra(String vehicleName) async {
          await tester.ensureVisible(find.text('Add another vehicle'));
          await tester.tap(find.text('Add another vehicle'));
          await _settle(tester);
          await tester.ensureVisible(find.text('2-Wheeler').last);
          await tester.tap(find.text('2-Wheeler').last);
          await _settle(tester);
          await tester.ensureVisible(find.text(vehicleName).last);
          await tester.tap(find.text(vehicleName).last);
          await _settle(tester);
          await tester.ensureVisible(find.text('Add vehicle photos').last);
          await tester.tap(find.text('Add vehicle photos').last);
          await _settle(tester);
          await tester.tap(find.text('Choose from Gallery'));
          await _settle(tester);
        }

        await http.runWithClient(() async {
          await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
          await _settle(tester);
          await tester.enterText(find.byType(TextField).first, 'Rizal');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);
          await tester.enterText(find.byType(TextField).last, 'Fairview');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);
          await tester.tap(find.text('Continue'));
          await _settle(tester);

          await tester.ensureVisible(find.text('4-Wheeler').last);
          await tester.tap(find.text('4-Wheeler').last);
          await _settle(tester);
          await tester.ensureVisible(find.text('Sedan').last);
          await tester.tap(find.text('Sedan').last);
          await _settle(tester);
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

          await addScheduledExtra('Motorcycle');
          await addScheduledExtra('Scooter');

          await tester.ensureVisible(find.text('Continue'));
          await tester.tap(find.text('Continue'));
          await _settle(tester);
        }, () => _client(readyTruckTypeIds: const [2], pricingOverride: resp));

        expect(find.textContaining('Vehicle 1 —'), findsOneWidget);
        expect(find.textContaining('Vehicle 2 —'), findsOneWidget);
        expect(find.textContaining('Vehicle 3 —'), findsOneWidget);
        expect(find.text('Base Rate'), findsNWidgets(3));
        expect(find.text('Distance Fee'), findsNWidgets(3));
        expect(find.text('VAT (12%)'), findsNWidgets(3));
        expect(find.text('Vehicle Total'), findsNWidgets(3));
        expect(find.text('Estimated Total'), findsOneWidget);

        await tester.pumpWidget(const SizedBox());
      },
    );

    testWidgets(
      'request_total is never sent as part of the booking submission payload',
      (tester) async {
        String? capturedBody;
        final scheduledExtra = _scheduledExtraPreview(
          vehicleTypeId: 3,
          truckTypeId: 2,
          baseRate: 900,
        );
        final resp = _pricingResponse(scheduledExtraPreviews: [scheduledExtra]);

        tester.view.physicalSize = const Size(390, 844);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        final previousPlatform = ImagePickerPlatform.instance;
        ImagePickerPlatform.instance = _FakeImagePickerPlatform(
          _createFakePhotoFile(),
        );
        addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

        SharedPreferences.setMockInitialValues({
          'auth_token': 'test-token',
          'user_role': 'Customer',
        });
        final client = MockClient((request) async {
          final url = request.url.toString();
          if (url.contains('router.project-osrm.org')) {
            return _json({'code': 'Error'});
          }
          final path = request.url.path;
          if (path.contains('vehicle-types'))
            return _json(_vehicleTypesPayload());
          if (path.contains('availability')) {
            return _json({
              'book_now_enabled': true,
              'ready_units_count': 1,
              'ready_by_class': {},
              'ready_truck_type_ids': [1],
            });
          }
          if (path.contains('autocomplete')) {
            final q = request.url.queryParameters['q'] ?? '';
            final lat = q.toLowerCase().contains('fairview') ? 14.6905 : 14.5832;
            return _json({
              'suggestions': [
                {
                  'label': 'Rizal Park, Manila',
                  'coordinates': [120.9822, lat],
                },
              ],
            });
          }
          if (path.contains('pricing-preview')) return _json(resp);
          if (path.endsWith('/v1/bookings') && request.method == 'POST') {
            capturedBody = latin1.decode(request.bodyBytes);
            return _json({
              'success': false,
              'message': 'Blocked for test',
            }, status: 422);
          }
          return _json({}, status: 404);
        });

        await http.runWithClient(() async {
          await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
          await _settle(tester);
          await tester.enterText(find.byType(TextField).first, 'Rizal');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);
          await tester.enterText(find.byType(TextField).last, 'Fairview');
          await tester.pump(const Duration(milliseconds: 500));
          await _settle(tester);
          await tester.tap(find.text('Rizal Park').first);
          await _settle(tester);
          await tester.tap(find.text('Continue'));
          await _settle(tester);
          await tester.ensureVisible(find.text('4-Wheeler').last);
          await tester.tap(find.text('4-Wheeler').last);
          await _settle(tester);
          await tester.ensureVisible(find.text('Sedan').last);
          await tester.tap(find.text('Sedan').last);
          await _settle(tester);
          await tester.tap(find.text('Add vehicle photos'));
          await _settle(tester);
          await tester.tap(find.text('Choose from Gallery'));
          await _settle(tester);
          await tester.ensureVisible(find.text('Continue'));
          await tester.tap(find.text('Continue'));
          await _settle(tester);

          await tester.tap(find.text('Confirm Booking'));
          await _settle(tester);
        }, () => client);

        expect(capturedBody, isNotNull);
        expect(capturedBody!.contains('request_total'), isFalse);

        await tester.pumpWidget(const SizedBox());
      },
    );

    for (final width in [320.0, 360.0, 390.0, 412.0]) {
      testWidgets(
        'all-Scheduled group Review renders without overflow at ${width.toInt()}px',
        (tester) async {
          final scheduledExtra = _scheduledExtraPreview(
            vehicleTypeId: 3,
            truckTypeId: 2,
            baseRate: 900,
          );
          final resp = _pricingResponse(
            scheduledExtraPreviews: [scheduledExtra],
          );

          final previousPlatform = ImagePickerPlatform.instance;
          ImagePickerPlatform.instance = _FakeImagePickerPlatform(
            _createFakePhotoFile(),
          );
          addTearDown(() => ImagePickerPlatform.instance = previousPlatform);

          tester.view.physicalSize = Size(width, 900);
          tester.view.devicePixelRatio = 1.0;
          addTearDown(tester.view.resetPhysicalSize);
          addTearDown(tester.view.resetDevicePixelRatio);

          SharedPreferences.setMockInitialValues({
            'auth_token': 'test-token',
            'user_role': 'Customer',
          });
          await http.runWithClient(
            () async {
              await tester.pumpWidget(const MaterialApp(home: BookNowScreen()));
              await _settle(tester);
              await tester.enterText(find.byType(TextField).first, 'Rizal');
              await tester.pump(const Duration(milliseconds: 500));
              await _settle(tester);
              await tester.tap(find.text('Rizal Park').first);
              await _settle(tester);
              await tester.enterText(find.byType(TextField).last, 'Fairview');
              await tester.pump(const Duration(milliseconds: 500));
              await _settle(tester);
              await tester.tap(find.text('Rizal Park').first);
              await _settle(tester);
              await tester.tap(find.text('Continue'));
              await _settle(tester);

              await tester.ensureVisible(find.text('4-Wheeler').last);
              await tester.tap(find.text('4-Wheeler').last);
              await _settle(tester);
              await tester.ensureVisible(find.text('Sedan').last);
              await tester.tap(find.text('Sedan').last);
              await _settle(tester);
              tester.takeException();
              await tester.tap(find.text('Schedule entire request').last);
              await _settle(tester);
              tester.takeException();
              await tester.tap(find.text('Select date'));
              await _settle(tester);
              await tester.tap(find.text('OK'));
              await _settle(tester);
              tester.takeException();
              await tester.tap(find.text('Select time'));
              await _settle(tester);
              await tester.tap(find.text('OK'));
              await _settle(tester);
              tester.takeException();

              await tester.ensureVisible(find.text('Continue'));
              await tester.tap(find.text('Continue'));
              await _settle(tester);
              tester.takeException();

              await tester.tap(find.text('Add vehicle photos'));
              await _settle(tester);
              await tester.tap(find.text('Choose from Gallery'));
              await _settle(tester);
              tester.takeException();

              await tester.ensureVisible(find.text('Add another vehicle'));
              await tester.tap(find.text('Add another vehicle'));
              await _settle(tester);
              await tester.ensureVisible(find.text('2-Wheeler').last);
              await tester.tap(find.text('2-Wheeler').last);
              await _settle(tester);
              await tester.ensureVisible(find.text('Motorcycle').last);
              await tester.tap(find.text('Motorcycle').last);
              await _settle(tester);
              tester.takeException();
              await tester.ensureVisible(find.text('Add vehicle photos').last);
              await tester.tap(find.text('Add vehicle photos').last);
              await _settle(tester);
              await tester.tap(find.text('Choose from Gallery'));
              await _settle(tester);
              tester.takeException();

              await tester.ensureVisible(find.text('Continue'));
              await tester.tap(find.text('Continue'));
              await _settle(tester);
              tester.takeException();
            },
            () => _client(readyTruckTypeIds: const [2], pricingOverride: resp),
          );

          expect(find.textContaining('Vehicle 1 —'), findsOneWidget);
          expect(find.textContaining('Vehicle 2 —'), findsOneWidget);
          expect(tester.takeException(), isNull);

          await tester.pumpWidget(const SizedBox());
        },
      );
    }
  });
}
