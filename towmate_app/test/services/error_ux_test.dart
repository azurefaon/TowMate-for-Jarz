import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/api_error.dart';
import 'package:towmate_app/screens/team_leader/tl_history_screen.dart';
import 'package:towmate_app/services/api_service.dart';
import 'package:towmate_app/services/team_leader_service.dart';

http.Response _json(Object body, int status) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});

Future<T> _with<T>(http.Client client, Future<T> Function() body) =>
    http.runWithClient(body, () => client);

const _generic = "We couldn't complete your request right now. Please try again.";
const _unreachable = 'Unable to connect. Check your internet connection and try again.';
const _timeout = 'The request took too long. Please try again.';

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    await ApiService.clearSession();
    SharedPreferences.setMockInitialValues({'auth_token': 'live-token', 'user_role': 'Team Leader'});
    TeamLeaderService.retryDelay = Duration.zero;
  });

  group('safe backend message policy', () {
    test('rejects framework defaults, raw markup, json and technical wording', () {
      for (final bad in [
        'Unauthenticated.',
        'Forbidden.',
        'Not found.',
        'Server Error',
        'Internal Server Error',
        'Too Many Attempts.',
        'Server error (500).',
        'HTTP 403',
        '<html><body>boom</body></html>',
        '{"message":"x"}',
        'Illuminate\\Database\\QueryException',
        'SQLSTATE[23000]: failure',
        '#0 /var/www/vendor/laravel',
      ]) {
        expect(ApiMessages.isSafe(bad), isFalse, reason: bad);
      }
    });

    test('keeps natural-language business messages', () {
      for (final good in [
        'This task was already accepted.',
        'The reason field is required.',
        'Arrival cannot be simulated.',
        'You can only cancel within 5 minutes of booking.',
      ]) {
        expect(ApiMessages.isSafe(good), isTrue, reason: good);
      }
    });
  });

  group('TeamLeaderService response messages', () {
    test('a 500 never shows a raw status or the word Server error', () async {
      final result = await _with(
        MockClient((_) async => _json({'message': 'Server Error'}, 500)),
        () => TeamLeaderService.acceptTask('TM-1'),
      );

      expect(result['success'], isFalse);
      expect(result['message'], _generic);
      expect(result['message'], isNot(contains('500')));
    });

    test('an html 500 body is not reported as no internet', () async {
      final result = await _with(
        MockClient((_) async => http.Response('<html>Internal Server Error</html>', 500)),
        () => TeamLeaderService.acceptTask('TM-1'),
      );

      expect(result['message'], _generic);
      expect(result['message'], isNot(contains('connect')));
    });

    test('a 403 with the framework default hides raw status and framework text', () async {
      final result = await _with(
        MockClient((_) async => _json({'message': 'Forbidden.'}, 403)),
        () => TeamLeaderService.acceptTask('TM-1'),
      );

      expect(result['message'], "You don't have permission to do this.");
      expect(result['message'], isNot(contains('403')));
      expect(result['message'], isNot(contains('Forbidden')));
    });

    test('Laravel Unauthenticated never reaches the message', () async {
      final result = await _with(
        MockClient((_) async => _json({'message': 'Unauthenticated.'}, 401)),
        () => TeamLeaderService.acceptTask('TM-1'),
      );

      expect(result['message'], isNot(contains('Unauthenticated')));
      expect(result['message'], ApiErrorMessages.sessionEnded);
    });

    test('a useful business 409 message is preserved', () async {
      final result = await _with(
        MockClient((_) async => _json({'success': false, 'message': 'This task was already accepted.'}, 409)),
        () => TeamLeaderService.acceptTask('TM-1'),
      );

      expect(result['message'], 'This task was already accepted.');
    });

    test('a specific 422 validation message is preserved', () async {
      final result = await _with(
        MockClient((_) async => _json({'message': 'The reason field is required.'}, 422)),
        () => TeamLeaderService.acceptTask('TM-1'),
      );

      expect(result['message'], 'The reason field is required.');
    });

    test('a generic 422 falls back to the first field error', () async {
      final result = await _with(
        MockClient((_) async => _json({
              'message': 'The given data was invalid.',
              'errors': {
                'reason': ['The reason field is required.'],
              },
            }, 422)),
        () => TeamLeaderService.acceptTask('TM-1'),
      );

      expect(result['message'], 'The reason field is required.');
    });

    test('a timeout is reported as a timeout', () async {
      final result = await _with(
        MockClient((_) async => throw TimeoutException('slow')),
        () => TeamLeaderService.acceptTask('TM-1'),
      );

      expect(result['message'], _timeout);
    });

    test('an unreachable server is reported as a connectivity failure', () async {
      final result = await _with(
        MockClient((_) async => throw const SocketException('down')),
        () => TeamLeaderService.acceptTask('TM-1'),
      );

      expect(result['message'], _unreachable);
    });

    test('a 5xx is not labeled network or unreachable', () async {
      final result = await _with(
        MockClient((_) async => http.Response('', 503)),
        () => TeamLeaderService.acceptTask('TM-1'),
      );

      expect(result['message'], _generic);
      expect(result['message'], isNot(_unreachable));
      expect(result['message'], isNot(_timeout));
    });

    test('a 429 is not labeled timeout or network', () async {
      final result = await _with(
        MockClient((_) async => _json({'message': 'Too Many Attempts.'}, 429)),
        () => TeamLeaderService.acceptTask('TM-1'),
      );

      expect(result['message'], 'Too many attempts. Please wait a moment and try again.');
    });

    test('a malformed success body is not labeled no internet', () async {
      final result = await _with(
        MockClient((_) async => http.Response('<html>oops</html>', 200)),
        () => TeamLeaderService.acceptTask('TM-1'),
      );

      expect(result['success'], isFalse);
      expect(result['message'], _generic);
    });
  });

  group('Google login classification', () {
    test('a timeout is a transport error with timeout wording', () async {
      final result = await _with(
        MockClient((_) async => throw TimeoutException('slow')),
        () => ApiService.loginWithGoogle('token', 'csrf'),
      );

      expect(result['success'], isFalse);
      expect(result['transport_error'], isTrue);
      expect(result['message'], contains('timed out'));
    });

    test('a 5xx is a transport error with the generic wording', () async {
      final result = await _with(
        MockClient((_) async => _json({'message': 'Server Error'}, 500)),
        () => ApiService.loginWithGoogle('token', 'csrf'),
      );

      expect(result['transport_error'], isTrue);
      expect(result['message'], _generic);
    });

    test('a 429 is a transport error with the rate limit wording', () async {
      final result = await _with(
        MockClient((_) async => _json({'message': 'Too Many Attempts.'}, 429)),
        () => ApiService.loginWithGoogle('token', 'csrf'),
      );

      expect(result['transport_error'], isTrue);
      expect(result['message'], 'Too many attempts. Please wait a moment and try again.');
    });

    test('malformed html is not reported as no internet', () async {
      final result = await _with(
        MockClient((_) async => http.Response('<html>bad gateway</html>', 502)),
        () => ApiService.loginWithGoogle('token', 'csrf'),
      );

      expect(result['message'], _generic);
    });

    test('a genuine Google 401 keeps its business message and does not end an existing session', () async {
      final result = await _with(
        MockClient((_) async => _json({'message': 'This Google account could not be verified.'}, 401)),
        () => ApiService.loginWithGoogle('token', 'csrf'),
      );

      expect(result['message'], 'This Google account could not be verified.');
      expect(await ApiService.getToken(), 'live-token');
    });
  });

  group('profile image 422', () {
    final image = XFile.fromData(Uint8List.fromList([1, 2, 3]), name: 'photo.jpg', path: 'photo.jpg', mimeType: 'image/jpeg');

    test('a size violation gets the size-specific wording', () async {
      final result = await _with(
        MockClient((_) async => _json({
              'message': 'The profile image field must not be greater than 5120 kilobytes.',
              'errors': {
                'profile_image': ['The profile image field must not be greater than 5120 kilobytes.'],
              },
            }, 422)),
        () => ApiService.updateProfileImage(image),
      );

      expect(result['success'], isFalse);
      expect(result['message'], contains('5 MB'));
    });

    test('a non-size 422 never claims the file exceeded 5 MB', () async {
      final result = await _with(
        MockClient((_) async => _json({
              'message': 'The profile image field must be an image.',
              'errors': {
                'profile_image': ['The profile image field must be an image.'],
              },
            }, 422)),
        () => ApiService.updateProfileImage(image),
      );

      expect(result['message'], isNot(contains('5 MB')));
      expect(result['message'], 'The profile image field must be an image.');
    });

    test('an unusable 422 falls back to the generic validation wording', () async {
      final result = await _with(
        MockClient((_) async => _json({'message': 'The given data was invalid.'}, 422)),
        () => ApiService.updateProfileImage(image),
      );

      expect(result['message'], 'Please check the information you entered.');
    });
  });

  group('getCurrentTask retry policy', () {
    int calls = 0;

    Future<Object?> run(http.Client client) async {
      try {
        return await _with(client, TeamLeaderService.getCurrentTask);
      } catch (e) {
        return e;
      }
    }

    setUp(() => calls = 0);

    for (final status in [401, 403, 404, 409, 422, 429]) {
      test('$status is not retried', () async {
        final error = await run(MockClient((_) async {
          calls++;
          return _json({'message': 'x'}, status);
        }));

        expect(calls, 1);
        expect(error, isA<ApiException>());
        expect(error.toString(), isNot(contains('$status')));
        expect(error.toString(), isNot(contains('Exception')));
      });
    }

    test('a malformed success body is not retried', () async {
      final error = await run(MockClient((_) async {
        calls++;
        return http.Response('<html>oops</html>', 200);
      }));

      expect(calls, 1);
      expect(error, isA<ApiException>());
    });

    test('a timeout is still retried and can recover', () async {
      final result = await run(MockClient((_) async {
        calls++;
        if (calls < 3) throw TimeoutException('slow');
        return _json({'data': null}, 200);
      }));

      expect(calls, 3);
      expect(result, isNull);
    });

    test('an unreachable server is retried then reported', () async {
      final error = await run(MockClient((_) async {
        calls++;
        throw const SocketException('down');
      }));

      expect(calls, 3);
      expect((error as ApiException).kind, ApiErrorKind.unreachable);
    });

    test('a 503 is retried and then reported with the generic wording', () async {
      final error = await run(MockClient((_) async {
        calls++;
        return http.Response('', 503);
      }));

      expect(calls, 3);
      expect((error as ApiException).message, _generic);
    });
  });

  group('Team Leader history screen', () {
    Future<void> pump(WidgetTester tester, http.Client client) async {
      await http.runWithClient(() async {
        await tester.pumpWidget(const MaterialApp(home: TlHistoryScreen()));
        for (var i = 0; i < 12; i++) {
          await tester.pump(const Duration(milliseconds: 50));
        }
      }, () => client);
    }

    testWidgets('a successful empty response shows the empty state', (tester) async {
      await pump(tester, MockClient((_) async => _json({'data': [], 'current_page': 1, 'last_page': 1}, 200)));

      expect(find.text('No completed jobs yet'), findsOneWidget);
      expect(find.text("We couldn't load your job history"), findsNothing);
    });

    testWidgets('a failed request shows an error state with retry, not the empty state', (tester) async {
      await pump(tester, MockClient((_) async => _json({'message': 'Server Error'}, 500)));

      expect(find.text("We couldn't load your job history"), findsOneWidget);
      expect(find.text(_generic), findsOneWidget);
      expect(find.text('Try again'), findsOneWidget);
      expect(find.text('No completed jobs yet'), findsNothing);
    });

    testWidgets('an unreachable server shows the connectivity wording, not the empty state', (tester) async {
      await pump(tester, MockClient((_) async => throw const SocketException('down')));

      expect(find.text(_unreachable), findsOneWidget);
      expect(find.text('No completed jobs yet'), findsNothing);
    });
  });
}
