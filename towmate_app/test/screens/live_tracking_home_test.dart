import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/route_observer.dart';
import 'package:towmate_app/screens/customer/home_screen.dart';
import 'package:towmate_app/services/live_tracking_controller.dart';

http.Response _json(Object body, {int status = 200}) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});

Map<String, dynamic> _booking({String code = 'TM-0001', String status = 'on_the_way', Map<String, dynamic> extra = const {}}) => {
      'id': 1,
      'booking_code': code,
      'status': status,
      'pickup_address': '123 Main St',
      'dropoff_address': '456 Side St',
      'distance_km': 5.2,
      'computed_total': 850.0,
      'vehicle_type_name': 'SUV',
      ...extra,
    };

Map<String, dynamic> tracking(
  String code, {
  bool tracking = true,
  String status = 'on_the_way',
  String freshness = 'live',
  int age = 5,
  double lat = 14.60,
  double lng = 121.00,
  int? lastSeen,
}) {
  final phase = status == 'on_job' ? 'dropoff' : 'pickup';
  final hasLoc = tracking && freshness != 'unavailable';
  return {
    'success': true,
    'data': {
      'tracking': tracking,
      'booking_code': code,
      'status': status,
      'phase': tracking ? phase : null,
      'freshness': tracking ? freshness : null,
      'location': hasLoc ? {'lat': lat, 'lng': lng, 'accuracy': 8.0, 'age_seconds': age} : null,
      'last_seen': lastSeen != null ? {'age_seconds': lastSeen} : null,
      'destination': tracking
          ? (phase == 'pickup' ? {'lat': 14.65, 'lng': 121.05} : {'lat': 14.55, 'lng': 121.02})
          : null,
    },
  };
}

/// OSRM-shaped route: 3.4 km, 10 min.
final Map<String, dynamic> _osrm = {
  'code': 'Ok',
  'routes': [
    {
      'distance': 3400,
      'duration': 600,
      'geometry': {
        'coordinates': [
          [121.00, 14.60],
          [121.03, 14.63],
          [121.05, 14.65],
        ],
      },
    },
  ],
};

class _Backend {
  _Backend({required this.current, Map<String, Map<String, dynamic>>? trackingByCode})
      : trackingByCode = trackingByCode ?? {};

  Object? current;
  final Map<String, Map<String, dynamic>> trackingByCode;
  final List<String> trackingRequests = [];

  http.Client client() => MockClient((request) async {
        final path = request.url.path;
        if (path.contains('/route/v1/driving/')) return _json(_osrm);
        if (path.endsWith('/tracking')) {
          final code = Uri.decodeComponent(path.split('/')[path.split('/').length - 2]);
          trackingRequests.add(code);
          final body = trackingByCode[code];
          return body == null ? _json({'success': false}, status: 404) : _json(body);
        }
        if (path.endsWith('/v1/bookings/current')) return _json({'data': current});
        if (path.endsWith('/v1/quotations/pending')) return _json({'data': null});
        if (path.endsWith('/v1/customer/content')) return _json({'announcement': null, 'services': []});
        if (path.contains('/vehicle-types/by-category/')) return _json({'vehicleTypes': []});
        if (path.endsWith('/v1/notifications')) return _json({'success': true, 'unread_count': 0, 'data': []});
        return _json({'success': false}, status: 404);
      });
}

