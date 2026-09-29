import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  late String source;

  setUpAll(() {
    source = File(
      'lib/screens/customer/google_phone_completion_screen.dart',
    ).readAsStringSync();
  });

  group('Google phone completion — Terms of Use / Privacy Policy acceptance', () {
    test('a TermsAgreementCheckbox is rendered on the phone completion screen', () {
      expect(source.contains('TermsAgreementCheckbox('), isTrue);
    });

    test('the Continue button is disabled until the Terms checkbox is checked', () {
      expect(
        source.contains('onPressed: (_isLoading || !_acceptTerms) ? null : _submit'),
        isTrue,
      );
    });

    test('tapping Terms of Use / Privacy Policy opens the in-app readable screens', () {
      expect(source.contains('const TermsOfUseScreen()'), isTrue);
      expect(source.contains('const PrivacyPolicyScreen()'), isTrue);
      expect(source.contains('onTermsTap: _openTerms'), isTrue);
      expect(source.contains('onPrivacyTap: _openPrivacy'), isTrue);
    });

    test('the accepted value is forwarded to the Google completion API call', () {
      expect(source.contains('acceptTerms: _acceptTerms'), isTrue);
    });

    test('the phone number requirement is still present, unchanged by the Terms addition', () {
      expect(source.contains('ApiService.completeGoogleSignup('), isTrue);
      expect(source.contains('phone: phone'), isTrue);
      expect(source.contains('PhMobilePhone.validateLocal('), isTrue);
    });
  });
}
