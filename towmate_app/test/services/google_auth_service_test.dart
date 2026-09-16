import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/services/google_auth_service.dart';

void main() {
  late String source;

  setUpAll(() {
    source = File('lib/services/google_auth_service.dart').readAsStringSync();
  });

  group('GoogleAuthResult', () {
    test('carries a success outcome together with its id token', () {
      const result = GoogleAuthResult(
        outcome: GoogleAuthOutcome.success,
        idToken: 'a-real-verifiable-token',
      );
      expect(result.outcome, GoogleAuthOutcome.success);
      expect(result.idToken, 'a-real-verifiable-token');
    });

    test('a cancelled or unavailable outcome carries no id token', () {
      const cancelled = GoogleAuthResult(outcome: GoogleAuthOutcome.cancelled);
      const unavailable = GoogleAuthResult(outcome: GoogleAuthOutcome.unavailable);
      expect(cancelled.idToken, isNull);
      expect(unavailable.idToken, isNull);
    });
  });

  group('GoogleAuthService source invariants', () {
    test('a user-cancelled sign-in is distinguished from an unavailable/failed one', () {
      expect(source.contains('GoogleSignInExceptionCode.canceled'), isTrue);
      expect(source.contains('GoogleAuthOutcome.cancelled'), isTrue);
      expect(source.contains('GoogleAuthOutcome.unavailable'), isTrue);
    });

    test('a missing or empty id token is never treated as a successful result', () {
      expect(source.contains('idToken == null || idToken.isEmpty'), isTrue);
    });

    test('initialize is only ever called once per app session', () {
      expect(source.contains('if (_initialized) return;'), isTrue);
      expect(source.contains('_initialized = true;'), isTrue);
    });

    test('no Google client secret or hardcoded credential is embedded in the client', () {
      expect(RegExp(r'client_secret', caseSensitive: false).hasMatch(source), isFalse);
      expect(source.contains('String.fromEnvironment('), isTrue);
    });

    test('serverClientId is never passed on Web, matching the google_sign_in_web contract', () {
      expect(source.contains('kIsWeb'), isTrue);
      expect(source.contains('serverClientId: kIsWeb'), isTrue);
    });

    test('clientId is sourced independently from GOOGLE_CLIENT_ID for Web', () {
      expect(source.contains("String.fromEnvironment('GOOGLE_CLIENT_ID')"), isTrue);
      expect(source.contains("String.fromEnvironment(\n    'GOOGLE_SERVER_CLIENT_ID',\n  )"), isTrue);
    });

    test('Web exposes an explicit initializer and an authentication event stream', () {
      expect(source.contains('static Future<void> ensureInitializedForWeb()'), isTrue);
      expect(source.contains('static Stream<GoogleAuthResult> get webAuthResults'), isTrue);
      expect(source.contains('GoogleSignIn.instance.authenticationEvents'), isTrue);
      expect(source.contains('GoogleSignInAuthenticationEventSignIn'), isTrue);
    });

    test('a missing or empty id token from a web auth event is treated as unavailable, not success', () {
      expect(
        source.contains(
          'if (idToken == null || idToken.isEmpty) {\n            return const GoogleAuthResult(\n              outcome: GoogleAuthOutcome.unavailable,\n            );',
        ),
        isTrue,
      );
    });
  });
}
