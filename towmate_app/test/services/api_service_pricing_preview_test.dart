import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/services/api_service.dart';

Future<Map<String, dynamic>?> runFetch(
  http.Client client, {
  List<Map<String, dynamic>> extraVehicles = const [],
}) {
  return http.runWithClient(
    () => ApiService.fetchPricingPreview(
      vehicleTypeId: 4,
      pickupLat: 14.5995,
      pickupLng: 120.9842,
      dropoffLat: 14.6905,
      dropoffLng: 120.9842,
      serviceType: 'book_now',
      extraVehicles: extraVehicles,
    ),
    () => client,
  );
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  SharedPreferences.setMockInitialValues({});

  group('ApiService.fetchPricingPreview', () {
    test('returns the server-computed pricing map from a successful response', () async {
      final client = MockClient((request) async {
        return http.Response(
          jsonEncode({
            'route': {'distance_km': 12.4},
            'pricing': {
              'distance_km': 12.4,
              'base_rate': 1500.0,
              'distance_fee': 504.0,
              'vat_amount': 240.48,
              'final_total': 2244.48,
            },
            'scheduled_extra_previews': [],
            'availability': {'book_now_enabled': true},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      });

      final result = await runFetch(client);

      expect(result, isNotNull);
      expect(result!['pricing']['base_rate'], 1500.0);
      expect(result['pricing']['final_total'], 2244.48);
    });

    test('sends vehicle_type_id, coordinates and service_type in the request body', () async {
      late Map<String, dynamic> sentBody;
      final client = MockClient((request) async {
        sentBody = jsonDecode(request.body) as Map<String, dynamic>;
        return http.Response(
          jsonEncode({'pricing': {}}),
          200,
          headers: {'content-type': 'application/json'},
        );
      });

      await runFetch(client);

      expect(sentBody['vehicle_type_id'], 4);
      expect(sentBody['pickup_lat'], 14.5995);
      expect(sentBody['drop_lat'], 14.6905);
      expect(sentBody['service_type'], 'book_now');
      expect(sentBody.containsKey('extra_vehicles'), isFalse);
    });

    test('includes extra_vehicles in the request body when present', () async {
      late Map<String, dynamic> sentBody;
      final client = MockClient((request) async {
        sentBody = jsonDecode(request.body) as Map<String, dynamic>;
        return http.Response(
          jsonEncode({'pricing': {}}),
          200,
          headers: {'content-type': 'application/json'},
        );
      });

      await runFetch(
        client,
        extraVehicles: [
          {'truck_type_id': 7, 'service_type': 'schedule'},
        ],
      );

      expect(sentBody['extra_vehicles'], [
        {'truck_type_id': 7, 'service_type': 'schedule'},
      ]);
    });

    test('returns null on a non-200 response instead of throwing', () async {
      final client = MockClient((request) async {
        return http.Response(
          jsonEncode({'message': 'Validation failed.'}),
          422,
          headers: {'content-type': 'application/json'},
        );
      });

      final result = await runFetch(client);

      expect(result, isNull);
    });

    test('returns null on a network failure instead of throwing', () async {
      final client = MockClient((request) async {
        throw const SocketException('Connection refused');
      });

      final result = await runFetch(client);

      expect(result, isNull);
    });
  });
}
