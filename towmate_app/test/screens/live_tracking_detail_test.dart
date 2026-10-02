import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/customer/booking_detail_screen.dart';
import 'package:towmate_app/services/live_tracking_controller.dart';

http.Response _json(Object body, {int status = 200}) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});

Map<String, dynamic> _detail({
  String code = 'TM-00225',
  String status = 'on_the_way',
  String? groupCode,
  List<Map<String, dynamic>>? groupVehicles,
}) =>
    {
      'success': true,
      'data': {
        'booking_code': code,
        'status': status,
        'service_type': 'book_now',
        'pickup_address': 'Sumilang Street, Pasig',
        'pickup_lat': 14.5,
        'pickup_lng': 121.0,
        'dropoff_address': 'Quirino Highway, QC',
        'dropoff_lat': 14.6,
        'dropoff_lng': 121.1,
        'distance_km': 6.0,
        'truck_type_name': 'Light Duty',
        'vehicle_type_name': 'Sedan',
        'base_rate': 2500.0,
        'per_km_rate': 60.0,
        'distance_fee': 0.0,
        'computed_total': 2500.0,
        'vat_amount': 300.0,
        'final_total': 2800.0,
        'created_at': '2026-09-01 10:00:00',
        'price_history': [],
        'group_code': groupCode,
        'group_booking_code': code,
        'group_siblings': [],
        'group_vehicles': groupVehicles ?? [],
        'group_totals': groupCode == null
            ? null
            : {
                'vehicle_count': (groupVehicles ?? []).length,
                'base_rate': 4000.0,
                'computed_total': 4000.0,
                'vat_amount': 480.0,
                'additional_fee': 0.0,
                'final_total': 4480.0,
              },
      },
    };

Map<String, dynamic> _tracking(String code, {String status = 'on_the_way', bool tracking = true, String freshness = 'live'}) => {
      'success': true,
      'data': {
        'tracking': tracking,
        'booking_code': code,
        'status': status,
        'phase': tracking ? (status == 'on_job' ? 'dropoff' : 'pickup') : null,
        'freshness': tracking ? freshness : null,
        'location': tracking && freshness != 'unavailable'
            ? {'lat': 14.55, 'lng': 121.02, 'accuracy': 5.0, 'age_seconds': 4}
            : null,
        'last_seen': null,
        'destination': tracking ? {'lat': 14.5, 'lng': 121.0} : null,
      },
    };

