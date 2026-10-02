import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/models/tracking_model.dart';
import 'package:towmate_app/services/live_tracking_controller.dart';

TrackingSnapshot _snap({
  bool tracking = true,
  String freshness = 'live',
  double lat = 14.60,
  double lng = 121.00,
  int age = 5,
  String phase = 'pickup',
}) =>
    TrackingSnapshot.fromJson({
      'tracking': tracking,
      'booking_code': 'TM-1',
      'status': phase == 'pickup' ? 'on_the_way' : 'on_job',
      'phase': tracking ? phase : null,
      'freshness': tracking ? freshness : null,
      'location': {'lat': lat, 'lng': lng, 'accuracy': 5.0, 'age_seconds': age},
      'destination': tracking ? (phase == 'pickup' ? {'lat': 14.65, 'lng': 121.05} : {'lat': 14.50, 'lng': 121.10}) : null,
    });

class _Harness {
  _Harness(this.next);

  TrackingSnapshot? Function() next;
  DateTime now = DateTime(2026, 10, 2, 12);
  int fetches = 0;
  final List<List<double>> routeCalls = [];
  bool routeSucceeds = true;

  LiveTrackingController build({String code = 'TM-1'}) => LiveTrackingController(
        bookingCode: code,
        clock: () => now,
        fetcher: (c) async {
          fetches++;
          return next();
        },
        routeCalculator: (a, b, c, d) async {
          routeCalls.add([a, b, c, d]);
          if (!routeSucceeds) return {'success': false};
          return {
            'success': true,
            'distance_km': 3.4,
            'duration_min': 10.0,
            'coordinates': [
              [a, b],
              [c, d],
            ],
          };
        },
      );

