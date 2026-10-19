import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/core/api_config.dart';

void main() {
  group('isSecureApiBaseUrl', () {
    test('accepts the production API URL', () {
      expect(isSecureApiBaseUrl('https://www.jarztowing.com/api'), isTrue);
      expect(isSecureApiBaseUrl('  https://api.example.com/api  '), isTrue);
    });

    test('rejects plain http, even on a public host', () {
      expect(isSecureApiBaseUrl('http://www.jarztowing.com/api'), isFalse);
    });

    test('rejects the compiled-in development default', () {
      expect(isSecureApiBaseUrl('http://127.0.0.1:8000/api'), isFalse);
    });

    test('rejects https URLs that point at a local or private address', () {
      for (final url in [
        'https://localhost/api',
        'https://127.0.0.1/api',
        'https://10.0.2.2/api',
        'https://192.168.1.20/api',
        'https://172.16.0.5/api',
        'https://172.31.255.1/api',
        'https://printer.local/api',
      ]) {
        expect(isSecureApiBaseUrl(url), isFalse, reason: url);
      }
      // 172.32.x.x is public address space
      expect(isSecureApiBaseUrl('https://172.32.0.1/api'), isTrue);
    });

    test('rejects empty, malformed and host-less values', () {
      expect(isSecureApiBaseUrl(''), isFalse);
      expect(isSecureApiBaseUrl('not a url'), isFalse);
      expect(isSecureApiBaseUrl('https://'), isFalse);
    });
  });

  group('assertSecureApiForRelease', () {
    test('throws for a release build with an insecure URL', () {
      expect(
        () => assertSecureApiForRelease(isRelease: true, baseUrl: 'http://127.0.0.1:8000/api'),
        throwsA(isA<StateError>()),
      );
    });

    test('allows a release build with the production URL', () {
      expect(
        () => assertSecureApiForRelease(isRelease: true, baseUrl: 'https://www.jarztowing.com/api'),
        returnsNormally,
      );
    });

    test('never blocks debug or profile builds, so local development is unchanged', () {
      expect(
        () => assertSecureApiForRelease(isRelease: false, baseUrl: 'http://127.0.0.1:8000/api'),
        returnsNormally,
      );
    });
  });
}