final Map<String, dynamic> _osrm = {
  'code': 'Ok',
  'routes': [
    {
      'distance': 2100,
      'duration': 420,
      'geometry': {
        'coordinates': [
          [121.02, 14.55],
          [121.0, 14.5],
        ],
      },
    },
  ],
};

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 12; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> details,
  required Map<String, Map<String, dynamic>> tracking,
  String code = 'TM-00225',
  bool asGroupOverview = false,
  List<String>? trackingLog,
  void Function(String route, Object? args)? onNavigate,
  Future<void> Function()? body,
}) async {
  tester.view.physicalSize = const Size(400, 2600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  SharedPreferences.setMockInitialValues({'auth_token': 'test-token', 'user_role': 'Customer'});
  var detailCalls = 0;
  final client = MockClient((request) async {
    final path = request.url.path;
    if (path.contains('/route/v1/driving/')) return _json(_osrm);
    if (path.endsWith('/tracking')) {
      final segs = path.split('/');
      final c = segs[segs.length - 2];
      trackingLog?.add(c);
      final t = tracking[c];
      return t == null ? _json({'success': false}, status: 404) : _json(t);
    }
    if (path.contains('/detail')) {
      final i = detailCalls < details.length ? detailCalls : details.length - 1;
      detailCalls++;
      return _json(details[i]);
    }
    return _json({'success': false}, status: 404);
  });
  await http.runWithClient(() async {
    await tester.pumpWidget(MaterialApp(
      home: BookingDetailScreen(bookingCode: code, asGroupOverview: asGroupOverview),
      onGenerateRoute: (settings) {
        onNavigate?.call(settings.name ?? '', settings.arguments);
        return MaterialPageRoute(builder: (_) => const Scaffold());
      },
    ));
    await _settle(tester);
    if (body != null) await body();
  }, () => client);
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  tearDown(() => LiveTrackingRegistry.instance.resetForTest());

  group('Booking Details — single booking Live Tracking card', () {
    testWidgets('on_the_way: card directly after the status card with ETA, distance and Track tow truck', (tester) async {
      String? route;
      Object? args;
      await _pump(
        tester,
        details: [_detail()],
        tracking: {'TM-00225': _tracking('TM-00225')},
        onNavigate: (r, a) {
          route = r;
          args = a;
        },
      );

      final card = find.byKey(const ValueKey('detail-live-tracking-TM-00225'));
      expect(card, findsOneWidget);
      expect(find.text('ETA TO PICKUP'), findsOneWidget);
      expect(find.text('DISTANCE'), findsOneWidget);
      expect(find.text('7 min'), findsOneWidget);
      expect(find.text('2.1 km'), findsOneWidget);
      expect(find.text('Track tow truck'), findsOneWidget);

      // Placed before the total amount (i.e. right after the status card).
      expect(tester.getTopLeft(card).dy, lessThan(tester.getTopLeft(find.text('Total Amount')).dy));

      await tester.tap(find.text('Track tow truck'));
      await _settle(tester);
      expect(route, '/live-tracking');
      expect(args, 'TM-00225');
    });

    testWidgets('on_job: button says Track vehicle and ETA is to drop-off', (tester) async {
      await _pump(
        tester,
        details: [_detail(status: 'on_job')],
        tracking: {'TM-00225': _tracking('TM-00225', status: 'on_job')},
      );
      expect(find.text('Track vehicle'), findsOneWidget);
      expect(find.text('ETA TO DROP-OFF'), findsOneWidget);
    });

    for (final status in ['requested', 'assigned', 'arrived_pickup', 'in_progress', 'completed', 'cancelled']) {
      testWidgets('$status: no Live Tracking card and no tracking requests', (tester) async {
        final log = <String>[];
        await _pump(
          tester,
          details: [_detail(status: status)],
          tracking: {'TM-00225': _tracking('TM-00225')},
          trackingLog: log,
        );
        expect(find.byKey(const ValueKey('detail-live-tracking-TM-00225')), findsNothing);
        expect(find.text('Track tow truck'), findsNothing);
        expect(log, isEmpty);
      });
    }

    testWidgets('unavailable: no ETA/distance, calm unavailable notice', (tester) async {
      await _pump(
        tester,
        details: [_detail()],
        tracking: {'TM-00225': _tracking('TM-00225', freshness: 'unavailable')},
      );
      expect(find.text('Live location temporarily unavailable'), findsOneWidget);
      expect(find.byKey(const ValueKey('tracking-eta')), findsNothing);
      expect(find.byKey(const ValueKey('tracking-distance')), findsNothing);
    });

    testWidgets('when tracking ends, the card disappears and the booking refreshes to its new status', (tester) async {
      await _pump(
        tester,
        details: [_detail(), _detail(status: 'in_progress')],
        tracking: {'TM-00225': _tracking('TM-00225', tracking: false, status: 'in_progress')},
      );
      expect(find.byKey(const ValueKey('detail-live-tracking-TM-00225')), findsNothing);
      expect(find.text('Towing in progress'), findsWidgets);
    });
  });

  group('Booking Details — group overview LIVE TRACKING', () {
    testWidgets('one row per trackable booking_code, after the group status card, above the unchanged vehicles list', (tester) async {
      final log = <String>[];
      await _pump(
        tester,
        code: 'TM-00227',
        asGroupOverview: true,
        trackingLog: log,
        details: [
          _detail(code: 'TM-00227', status: 'requested', groupCode: 'GRP-1', groupVehicles: [
            {'booking_code': 'TM-00229', 'status': 'on_job', 'vehicle_type_name': 'Van', 'final_total': 1500.0},
            {'booking_code': 'TM-00227', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
            {'booking_code': 'TM-00228', 'status': 'on_the_way', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
          ]),
        ],
        tracking: {
          'TM-00228': _tracking('TM-00228'),
          'TM-00229': _tracking('TM-00229', status: 'on_job'),
        },
      );

      expect(find.text('LIVE TRACKING'), findsOneWidget);
      expect(find.byKey(const ValueKey('detail-live-tracking-TM-00228')), findsOneWidget);
      expect(find.byKey(const ValueKey('detail-live-tracking-TM-00229')), findsOneWidget);
      expect(find.byKey(const ValueKey('detail-live-tracking-TM-00227')), findsNothing);
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('detail-live-tracking-TM-00228')),
          matching: find.text('Vehicle 2 · Motorcycle'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('detail-live-tracking-TM-00229')),
          matching: find.text('Vehicle 3 · Van'),
        ),
        findsOneWidget,
      );
      expect(find.text('Track tow truck'), findsOneWidget);
      expect(find.text('Track vehicle'), findsOneWidget);
      expect(log.toSet(), {'TM-00228', 'TM-00229'});

      final liveY = tester.getTopLeft(find.text('LIVE TRACKING')).dy;
      expect(liveY, greaterThan(tester.getTopLeft(find.text('Group Request')).dy));
      expect(find.text('VEHICLES IN THIS GROUP'), findsOneWidget);
      expect(liveY, lessThan(tester.getTopLeft(find.text('VEHICLES IN THIS GROUP')).dy));
    });

    testWidgets('no trackable vehicle → no LIVE TRACKING section', (tester) async {
      await _pump(
        tester,
        code: 'TM-00227',
        asGroupOverview: true,
        details: [
          _detail(code: 'TM-00227', status: 'requested', groupCode: 'GRP-1', groupVehicles: [
            {'booking_code': 'TM-00227', 'status': 'requested', 'vehicle_type_name': 'Sedan', 'final_total': 1680.0},
            {'booking_code': 'TM-00228', 'status': 'assigned', 'vehicle_type_name': 'Motorcycle', 'final_total': 1120.0},
          ]),
        ],
        tracking: const {},
      );
      expect(find.text('LIVE TRACKING'), findsNothing);
    });
  });
}