  /// Advance both the fake timers and the injected clock.
  Future<void> advance(WidgetTester tester, Duration d) async {
    now = now.add(d);
    await tester.pump(d);
    await tester.pump();
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  tearDown(() => LiveTrackingRegistry.instance.resetForTest());

  testWidgets('polls immediately, then every 10 s, and stops on stop()/dispose()', (tester) async {
    final h = _Harness(() => _snap());
    final c = h.build();
    c.start();
    await tester.pump();
    expect(h.fetches, 1);
    await h.advance(tester, const Duration(seconds: 10));
    expect(h.fetches, 2);
    await h.advance(tester, const Duration(seconds: 10));
    expect(h.fetches, 3);

    c.stop();
    expect(c.isPolling, isFalse);
    await h.advance(tester, const Duration(seconds: 30));
    expect(h.fetches, 3);

    c.start();
    await tester.pump();
    expect(h.fetches, 4);
    c.dispose();
    await h.advance(tester, const Duration(seconds: 30));
    expect(h.fetches, 4);
  });

  testWidgets('tracking=false ends tracking and stops polling immediately', (tester) async {
    var tracking = true;
    final h = _Harness(() => _snap(tracking: tracking));
    final c = h.build()..start();
    await tester.pump();
    expect(c.ended, isFalse);
    expect(c.etaMinutes, 10.0);

    tracking = false;
    await h.advance(tester, const Duration(seconds: 10));
    expect(c.ended, isTrue);
    expect(c.isPolling, isFalse);
    expect(c.routePoints, isEmpty);
    expect(c.etaMinutes, isNull);
    expect(c.distanceKm, isNull);

    final f = h.fetches;
    await h.advance(tester, const Duration(seconds: 40));
    expect(h.fetches, f);
    c.dispose();
  });

  testWidgets('unavailable: no route, no ETA, no distance, no route request', (tester) async {
    final h = _Harness(() => _snap(freshness: 'unavailable'));
    final c = h.build()..start();
    await tester.pump();
    expect(c.snapshot!.freshness, TrackingFreshness.unavailable);
    expect(c.routePoints, isEmpty);
    expect(c.etaMinutes, isNull);
    expect(c.distanceKm, isNull);
    expect(h.routeCalls, isEmpty);
    c.dispose();
  });

  testWidgets('route is throttled: not every poll; refreshed after ~60 s or a ≥200 m move', (tester) async {
    var lat = 14.60;
    final h = _Harness(() => _snap(lat: lat));
    final c = h.build()..start();
    await tester.pump();
    expect(h.routeCalls.length, 1);
    expect(c.routePoints.length, 2);

    // Small movement, several polls inside 60 s → no new route.
    for (var i = 0; i < 4; i++) {
      lat += 0.0001; // ~11 m
      await h.advance(tester, const Duration(seconds: 10));
    }
    expect(h.routeCalls.length, 1);

    // 60 s since the last route → refresh.
    await h.advance(tester, const Duration(seconds: 20));
    expect(h.routeCalls.length, 2);

    // Big move (~330 m) but within the 30 s min gap → wait.
    lat += 0.003;
    await h.advance(tester, const Duration(seconds: 10));
    expect(h.routeCalls.length, 2);
    // After the min gap, the big move triggers a refresh before 60 s.
    await h.advance(tester, const Duration(seconds: 10));
    await h.advance(tester, const Duration(seconds: 10));
    expect(h.routeCalls.length, 3);
    c.dispose();
  });

  testWidgets('a phase change (new destination) re-routes promptly', (tester) async {
    var phase = 'pickup';
    final h = _Harness(() => _snap(phase: phase));
    final c = h.build()..start();
    await tester.pump();
    expect(h.routeCalls.length, 1);

    phase = 'dropoff';
    await h.advance(tester, const Duration(seconds: 20));
    expect(h.routeCalls.length, 2);
    expect(h.routeCalls.last.sublist(2), [14.50, 121.10]);
    c.dispose();
  });

  testWidgets('router failure: no polyline and no ETA, approximate straight-line distance', (tester) async {
    final h = _Harness(() => _snap())..routeSucceeds = false;
    final c = h.build()..start();
    await tester.pump();
    expect(c.routePoints, isEmpty);
    expect(c.etaMinutes, isNull);
    expect(c.distanceKm, isNotNull);
    expect(c.distanceIsApproximate, isTrue);
    c.dispose();
  });

  testWidgets('failed polls age the last snapshot locally (never shown as live forever)', (tester) async {
    var fail = false;
    final h = _Harness(() => fail ? null : _snap(age: 5));
    final c = h.build()..start();
    await tester.pump();
    expect(c.snapshot!.freshness, TrackingFreshness.live);

    fail = true;
    await h.advance(tester, const Duration(seconds: 30));
    expect(c.snapshot!.freshness, TrackingFreshness.updating);
    await h.advance(tester, const Duration(seconds: 60));
    expect(c.snapshot!.freshness, TrackingFreshness.unavailable);
    expect(c.snapshot!.location, isNull);
    expect(c.routePoints, isEmpty);
    expect(c.etaMinutes, isNull);
    c.dispose();
  });

  group('LiveTrackingRegistry', () {
    late _Harness h;
    setUp(() {
      h = _Harness(() => _snap());
      LiveTrackingRegistry.controllerFactory = (code) => h.build(code: code);
    });

    testWidgets('one controller and one loop per booking_code, shared by all viewers', (tester) async {
      final reg = LiveTrackingRegistry.instance;
      final a = reg.acquire('TM-1');
      final b = reg.acquire('TM-1');
      expect(identical(a.controller, b.controller), isTrue);
      expect(reg.controllerCount, 1);
      await tester.pump();
      expect(h.fetches, 1);
      await h.advance(tester, const Duration(seconds: 10));
      expect(h.fetches, 2);

      a.release();
      expect(reg.controllerCount, 1);
      b.release();
      expect(reg.controllerCount, 0);
      await h.advance(tester, const Duration(seconds: 30));
      expect(h.fetches, 2);
    });

    testWidgets('different booking_codes never share a controller', (tester) async {
      final reg = LiveTrackingRegistry.instance;
      final a = reg.acquire('TM-A');
      final b = reg.acquire('TM-B');
      expect(identical(a.controller, b.controller), isFalse);
      expect(a.controller.bookingCode, 'TM-A');
      expect(b.controller.bookingCode, 'TM-B');
      a.release();
      b.release();
    });

    testWidgets('inactive handles (covered screen) pause polling; reactivation resumes', (tester) async {
      final reg = LiveTrackingRegistry.instance;
      final a = reg.acquire('TM-1');
      await tester.pump();
      a.setActive(false);
      expect(a.controller.isPolling, isFalse);
      final f = h.fetches;
      await h.advance(tester, const Duration(seconds: 30));
      expect(h.fetches, f);
      a.setActive(true);
      expect(a.controller.isPolling, isTrue);
      a.release();
    });

    testWidgets('app backgrounded pauses polling; resumed restarts it', (tester) async {
      final reg = LiveTrackingRegistry.instance;
      final a = reg.acquire('TM-1');
      await tester.pump();
      reg.didChangeAppLifecycleState(AppLifecycleState.paused);
      expect(a.controller.isPolling, isFalse);
      final f = h.fetches;
      await h.advance(tester, const Duration(seconds: 30));
      expect(h.fetches, f);
      reg.didChangeAppLifecycleState(AppLifecycleState.resumed);
      expect(a.controller.isPolling, isTrue);
      await tester.pump();
      expect(h.fetches, f + 1);
      a.release();
    });
  });
}
