import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/services/api_service.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() async {
    SharedPreferences.setMockInitialValues({'auth_token': 'stored-token-123'});
    await ApiService.clearSession();
    SharedPreferences.setMockInitialValues({'auth_token': 'stored-token-123'});
  });

  group('ApiService.fetchProfileImage', () {
    test('returns the raw image bytes on a 200 response', () async {
      final fakeBytes = Uint8List.fromList([1, 2, 3, 4, 5]);
      final client = MockClient((request) async {
        expect(request.url.path, contains('v1/profile/image'));
        expect(request.headers['Authorization'], 'Bearer stored-token-123');
        return http.Response.bytes(fakeBytes, 200);
      });

      final result = await http.runWithClient(
        () => ApiService.fetchProfileImage(),
        () => client,
      );

      expect(result, fakeBytes);
    });

    test('returns null when no photo has been uploaded yet (404)', () async {
      final client = MockClient((request) async {
        return http.Response('', 404);
      });

      final result = await http.runWithClient(
        () => ApiService.fetchProfileImage(),
        () => client,
      );

      expect(result, isNull);
    });

    test('returns null without throwing on a network failure', () async {
      final client = MockClient((request) async {
        throw Exception('network down');
      });

      final result = await http.runWithClient(
        () => ApiService.fetchProfileImage(),
        () => client,
      );

      expect(result, isNull);
    });

    test('returns null when there is no stored session token', () async {
      SharedPreferences.setMockInitialValues({});
      final client = MockClient((request) async {
        fail('should not make a network request without a token');
      });

      final result = await http.runWithClient(
        () => ApiService.fetchProfileImage(),
        () => client,
      );

      expect(result, isNull);
    });
  });

  group('ApiService.updateProfileImage', () {
    test('uploads the picked file as multipart form data with a bearer token', () async {
      String? authHeader;
      String? fieldName;
      String? filename;
      final client = MockClient.streaming((request, bodyStream) async {
        expect(request, isA<http.MultipartRequest>());
        final multipart = request as http.MultipartRequest;
        authHeader = multipart.headers['Authorization'];
        fieldName = multipart.files.single.field;
        filename = multipart.files.single.filename;
        await bodyStream.drain<void>();
        return http.StreamedResponse(
          Stream.value(utf8.encode(jsonEncode({'success': true}))),
          200,
        );
      });

      final image = XFile.fromData(
        Uint8List.fromList([9, 9, 9]),
        name: 'avatar.jpg',
        path: 'avatar.jpg',
        mimeType: 'image/jpeg',
      );

      final result = await http.runWithClient(
        () => ApiService.updateProfileImage(image),
        () => client,
      );

      expect(result['success'], isTrue);
      expect(authHeader, 'Bearer stored-token-123');
      expect(fieldName, 'profile_image');
      expect(filename, 'avatar.jpg');
    });

    test('rejects an unsupported file extension before making a network call', () async {
      final client = MockClient((request) async {
        fail('should not upload a file with an unsupported extension');
      });

      final image = XFile.fromData(
        Uint8List.fromList([9, 9, 9]),
        name: 'avatar.gif',
        path: 'avatar.gif',
        mimeType: 'image/gif',
      );

      final result = await http.runWithClient(
        () => ApiService.updateProfileImage(image),
        () => client,
      );

      expect(result['success'], isFalse);
    });

    test('surfaces a friendly message when there is no stored session token', () async {
      SharedPreferences.setMockInitialValues({});
      final client = MockClient((request) async {
        fail('should not upload without a token');
      });

      final image = XFile.fromData(
        Uint8List.fromList([9, 9, 9]),
        name: 'avatar.png',
        path: 'avatar.png',
        mimeType: 'image/png',
      );

      final result = await http.runWithClient(
        () => ApiService.updateProfileImage(image),
        () => client,
      );

      expect(result['success'], isFalse);
      expect(result['message'], contains('session'));
    });

    test('a server-side validation failure is surfaced, not silently swallowed', () async {
      final client = MockClient((request) async {
        return http.Response(
          jsonEncode({'success': false, 'message': 'The profile image field must be an image.'}),
          422,
        );
      });

      final image = XFile.fromData(
        Uint8List.fromList([9, 9, 9]),
        name: 'avatar.png',
        path: 'avatar.png',
        mimeType: 'image/png',
      );

      final result = await http.runWithClient(
        () => ApiService.updateProfileImage(image),
        () => client,
      );

      expect(result['success'], isFalse);
      expect(result['message'], contains('image'));
    });
  });
}
