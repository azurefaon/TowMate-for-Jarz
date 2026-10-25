import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/services/tl_navigation_session.dart';

class FakeNavigationDriver implements TlNavigationDriver {
  FakeNavigationDriver({this.initialized = false, this.termsAccepted = true});

  bool initialized;
  bool termsAccepted;
  final List<String> calls = [];

  @override
  Future<bool> isInitialized() async {
    calls.add('isInitialized');
    return initialized;
  }

  @override
  Future<bool> areTermsAccepted() async {
    calls.add('areTermsAccepted');
    return termsAccepted;
  }

  @override
  Future<void> initialize() async {
    calls.add('initialize');
    initialized = true;
  }

  @override
  Future<void> stop() async {
    calls.add('stop');
    initialized = false;
  }
}

void main() {
  late FakeNavigationDriver driver;

  setUp(() {
    TlNavigationSession.resetForTest();
    driver = FakeNavigationDriver();
    TlNavigationSession.driver = driver;
  });

  tearDown(TlNavigationSession.resetForTest);

  group('phases', () {
    test('only the two driving legs are navigation phases', () {
      const driving = {'accepted': 'pickup', 'on_the_way': 'pickup', 'on_job': 'dropoff'};
      const notDriving = [
        'assigned', 'arrived_pickup', 'in_progress', 'loading_vehicle',
        'arrived_dropoff', 'waiting_verification', 'completed', 'returned',
      ];
      driving.forEach((status, leg) {
        expect(TlNavigationSession.isNavigationPhase(status), isTrue, reason: status);
        expect(TlNavigationSession.legFor(status), leg, reason: status);
      });
      for (final status in notDriving) {
        expect(TlNavigationSession.isNavigationPhase(status), isFalse, reason: status);
        expect(TlNavigationSession.legFor(status), isNull, reason: status);
      }
    });
  });

  group('stopping', () {
    test('ensureStopped stops a running session', () async {
      driver.initialized = true;
      await TlNavigationSession.ensureStopped();
      expect(driver.calls, ['isInitialized', 'stop']);
      expect(driver.initialized, isFalse);
    });

    test('ensureStopped re-attaches to and stops a session left by a previous run', () async {
      await TlNavigationSession.ensureStopped();
      expect(driver.calls, ['isInitialized', 'areTermsAccepted', 'initialize', 'stop']);
      expect(driver.initialized, isFalse);
    });

    test('ensureStopped touches nothing when navigation was never used', () async {
      driver.termsAccepted = false;
      await TlNavigationSession.ensureStopped();
      expect(driver.calls, ['isInitialized', 'areTermsAccepted']);
    });

    test('ensureStopped leaves an open Navigate screen alone', () async {
      final screen = Object();
      TlNavigationSession.claim(screen);
      driver.initialized = true;
      await TlNavigationSession.ensureStopped();
      expect(driver.calls, isEmpty);
      expect(driver.initialized, isTrue);
    });

    test('release is idempotent and ignores non-owners', () async {
      final screen = Object();
      TlNavigationSession.claim(screen);
      driver.initialized = true;

      await TlNavigationSession.release(Object());
      expect(driver.calls, isEmpty);

      await TlNavigationSession.release(screen);
      await TlNavigationSession.release(screen);
      expect(driver.calls.where((c) => c == 'stop'), hasLength(1));
      expect(TlNavigationSession.hasOwner, isFalse);
    });

    test('a driver failure never escapes a stop', () async {
      TlNavigationSession.driver = _ThrowingDriver();
      await expectLater(TlNavigationSession.ensureStopped(), completes);
    });
  });

  group('ordering', () {
    test('a stop queued during an in-flight start runs after it, so guidance ends stopped', () async {
      final screen = Object();
      TlNavigationSession.claim(screen);
      final startGate = Completer<void>();
      final events = <String>[];

      final start = TlNavigationSession.runFor(screen, () async {
        events.add('start-begin');
        await startGate.future;
        driver.initialized = true;
        events.add('start-end');
      });
      await Future<void>.delayed(Duration.zero);

      final stop = TlNavigationSession.release(screen);
      await Future<void>.delayed(Duration.zero);
      expect(driver.calls, isEmpty, reason: 'stop must wait for the start');

      startGate.complete();
      expect(await start, isTrue);
      await stop;

      expect(events, ['start-begin', 'start-end']);
      expect(driver.calls, ['isInitialized', 'stop']);
      expect(driver.initialized, isFalse);
    });

    test('a start that was queued before release but not yet run is skipped', () async {
      final screen = Object();
      TlNavigationSession.claim(screen);
      var ran = false;
      final start = TlNavigationSession.runFor(screen, () async => ran = true);
      unawaited(TlNavigationSession.release(screen));

      expect(await start, isFalse);
      expect(ran, isFalse);
    });

    test('a start queued after its screen closed is skipped', () async {
      final screen = Object();
      TlNavigationSession.claim(screen);
      final gate = Completer<void>();
      final first = TlNavigationSession.runFor(screen, () => gate.future);
      await Future<void>.delayed(Duration.zero); // `first` is now in flight

      var lateStartRan = false;
      final late = TlNavigationSession.runFor(screen, () async => lateStartRan = true);
      unawaited(TlNavigationSession.release(screen));
      gate.complete();

      expect(await first, isTrue);
      // `late` was queued before release() but runs after the owner is gone.
      expect(await late, isFalse);
      expect(lateStartRan, isFalse);
    });
  });
}

class _ThrowingDriver implements TlNavigationDriver {
  @override
  Future<bool> isInitialized() async => throw StateError('native failure');
  @override
  Future<bool> areTermsAccepted() async => throw StateError('native failure');
  @override
  Future<void> initialize() async => throw StateError('native failure');
  @override
  Future<void> stop() async => throw StateError('native failure');
}
