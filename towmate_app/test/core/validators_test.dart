import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/core/validators.dart';

void main() {
  group('Validators.email — Gmail-only registration validation', () {
    test('accepts a plain Gmail address', () {
      expect(Validators.email('customer@gmail.com'), isNull);
    });

    test('accepts an uppercase Gmail domain after normalization', () {
      expect(Validators.email('Customer@GMAIL.COM'), isNull);
    });

    test('accepts a Gmail address with surrounding spaces', () {
      expect(Validators.email(' customer@gmail.com '), isNull);
    });

    test('rejects a Yahoo address', () {
      expect(Validators.email('customer@yahoo.com'), 'Please use a Gmail address.');
    });

    test('rejects an Outlook address', () {
      expect(Validators.email('customer@outlook.com'), 'Please use a Gmail address.');
    });

    test('rejects a Hotmail address', () {
      expect(Validators.email('customer@hotmail.com'), 'Please use a Gmail address.');
    });

    test('rejects an arbitrary custom domain', () {
      expect(Validators.email('customer@hesoyam.com'), 'Please use a Gmail address.');
    });

    test('rejects a near-miss domain like gmail.co', () {
      expect(Validators.email('customer@gmail.co'), 'Please use a Gmail address.');
    });

    test('rejects a spoofed subdomain like gmail.com.example.com', () {
      expect(Validators.email('customer@gmail.com.example.com'), 'Please use a Gmail address.');
    });

    test('rejects malformed input with a Gmail-specific message', () {
      expect(Validators.email('ada'), 'Enter a valid Gmail address.');
    });

    test('requires an email', () {
      expect(Validators.email(''), 'Email is required');
      expect(Validators.email(null), 'Email is required');
    });

    test('does not silently rewrite a non-Gmail address as valid', () {
      expect(Validators.email('customer@gmail.com.br'), isNotNull);
    });
  });

  group('Validators.password — requirements remain intact', () {
    test('requires at least 12 characters', () {
      expect(Validators.password('Sh0rt!'), 'At least 12 characters required');
    });

    test('requires an uppercase letter', () {
      expect(Validators.password('alllowercase123!'), 'Must contain an uppercase letter');
    });

    test('requires a lowercase letter', () {
      expect(Validators.password('ALLUPPERCASE123!'), 'Must contain a lowercase letter');
    });

    test('requires a number', () {
      expect(Validators.password('NoNumbersHere!!'), 'Must contain a number');
    });

    test('requires a special character', () {
      expect(Validators.password('NoSpecialChar123'), 'Must contain a special character');
    });

    test('accepts a password meeting every rule', () {
      expect(Validators.password('Xk7!TowMateSecure91'), isNull);
    });
  });

  group('Validators.confirmPassword — mismatch detection remains intact', () {
    test('flags a mismatch', () {
      expect(Validators.confirmPassword('Different!123', 'Original!123'), 'Passwords do not match');
    });

    test('accepts a match', () {
      expect(Validators.confirmPassword('Original!123', 'Original!123'), isNull);
    });

    test('requires confirmation', () {
      expect(Validators.confirmPassword('', 'Original!123'), 'Please confirm your password');
    });
  });
}
