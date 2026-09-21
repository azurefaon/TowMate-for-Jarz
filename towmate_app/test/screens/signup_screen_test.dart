import 'dart:io';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/core/validators.dart';

void main() {
  late String source;
  late String validatorsSource;

  setUpAll(() {
    source = File('lib/screens/customer/signup_screen.dart').readAsStringSync();
    validatorsSource = File('lib/core/validators.dart').readAsStringSync();
  });

  group('signup screen redesign invariants', () {
    test('renders the modern Create your account layout', () {
      expect(source.contains("'Create your account'"), isTrue);
      expect(source.contains("'Request and track towing services with TowMate'"), isTrue);
      expect(source.contains('boxShadow'), isTrue);
    });

    test('all real backend-required fields remain, no invented middle name field', () {
      expect(source.contains('_firstNameController'), isTrue);
      expect(source.contains('_lastNameController'), isTrue);
      expect(source.contains('_emailController'), isTrue);
      expect(source.contains('_phoneController'), isTrue);
      expect(source.contains('_passwordController'), isTrue);
      expect(source.contains('_confirmPasswordController'), isTrue);
      expect(source.contains('middleName'), isFalse);
      expect(source.contains('Middle name'), isFalse);
    });

    test('the Create account action remains and still starts the existing OTP flow', () {
      expect(source.contains("'Create account'"), isTrue);
      expect(source.contains('ApiService.sendRegistrationOtp(email)'), isTrue);
      expect(source.contains('EmailOtpScreen('), isTrue);
    });

    test('Back to sign in and the Sign in link both route back to login', () {
      expect(source.contains("'Back to sign in'"), isTrue);
      expect(source.contains("'Sign in'"), isTrue);
      expect(source.contains('_goToLogin'), isTrue);
    });

    test('the submitted server contract normalizes to +63 plus the local digits', () {
      expect(source.contains('PhMobilePhone.validateLocal('), isTrue);
      expect(
        source.contains("phone: '+63\${InputSanitizer.sanitize(_phoneController.text)}'"),
        isTrue,
      );
    });

    test('the normal signup form and Create account CTA appear before the Google section', () {
      final formIndex = source.indexOf('Form(');
      final createAccountButtonIndex = source.indexOf("'Create account'");
      final dividerIndex = source.indexOf('_AuthDivider(');
      final googleButtonIndex = source.indexOf('GoogleSignInButton(');

      expect(formIndex, greaterThan(-1));
      expect(createAccountButtonIndex, greaterThan(formIndex));
      expect(dividerIndex, greaterThan(createAccountButtonIndex));
      expect(googleButtonIndex, greaterThan(dividerIndex));
    });

    test('the Sign in footer appears after the Google section', () {
      final googleButtonIndex = source.indexOf('GoogleSignInButton(');
      final footerIndex = source.indexOf("'Already have an account? '");
      expect(googleButtonIndex, greaterThan(-1));
      expect(footerIndex, greaterThan(googleButtonIndex));
    });

    test('Google sign-in obtains a real credential and lets the server verify it, never trusting a bare email', () {
      expect(source.contains('GoogleAuthService.signIn()'), isTrue);
      expect(source.contains('ApiService.loginWithGoogle('), isTrue);
      expect(source.contains("body: jsonEncode({'email'"), isFalse);
    });

    test('a Google identity that needs phone completion routes to the shared completion screen', () {
      expect(source.contains("res['needsPhone'] == true"), isTrue);
      expect(source.contains('GooglePhoneCompletionScreen('), isTrue);
    });

    test('the form remains scrollable', () {
      expect(source.contains('SingleChildScrollView'), isTrue);
    });

    test('password strength feedback is still shown while typing', () {
      expect(source.contains('PasswordStrengthBar(password: _passwordValue)'), isTrue);
    });

    test('validation is gated per-field instead of globally on every keystroke', () {
      expect(source.contains('_touched'), isTrue);
      expect(source.contains('_gated('), isTrue);
      expect(source.contains('_submitted'), isTrue);
      expect(
        RegExp(r'Form\(\s*key:\s*_formKey,').hasMatch(source) &&
            !RegExp(r'Form\(\s*key:\s*_formKey,\s*autovalidateMode').hasMatch(source),
        isTrue,
      );
    });

    test('each field touches itself independently on change', () {
      expect(source.contains("_touch('firstName')"), isTrue);
      expect(source.contains("_touch('lastName')"), isTrue);
      expect(source.contains("_touch('email')"), isTrue);
      expect(source.contains("_touch('phone')"), isTrue);
      expect(source.contains("_touch('password')"), isTrue);
      expect(source.contains("_touch('confirmPassword')"), isTrue);
    });

    test('submit reveals all remaining validation errors', () {
      expect(source.contains('_submitted = true'), isTrue);
    });

    test('Google signup uses signup-specific wording, distinct from Login', () {
      expect(source.contains("_AuthDivider(label: 'or sign up with')"), isTrue);
      expect(source.contains("label: 'Sign up with Google'"), isTrue);
      expect(source.contains('webText: GoogleButtonText.signUp'), isTrue);
    });

    test('Google signup does not require the normal form fields to validate first', () {
      final googleButtonIndex = source.indexOf('GoogleSignInButton(');
      final formEndIndex = source.lastIndexOf(');', googleButtonIndex);
      expect(googleButtonIndex, greaterThan(-1));
      expect(source.contains('onWebResult: _onWebGoogleResult'), isTrue);
      expect(formEndIndex, lessThan(googleButtonIndex));
    });
  });

  group('phone number field — numeric-only PH mobile format', () {
    test('+63 is rendered as a fixed, non-editable prefix outside the controller value, with no fake country selector', () {
      final phoneFieldStart = source.indexOf('class _PhoneField');
      final phoneFieldEnd = source.indexOf('class _AuthDivider');
      final block = source.substring(phoneFieldStart, phoneFieldEnd);
      expect(block.contains("'+63'"), isTrue);
      expect(block.contains('TextEditingController'), isTrue);
      expect(source.contains("prefixText: '+63"), isFalse);
      expect(block.contains('flag'), isFalse);
      expect(block.contains('chevron'), isFalse);
      expect(block.contains('DropdownButton'), isFalse);
    });

    test('the phone field requests a numeric/phone keyboard', () {
      final phoneFieldStart = source.indexOf('class _PhoneField');
      final phoneFieldEnd = source.indexOf('class _AuthDivider');
      final block = source.substring(phoneFieldStart, phoneFieldEnd);
      expect(block.contains('keyboardType: TextInputType.phone'), isTrue);
    });

    test('digit-only and 10-digit-max input formatters are wired to the phone field via the shared helper', () {
      final phoneFieldStart = source.indexOf('class _PhoneField');
      final phoneFieldEnd = source.indexOf('class _AuthDivider');
      final block = source.substring(phoneFieldStart, phoneFieldEnd);
      expect(block.contains('inputFormatters: PhMobilePhone.formatters'), isTrue);
      expect(validatorsSource.contains('FilteringTextInputFormatter.digitsOnly'), isTrue);
      expect(validatorsSource.contains('LengthLimitingTextInputFormatter(10)'), isTrue);
    });

    test('the final validator requires exactly 10 digits starting with 9', () {
      expect(source.contains('PhMobilePhone.validateLocal('), isTrue);
      expect(validatorsSource.contains(r'^9\d{9}$'), isTrue);
      expect(validatorsSource.contains('Enter a valid 10-digit mobile number starting with 9.'), isTrue);
    });

    test('an incomplete phone number does not show an error before submit', () {
      expect(source.contains('showIncomplete: _submitted'), isTrue);
      expect(validatorsSource.contains("showIncomplete ? 'Phone number is required' : null"), isTrue);
      expect(validatorsSource.contains('showIncomplete ? errorMessage : null'), isTrue);
    });

    String applyFormatters(String rawInput) {
      var value = TextEditingValue.empty;
      for (final char in rawInput.split('')) {
        final next = TextEditingValue(
          text: value.text + char,
          selection: TextSelection.collapsed(offset: value.text.length + 1),
        );
        var formatted = next;
        for (final formatter in PhMobilePhone.formatters) {
          formatted = formatter.formatEditUpdate(value, formatted);
        }
        value = formatted;
      }
      return value.text;
    }

    String applyFormattersToPastedText(String pasted) {
      var formatted = TextEditingValue(text: pasted, selection: TextSelection.collapsed(offset: pasted.length));
      for (final formatter in PhMobilePhone.formatters) {
        formatted = formatter.formatEditUpdate(TextEditingValue.empty, formatted);
      }
      return formatted.text;
    }

    test('typed letters never remain in the formatted value', () {
      expect(applyFormatters('917123abc'), '917123');
    });

    test('typed symbols never remain in the formatted value', () {
      expect(applyFormatters('917-123-4567'), '9171234567');
    });

    test('typing is capped at 10 digits, an 11th digit is dropped', () {
      expect(applyFormatters('91712345678'), '9171234567');
      expect(applyFormatters('91712345678').length, 10);
    });

    test('a valid 10-digit local number formats through untouched', () {
      expect(applyFormatters('9171234567'), '9171234567');
    });

    test('pasting text containing letters never leaves letters in the field', () {
      final result = applyFormattersToPastedText('9171234567abc');
      expect(result, '9171234567');
      expect(RegExp(r'[a-zA-Z]').hasMatch(result), isFalse);
    });

    test('pasting pure letters results in an empty numeric value', () {
      final result = applyFormattersToPastedText('abc');
      expect(result, '');
    });

    test('the shared validator accepts only a 10-digit number starting with 9', () {
      expect(PhMobilePhone.validateLocal('9171234567', showIncomplete: true), isNull);
      expect(PhMobilePhone.validateLocal('09171234567', showIncomplete: true), isNotNull);
      expect(PhMobilePhone.validateLocal('8171234567', showIncomplete: true), isNotNull);
      expect(PhMobilePhone.validateLocal('917123456', showIncomplete: true), isNotNull);
      expect(PhMobilePhone.localPattern.hasMatch('9171234567'), isTrue);
      expect(PhMobilePhone.localPattern.hasMatch('8171234567'), isFalse);
    });

    test('the normalized API value is exactly +63 followed by the 10 local digits', () {
      final local = applyFormatters('9171234567');
      expect(PhMobilePhone.toCanonical(local), '+639171234567');
    });
  });
}
