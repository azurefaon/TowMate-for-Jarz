import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/services/tl_presence_controller.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

void _tlTest(String description, Future<void> Function(WidgetTester) body) {
  testWidgets(description, (tester) async {
    try {
      await body(tester);
    } finally {
      TlPresenceController.stop();
    }
  });
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  setUp(() {
    SharedPreferences.setMockInitialValues({'auth_token': 'tl-token'});
  });

  _tlTest('start pings immediately and repeats every 45 seconds', (tester) async {
    final pings = <String>[];
    final client = MockClient((request) async {
      pings.add(request.url.path);
      return _json({'success': true});
    });

    await http.runWithClient(() async {
      TlPresenceController.start();
      await tester.pump();
      expect(pings.length, 1);

      await tester.pump(const Duration(seconds: 45));
      expect(pings.length, 2);

      await tester.pump(const Duration(seconds: 45));
      expect(pings.length, 3);
    }, () => client);
  });

  _tlTest('exactly one heartbeat exists per session even if start is called again', (tester) async {
    final pings = <String>[];
    final client = MockClient((request) async {
      pings.add(request.url.path);
      return _json({'success': true});
    });

    await http.runWithClient(() async {
      TlPresenceController.start();
      await tester.pump();
      TlPresenceController.start();
      await tester.pump();

      final afterStart = pings.length;
      await tester.pump(const Duration(seconds: 45));
      expect(pings.length - afterStart, 1);
    }, () => client);
  });

  _tlTest('app resume triggers an immediate presence ping', (tester) async {
    final pings = <String>[];
    final client = MockClient((request) async {
      pings.add(request.url.path);
      return _json({'success': true});
    });

    await http.runWithClient(() async {
      TlPresenceController.start();
      await tester.pump();
      final afterStart = pings.length;

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();

      expect(pings.length, afterStart + 1);
    }, () => client);
  });

  _tlTest('repeated resume does not create duplicate periodic timers', (tester) async {
    final pings = <String>[];
    final client = MockClient((request) async {
      pings.add(request.url.path);
      return _json({'success': true});
    });

    await http.runWithClient(() async {
      TlPresenceController.start();
      await tester.pump();

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();

      final afterResumes = pings.length;
      await tester.pump(const Duration(seconds: 45));
      expect(pings.length - afterResumes, 1);
    }, () => client);
  });

  _tlTest('paused preserves the existing away behavior without inventing new semantics', (tester) async {
    final calls = <String>[];
    final client = MockClient((request) async {
      calls.add(request.url.path);
      return _json({'success': true});
    });

    await http.runWithClient(() async {
      TlPresenceController.start();
      await tester.pump();

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await tester.pump();

      expect(calls.any((p) => p.endsWith('/presence/away')), isTrue);
      expect(calls.any((p) => p.endsWith('/presence/offline')), isFalse);
    }, () => client);
  });

  _tlTest('stop cancels the heartbeat and stops reacting to lifecycle changes', (tester) async {
    final pings = <String>[];
    final client = MockClient((request) async {
      pings.add(request.url.path);
      return _json({'success': true});
    });

    await http.runWithClient(() async {
      TlPresenceController.start();
      await tester.pump();
      TlPresenceController.stop();

      final afterStop = pings.length;
      await tester.pump(const Duration(seconds: 45));
      expect(pings.length, afterStop);

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();
      expect(pings.length, afterStop);
    }, () => client);
  });

  _tlTest('a session that never calls start sends no presence traffic, as for a Customer session', (tester) async {
    final pings = <String>[];
    final client = MockClient((request) async {
      pings.add(request.url.path);
      return _json({'success': true});
    });

    await http.runWithClient(() async {
      expect(TlPresenceController.isActive, isFalse);

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();
      await tester.pump(const Duration(seconds: 45));

      expect(pings, isEmpty);
      expect(TlPresenceController.isActive, isFalse);
    }, () => client);
  });

  _tlTest('a non-2xx presence response is handled without throwing', (tester) async {
    final client = MockClient((request) async {
      return _json({'success': false}, status: 401);
    });

    await http.runWithClient(() async {
      TlPresenceController.start();
      await tester.pump();
    }, () => client);

    expect(tester.takeException(), isNull);
  });
}
