import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/customer/live_tracking_screen.dart';
import 'package:towmate_app/services/live_tracking_controller.dart';

http.Response _json(Object body, {int status = 200}) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});

Map<String, dynamic> _tracking({String status = 'on_the_way', bool tracking = true, String freshness = 'live'}) => {
      'success': true,
      'data': {
        'tracking': tracking,
        'booking_code': 'TM-0500',
        'status': status,
        'phase': tracking ? (status == 'on_job' ? 'dropoff' : 'pickup') : null,
        'freshness': tracking ? freshness : null,
        'location': tracking && freshness != 'unavailable'
            ? {'lat': 14.58, 'lng': 121.01, 'accuracy': 6.0, 'age_seconds': 3}
            : null,
        'last_seen': null,
        'destination': tracking
            ? (status == 'on_job' ? {'lat': 14.61, 'lng': 121.10} : {'lat': 14.50, 'lng': 121.00})
            : null,
      },
    };

final Map<String, dynamic> _detail = {
  'success': true,
  'data': {
    'booking_code': 'TM-0500',
    'status': 'on_the_way',
    'pickup_address': 'Sumilang Street, Pasig',
    'pickup_lat': 14.50,
    'pickup_lng': 121.00,
    'dropoff_address': 'Quirino Highway, QC',
    'dropoff_lat': 14.61,
    'dropoff_lng': 121.10,
    'truck_type_name': 'Light Duty',
    'vehicle_type_name': 'Sedan',
  },
};

final Map<String, dynamic> _osrm = {
  'code': 'Ok',
  'routes': [
    {
      'distance': 5200,
      'duration': 900,
      'geometry': {
        'coordinates': [
          [121.01, 14.58],
          [121.005, 14.54],
          [121.00, 14.50],
        ],
      },
    },
  ],
};

class _Server {
  Map<String, dynamic> tracking = _tracking();
  int trackingCalls = 0;
  int routeCalls = 0;

  http.Client client() => MockClient((request) async {
        final path = request.url.path;
        if (path.contains('/route/v1/driving/')) {
          routeCalls++;
          return _json(_osrm);
        }
        if (path.endsWith('/tracking')) {
          trackingCalls++;
          return _json(tracking);
        }
        if (path.endsWith('/detail')) return _json(_detail);
        return _json({'success': false}, status: 404);
      });
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 12; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

GoogleMap _map(WidgetTester tester) => tester.widget<GoogleMap>(find.byType(GoogleMap));

Set<String> _markerIds(WidgetTester tester) => _map(tester).markers.map((m) => m.markerId.value).toSet();

Future<void> _pump(
  WidgetTester tester,
  _Server server, {
  void Function(String route, Object? args)? onNavigate,
  Future<void> Function()? body,
}) async {
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  SharedPreferences.setMockInitialValues({'auth_token': 't', 'user_role': 'Customer'});
  await http.runWithClient(() async {
    await tester.pumpWidget(MaterialApp(
      home: const LiveTrackingScreen(bookingCode: 'TM-0500'),
      onGenerateRoute: (settings) {
        onNavigate?.call(settings.name ?? '', settings.arguments);
        return MaterialPageRoute(builder: (_) => const Scaffold());
      },
    ));
    await _settle(tester);
    if (body != null) await body();
  }, server.client);
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  tearDown(() => LiveTrackingRegistry.instance.resetForTest());

  testWidgets('on_the_way: header, map with truck + pickup + route, bottom card, no bottom navigation', (tester) async {
    final server = _Server();
    await _pump(tester, server);

    expect(find.text('Live Tracking'), findsOneWidget);
    expect(find.text('TM-0500 · Sedan'), findsOneWidget);
    expect(find.text('Tow truck heading to pickup'), findsOneWidget);
    expect(find.text('ETA TO PICKUP'), findsOneWidget);
    expect(find.text('15 min'), findsOneWidget);
    expect(find.text('5.2 km'), findsOneWidget);
    expect(find.text('Live'), findsOneWidget);
    expect(find.text('View booking details'), findsOneWidget);
    expect(find.byType(BottomNavigationBar), findsNothing);
    expect(find.byType(NavigationBar), findsNothing);

    expect(_markerIds(tester), {'truck', 'pickup'});
    expect(_map(tester).polylines.single.points.length, 3);

    // Map takes roughly 55–60% of the body.
    final mapH = tester.getSize(find.byType(GoogleMap)).height;
    final cardH = tester.getSize(find.byKey(const ValueKey('live-tracking-info-card'))).height;
    expect(mapH / (mapH + cardH), closeTo(0.58, 0.03));
  });

  testWidgets('on_job: destination switches to drop-off', (tester) async {
    final server = _Server()..tracking = _tracking(status: 'on_job');
    await _pump(tester, server);

    expect(find.text('Vehicle heading to drop-off'), findsOneWidget);
    expect(find.text('ETA TO DROP-OFF'), findsOneWidget);
    expect(_markerIds(tester), {'truck', 'dropoff'});
  });

  testWidgets('unavailable: destination only — no truck, no route, no ETA/distance', (tester) async {
    final server = _Server()..tracking = _tracking(freshness: 'unavailable');
    await _pump(tester, server);

    expect(find.text('Live location temporarily unavailable'), findsOneWidget);
    expect(_markerIds(tester), {'pickup'});
    expect(_map(tester).polylines, isEmpty);
    expect(find.byKey(const ValueKey('tracking-eta')), findsNothing);
    expect(server.routeCalls, 0);
  });

  testWidgets('tracking=false while open: ended state, truck/route removed, polling stops, screen stays', (tester) async {
    final server = _Server();
    await _pump(tester, server, body: () async {
      expect(_markerIds(tester), contains('truck'));

      server.tracking = _tracking(tracking: false, status: 'in_progress');
      await tester.pump(const Duration(seconds: 10));
      await _settle(tester);

      expect(find.text('Live tracking has ended'), findsOneWidget);
      expect(find.text('View booking details'), findsOneWidget);
      expect(_markerIds(tester).contains('truck'), isFalse);
      expect(_map(tester).polylines, isEmpty);
      expect(find.byType(LiveTrackingScreen), findsOneWidget);

      final calls = server.trackingCalls;
      await tester.pump(const Duration(seconds: 40));
      expect(server.trackingCalls, calls, reason: 'no polling after tracking ended');
    });
  });

  testWidgets('route is not recalculated on every poll', (tester) async {
    final server = _Server();
    await _pump(tester, server, body: () async {
      expect(server.routeCalls, 1);
      for (var i = 0; i < 3; i++) {
        await tester.pump(const Duration(seconds: 10));
        await _settle(tester);
      }
      expect(server.trackingCalls, greaterThanOrEqualTo(4));
      expect(server.routeCalls, 1);
    });
  });

  testWidgets('View booking details opens Booking Details for this booking_code', (tester) async {
    String? route;
    Object? args;
    final server = _Server();
    await _pump(tester, server, onNavigate: (r, a) {
      route = r;
      args = a;
    });

    await tester.tap(find.text('View booking details'));
    await _settle(tester);
    expect(route, '/booking-detail');
    expect(args, 'TM-0500');
  });

  testWidgets('disposing the screen releases the controller and stops polling', (tester) async {
    final server = _Server();
    await _pump(tester, server, body: () async {
      await tester.pumpWidget(const SizedBox());
      expect(LiveTrackingRegistry.instance.controllerCount, 0);
      final calls = server.trackingCalls;
      await tester.pump(const Duration(seconds: 30));
      expect(server.trackingCalls, calls);
    });
  });
}
