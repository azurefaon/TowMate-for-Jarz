import 'dart:async';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:google_sign_in/google_sign_in.dart';

enum GoogleAuthOutcome { success, cancelled, unavailable }

class GoogleAuthResult {
  const GoogleAuthResult({required this.outcome, this.idToken});

  final GoogleAuthOutcome outcome;
  final String? idToken;
}

class GoogleAuthService {
  static const String _serverClientId = String.fromEnvironment(
    'GOOGLE_SERVER_CLIENT_ID',
  );
  static const String _clientId = String.fromEnvironment('GOOGLE_CLIENT_ID');

  static bool _initialized = false;

  static Future<void> _ensureInitialized() async {
    if (_initialized) return;
    await GoogleSignIn.instance.initialize(
      clientId: _clientId.isEmpty ? null : _clientId,
      serverClientId: kIsWeb
          ? null
          : (_serverClientId.isEmpty ? null : _serverClientId),
    );
    _initialized = true;
  }

  static Future<void> ensureInitializedForWeb() => _ensureInitialized();

  static Stream<GoogleAuthResult> get webAuthResults {
    return GoogleSignIn.instance.authenticationEvents
        .where((event) => event is! GoogleSignInAuthenticationEventSignOut)
        .map<GoogleAuthResult>((event) {
          final signIn = event as GoogleSignInAuthenticationEventSignIn;
          final idToken = signIn.user.authentication.idToken;
          if (idToken == null || idToken.isEmpty) {
            return const GoogleAuthResult(
              outcome: GoogleAuthOutcome.unavailable,
            );
          }
          return GoogleAuthResult(
            outcome: GoogleAuthOutcome.success,
            idToken: idToken,
          );
        })
        .transform(
          StreamTransformer<GoogleAuthResult, GoogleAuthResult>.fromHandlers(
            handleError: (error, stackTrace, sink) {
              final cancelled =
                  error is GoogleSignInException &&
                  error.code == GoogleSignInExceptionCode.canceled;
              sink.add(
                GoogleAuthResult(
                  outcome: cancelled
                      ? GoogleAuthOutcome.cancelled
                      : GoogleAuthOutcome.unavailable,
                ),
              );
            },
          ),
        );
  }

  static Future<GoogleAuthResult> signIn() async {
    try {
      await _ensureInitialized();
    } catch (_) {
      return const GoogleAuthResult(outcome: GoogleAuthOutcome.unavailable);
    }

    if (!GoogleSignIn.instance.supportsAuthenticate()) {
      return const GoogleAuthResult(outcome: GoogleAuthOutcome.unavailable);
    }

    try {
      final account = await GoogleSignIn.instance.authenticate();
      final idToken = account.authentication.idToken;

      if (idToken == null || idToken.isEmpty) {
        return const GoogleAuthResult(outcome: GoogleAuthOutcome.unavailable);
      }

      return GoogleAuthResult(
        outcome: GoogleAuthOutcome.success,
        idToken: idToken,
      );
    } on GoogleSignInException catch (e) {
      if (e.code == GoogleSignInExceptionCode.canceled) {
        return const GoogleAuthResult(outcome: GoogleAuthOutcome.cancelled);
      }
      return const GoogleAuthResult(outcome: GoogleAuthOutcome.unavailable);
    } catch (_) {
      return const GoogleAuthResult(outcome: GoogleAuthOutcome.unavailable);
    }
  }

  static Future<void> signOut() async {
    try {
      await GoogleSignIn.instance.signOut();
    } catch (_) {}
  }
}
