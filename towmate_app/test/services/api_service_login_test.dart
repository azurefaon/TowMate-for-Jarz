import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/services/api_service.dart';

Future<Map<String, dynamic>> runLogin(http.Client client) {
  return http.runWithClient(
    () => ApiService.login('customer@example.com', 'CorrectHorse!9Battery', 'csrf'),
    () => client,
  );
}

Future<Map<String, dynamic>> runLoginWithGoogle(http.Client client) {
  return http.runWithClient(
    () => ApiService.loginWithGoogle('a-real-verifiable-id-token', 'csrf'),
    () => client,
  );
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  SharedPreferences.setMockInitialValues({});

  group('ApiService.login', () {
    test('a successful response saves the session and returns success', () async {
      final client = MockClient((request) async {
        return http.Response(
          jsonEncode({
            'success': true,
            'data': {
              'token': '76|abcdef1234567890',
              'user': {
                'id': 89,
                'name': 'Diag Test',
                'email': 'customer@example.com',
                'phone': '+639171234567',
                'role': 'Customer',
                'duty_class': null,
                'must_change_password': false,
              },
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      });

      final result = await runLogin(client);

      expect(result['success'], isTrue);
      expect(result['role'], 'Customer');
      expect(await ApiService.getToken(), '76|abcdef1234567890');
    });

    test('invalid credentials return the generic message without throwing', () async {
      final client = MockClient((request) async {
        return http.Response(
          jsonEncode({'success': false, 'message': 'Invalid credentials.'}),
          401,
          headers: {'content-type': 'application/json'},
        );
      });

      final result = await runLogin(client);

      expect(result['success'], isFalse);
      expect(result['message'], 'Invalid credentials.');
    });

    test('a locked account response is passed through unchanged', () async {
      final client = MockClient((request) async {
        return http.Response(
          jsonEncode({
            'success': false,
            'message': 'Your account was locked due to inactivity. Reset your password to reactivate it.',
          }),
          423,
          headers: {'content-type': 'application/json'},
        );
      });

      final result = await runLogin(client);

      expect(result['success'], isFalse);
      expect(result['message'], contains('locked due to inactivity'));
    });

    test('an inactive account response is passed through unchanged', () async {
      final client = MockClient((request) async {
        return http.Response(
          jsonEncode({'success': false, 'message': 'Account is inactive. Please contact support.'}),
          403,
          headers: {'content-type': 'application/json'},
        );
      });

      final result = await runLogin(client);

      expect(result['success'], isFalse);
      expect(result['message'], contains('inactive'));
    });

    test('a 429 throttle response does not throw and surfaces its message', () async {
      final client = MockClient((request) async {
        return http.Response(
          jsonEncode({'message': 'Too Many Attempts.'}),
          429,
          headers: {'content-type': 'application/json'},
        );
      });

      final result = await runLogin(client);

      expect(result['success'], isFalse);
      expect(result['message'], 'Too Many Attempts.');
    });

    test('an unexpected 500 with a non-JSON body falls back to a generic message, not a crash', () async {
      final client = MockClient((request) async {
        return http.Response('<html>Internal Server Error</html>', 500);
      });

      final result = await runLogin(client);

      expect(result['success'], isFalse);
      expect(result['message'], isNotEmpty);
    });

    test('a network failure returns a concise, non-crashing message', () async {
      final client = MockClient((request) async {
        throw const SocketException('Connection refused');
      });

      final result = await runLogin(client);

      expect(result['success'], isFalse);
      expect(result['message'], contains('reach the server'));
    });

    test('a request timeout returns a concise, non-crashing message', () async {
      final client = MockClient((request) async {
        throw TimeoutException('timed out');
      });

      final result = await runLogin(client);

      expect(result['success'], isFalse);
      expect(result['message'], contains('timed out'));
    });
  });

  group('ApiService.loginWithGoogle', () {
    test('a successful response saves the session and returns success', () async {
      final client = MockClient((request) async {
        return http.Response(
          jsonEncode({
            'success': true,
            'data': {
              'token': '90|googletoken1234567890',
              'user': {
                'id': 12,
                'name': 'Google Customer',
                'email': 'googlecustomer@example.com',
                'phone': '+639171234567',
                'role': 'Customer',
                'duty_class': null,
                'must_change_password': false,
              },
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      });

      final result = await runLoginWithGoogle(client);

      expect(result['success'], isTrue);
      expect(result['needsPhone'], isFalse);
      expect(result['role'], 'Customer');
    });

    test('a first-time Google identity is routed to phone completion, not treated as an error', () async {
      final client = MockClient((request) async {
        return http.Response(
          jsonEncode({
            'success': true,
            'needs_phone': true,
            'completion_token': 'a-completion-token',
            'first_name': 'New',
            'last_name': 'Customer',
          }),
          202,
          headers: {'content-type': 'application/json'},
        );
      });

      final result = await runLoginWithGoogle(client);

      expect(result['success'], isTrue);
      expect(result['needsPhone'], isTrue);
      expect(result['completionToken'], 'a-completion-token');
    });

    test('an existing password-account collision surfaces its real message, not a generic error', () async {
      final client = MockClient((request) async {
        return http.Response(
          jsonEncode({
            'success': false,
            'message': 'An account already exists for this email. Sign in with your password first.',
          }),
          409,
          headers: {'content-type': 'application/json'},
        );
      });

      final result = await runLoginWithGoogle(client);

      expect(result['success'], isFalse);
      expect(result['message'], contains('already exists'));
    });

    test('a browser-level fetch/CORS failure is reported as unreachable, not an unexpected error', () async {
      final client = MockClient((request) async {
        throw http.ClientException('Failed to fetch', request.url);
      });

      final result = await runLoginWithGoogle(client);

      expect(result['success'], isFalse);
      expect(result['message'], contains('reach the server'));
      expect(result['message'], isNot(contains('unexpected error')));
    });
  });
}
