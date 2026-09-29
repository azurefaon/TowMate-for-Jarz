import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/services/api_service.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    await ApiService.clearSession();
  });

  group('ApiService.signup', () {
    test('sends accept_terms=true in the registration request body', () async {
      Map<String, dynamic>? sentBody;
      final client = MockClient((request) async {
        sentBody = jsonDecode(request.body) as Map<String, dynamic>;
        return http.Response(
          jsonEncode({'success': true}),
          201,
          headers: {'content-type': 'application/json'},
        );
      });

      await http.runWithClient(
        () => ApiService.signup(
          firstName: 'Terms',
          lastName: 'Tester',
          email: 'terms@gmail.com',
          phone: '+639171234567',
          password: 'ValidPass!2024xy',
          confirmPassword: 'ValidPass!2024xy',
          csrfToken: 'csrf',
          acceptTerms: true,
        ),
        () => client,
      );

      expect(sentBody?['accept_terms'], isTrue);
    });

    test('sends accept_terms=false verbatim when the checkbox was not checked', () async {
      Map<String, dynamic>? sentBody;
      final client = MockClient((request) async {
        sentBody = jsonDecode(request.body) as Map<String, dynamic>;
        return http.Response(
          jsonEncode({'success': false, 'message': 'You must agree to the Terms of Use and Privacy Policy.'}),
          422,
          headers: {'content-type': 'application/json'},
        );
      });

      final result = await http.runWithClient(
        () => ApiService.signup(
          firstName: 'Terms',
          lastName: 'Tester',
          email: 'noterms@gmail.com',
          phone: '+639171234568',
          password: 'ValidPass!2024xy',
          confirmPassword: 'ValidPass!2024xy',
          csrfToken: 'csrf',
          acceptTerms: false,
        ),
        () => client,
      );

      expect(sentBody?['accept_terms'], isFalse);
      expect(result['success'], isFalse);
    });
  });

  group('ApiService.completeGoogleSignup', () {
    test('sends accept_terms=true in the completion request body', () async {
      Map<String, dynamic>? sentBody;
      final client = MockClient((request) async {
        sentBody = jsonDecode(request.body) as Map<String, dynamic>;
        return http.Response(
          jsonEncode({
            'success': true,
            'data': {
              'token': '77|googlecomplete1234567890',
              'user': {
                'id': 55,
                'name': 'Google Customer',
                'email': 'gcustomer@example.com',
                'phone': '+639171234567',
                'role': 'Customer',
                'duty_class': null,
              },
            },
          }),
          201,
          headers: {'content-type': 'application/json'},
        );
      });

      await http.runWithClient(
        () => ApiService.completeGoogleSignup(
          completionToken: 'a-completion-token',
          phone: '+639171234567',
          csrfToken: 'csrf',
          acceptTerms: true,
        ),
        () => client,
      );

      expect(sentBody?['accept_terms'], isTrue);
    });
  });

  group('ApiService.acceptTerms', () {
    test('posts to the accept-terms endpoint with the stored bearer token and returns success', () async {
      SharedPreferences.setMockInitialValues({'auth_token': 'stored-token-123'});
      String? authHeader;
      final client = MockClient((request) async {
        authHeader = request.headers['Authorization'];
        return http.Response(
          jsonEncode({'success': true, 'message': 'Terms accepted.'}),
          200,
          headers: {'content-type': 'application/json'},
        );
      });

      final result = await http.runWithClient(
        () => ApiService.acceptTerms(),
        () => client,
      );

      expect(result['success'], isTrue);
      expect(authHeader, 'Bearer stored-token-123');
    });

    test('an unauthenticated 401 response is surfaced as a failure, not a crash', () async {
      final client = MockClient((request) async {
        return http.Response(
          jsonEncode({'message': 'Unauthenticated.'}),
          401,
          headers: {'content-type': 'application/json'},
        );
      });

      final result = await http.runWithClient(
        () => ApiService.acceptTerms(),
        () => client,
      );

      expect(result['success'], isFalse);
    });

    test('a network failure returns a concise, non-crashing message', () async {
      final client = MockClient((request) async {
        throw const SocketException('Connection refused');
      });

      final result = await http.runWithClient(
        () => ApiService.acceptTerms(),
        () => client,
      );

      expect(result['success'], isFalse);
      expect(result['message'], contains('reach the server'));
    });
  });
}