Future<void> _settle(WidgetTester tester, [int frames = 12]) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pumpHome(
  WidgetTester tester,
  _Backend backend, {
  void Function(String route, Object? args)? onNavigate,
  Future<void> Function()? body,
}) async {
  tester.view.physicalSize = const Size(390, 2600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  SharedPreferences.setMockInitialValues({'auth_token': 't', 'user_role': 'Customer', 'user_name': 'Faon'});
  await http.runWithClient(() async {
    await tester.pumpWidget(MaterialApp(
      navigatorObservers: [appRouteObserver],
      onGenerateRoute: (settings) {
        if (settings.name == '/' || settings.name == null) {
          return MaterialPageRoute(builder: (_) => const HomeScreen());
        }
        onNavigate?.call(settings.name!, settings.arguments);
        return MaterialPageRoute(builder: (_) => const Scaffold());
      },
    ));
    await _settle(tester);
    if (body != null) await body();
  }, backend.client);
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  tearDown(() => LiveTrackingRegistry.instance.resetForTest());

  group('Home — single booking live tracking', () {
    testWidgets('on_the_way shows ETA TO PICKUP, distance, Live and a Track live action', (tester) async {
      final backend = _Backend(current: _booking(), trackingByCode: {'TM-0001': tracking('TM-0001')});
      String? route;
      Object? args;
      await _pumpHome(tester, backend, onNavigate: (r, a) {
        route = r;
        args = a;
      });

      expect(find.text('TM-0001'), findsOneWidget); // booking code stays the headline
      expect(find.text('ETA TO PICKUP'), findsOneWidget);
      expect(find.text('10 min'), findsOneWidget);
      expect(find.text('3.4 km'), findsOneWidget);
      expect(find.text('Live'), findsOneWidget);
      expect(find.text('Track live'), findsOneWidget);
      expect(find.text('Track'), findsNothing);

      // Block sits between the progress bar and the route box.
      final blockY = tester.getTopLeft(find.byKey(const ValueKey('home-live-tracking-block'))).dy;
      expect(blockY, greaterThan(tester.getTopLeft(find.text('Towing')).dy));
      expect(blockY, lessThan(tester.getTopLeft(find.text('123 Main St')).dy));

      await tester.tap(find.text('Track live'));
      await _settle(tester);
      expect(route, '/live-tracking');
      expect(args, 'TM-0001');
    });

    testWidgets('on_job shows ETA TO DROP-OFF and Track live opens that booking', (tester) async {
      final backend = _Backend(
        current: _booking(code: 'TM-0009', status: 'on_job'),
        trackingByCode: {'TM-0009': tracking('TM-0009', status: 'on_job')},
      );
      Object? args;
      await _pumpHome(tester, backend, onNavigate: (r, a) => args = a);

      expect(find.text('ETA TO DROP-OFF'), findsOneWidget);
      expect(find.text('ETA TO PICKUP'), findsNothing);
      await tester.tap(find.text('Track live'));
      await _settle(tester);
      expect(args, 'TM-0009');
    });

    testWidgets('a non-trackable status keeps today\'s Track behaviour and never polls tracking', (tester) async {
      final backend = _Backend(current: _booking(code: 'TM-0002', status: 'assigned'));
      String? route;
      Object? args;
      await _pumpHome(tester, backend, onNavigate: (r, a) {
        route = r;
        args = a;
      });

      expect(find.text('Track'), findsOneWidget);
      expect(find.text('Track live'), findsNothing);
      expect(find.byKey(const ValueKey('home-live-tracking-block')), findsNothing);
      expect(backend.trackingRequests, isEmpty);

      await tester.tap(find.text('Track'));
      await _settle(tester);
      expect(route, '/booking-detail');
      expect(args, 'TM-0002');
    });

    testWidgets('updating keeps ETA/distance but labels it Updating', (tester) async {
      final backend = _Backend(
        current: _booking(),
        trackingByCode: {'TM-0001': tracking('TM-0001', freshness: 'updating', age: 45)},
      );
      await _pumpHome(tester, backend);

      expect(find.text('Updating'), findsOneWidget);
      expect(find.text('10 min'), findsOneWidget);
      expect(find.textContaining('45s ago'), findsOneWidget);
    });

    testWidgets('unavailable hides ETA and distance and shows the calm unavailable state', (tester) async {
      final backend = _Backend(
        current: _booking(),
        trackingByCode: {'TM-0001': tracking('TM-0001', freshness: 'unavailable', lastSeen: 300)},
      );
      await _pumpHome(tester, backend);

      expect(find.text('Live location temporarily unavailable'), findsOneWidget);
      expect(find.text('Last updated 5 min ago'), findsOneWidget);
      expect(find.byKey(const ValueKey('tracking-eta')), findsNothing);
      expect(find.byKey(const ValueKey('tracking-distance')), findsNothing);
      expect(find.text('10 min'), findsNothing);
      expect(find.text('Track live'), findsOneWidget);
    });

    testWidgets('tracking=false hides the live block, restores Track and refreshes Home', (tester) async {
      final backend = _Backend(
        current: _booking(),
        trackingByCode: {'TM-0001': tracking('TM-0001', tracking: false, status: 'in_progress')},
      );
      await _pumpHome(tester, backend);

      expect(find.byKey(const ValueKey('home-live-tracking-block')), findsNothing);
      expect(find.text('Track live'), findsNothing);
      expect(find.text('Track'), findsOneWidget);
      expect(LiveTrackingRegistry.instance.controllerFor('TM-0001')?.isPolling, isFalse);
    });

    testWidgets('polls about every 10 seconds and stops when Home is disposed', (tester) async {
      final backend = _Backend(current: _booking(), trackingByCode: {'TM-0001': tracking('TM-0001')});
      await _pumpHome(tester, backend, body: () async {
        final before = backend.trackingRequests.length;
        await tester.pump(const Duration(seconds: 10));
        await _settle(tester, 4);
        expect(backend.trackingRequests.length, before + 1);

        await tester.pumpWidget(const SizedBox());
        expect(LiveTrackingRegistry.instance.controllerCount, 0);
        final afterDispose = backend.trackingRequests.length;
        await tester.pump(const Duration(seconds: 30));
        expect(backend.trackingRequests.length, afterDispose);
      });
    });
  });

  group('Home — grouped booking', () {
    Map<String, dynamic> groupBooking() => _booking(code: 'TM-00228', status: 'on_the_way', extra: {
          'group_code': 'GRP-7',
          'vehicle_type_name': 'Motorcycle',
          'group_vehicle_count': 3,
          'group_siblings': [
            {'booking_code': 'TM-00230', 'vehicle_type_name': 'Van / L300', 'service_type': 'book_now', 'status': 'on_job'},
            {'booking_code': 'TM-00227', 'vehicle_type_name': 'Sedan', 'service_type': 'book_now', 'status': 'assigned'},
          ],
        });

    testWidgets('rows are sorted by booking_code and each trackable row has its own Track live', (tester) async {
      final backend = _Backend(current: groupBooking(), trackingByCode: {
        'TM-00228': tracking('TM-00228', lat: 14.61, lng: 121.01),
        'TM-00230': tracking('TM-00230', status: 'on_job', freshness: 'unavailable'),
      });
      final opened = <Object?>[];
      await _pumpHome(tester, backend, onNavigate: (r, a) {
        if (r == '/live-tracking') opened.add(a);
      });

      expect(find.text('GRP-7'), findsOneWidget);
      final y227 = tester.getTopLeft(find.text('TM-00227')).dy;
      final y228 = tester.getTopLeft(find.text('TM-00228')).dy;
      final y230 = tester.getTopLeft(find.text('TM-00230')).dy;
      expect(y227 < y228 && y228 < y230, isTrue);
      expect(find.text('Vehicle 1 · Sedan'), findsOneWidget);
      expect(find.text('Vehicle 2 · Motorcycle'), findsOneWidget);
      expect(find.text('Vehicle 3 · Van / L300'), findsOneWidget);

      // Only the two trackable rows, each with its own controls; no combined ETA.
      expect(find.text('Track live'), findsNWidgets(2));
      expect(find.text('ETA TO PICKUP'), findsOneWidget); // TM-00228 live
      expect(find.text('Live location temporarily unavailable'), findsOneWidget); // TM-00230
      expect(find.textContaining('Track all'), findsNothing);

      // Each controller polled only its own booking_code.
      expect(backend.trackingRequests.toSet(), {'TM-00228', 'TM-00230'});
      expect(LiveTrackingRegistry.instance.controllerFor('TM-00228')!.snapshot!.location!.lat, 14.61);
      expect(LiveTrackingRegistry.instance.controllerFor('TM-00230')!.snapshot!.location, isNull);

      await tester.tap(find.text('Track live').last);
      await _settle(tester);
      expect(opened, ['TM-00230']);
    });

    testWidgets('the group action says View group and opens the group details, never tracking', (tester) async {
      final backend = _Backend(current: groupBooking(), trackingByCode: {
        'TM-00228': tracking('TM-00228'),
        'TM-00230': tracking('TM-00230', status: 'on_job'),
      });
      final routes = <String>[];
      Object? args;
      await _pumpHome(tester, backend, onNavigate: (r, a) {
        routes.add(r);
        args = a;
      });

      expect(find.text('View group'), findsOneWidget);
      await tester.ensureVisible(find.text('View group'));
      await tester.tap(find.text('View group'));
      await _settle(tester);
      expect(routes, ['/booking-detail']);
      expect(args, {'bookingCode': 'TM-00228', 'asGroupOverview': true});
    });
  });
}
