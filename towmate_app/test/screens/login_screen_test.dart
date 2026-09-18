import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  late String source;

  setUpAll(() {
    source = File('lib/screens/customer/login_screen.dart').readAsStringSync();
  });

  group('login screen redesign invariants', () {
    test('renders the modern Welcome back layout with the requested subtle glow', () {
      expect(source.contains("'Welcome back'"), isTrue);
      expect(source.contains("'Sign in to continue with TowMate'"), isTrue);
      expect(source.contains('boxShadow'), isTrue);
    });

    test('the primary action is labeled Sign in, not Login', () {
      expect(source.contains("label: 'Sign in'"), isTrue);
      expect(source.contains("'Login'"), isFalse);
    });

    test('Forgot Password still routes to the existing ForgotPasswordScreen', () {
      expect(source.contains("'Forgot password?'"), isTrue);
      expect(source.contains('ForgotPasswordScreen()'), isTrue);
    });

    test('Forgot Password renders below the password field and is right-aligned', () {
      final passwordFieldIndex = source.indexOf("label: 'PASSWORD'");
      final forgotButtonIndex = source.indexOf("'Forgot password?'");
      expect(passwordFieldIndex, greaterThan(-1));
      expect(forgotButtonIndex, greaterThan(passwordFieldIndex));

      final betweenFieldAndButton = source.substring(passwordFieldIndex, forgotButtonIndex);
      expect(betweenFieldAndButton.contains('Alignment.centerRight'), isTrue);
      expect(source.contains('hideLabel'), isFalse);
    });

    test('Create Account still routes to the existing /signup route', () {
      expect(source.contains("'Create Account'"), isTrue);
      expect(source.contains("Navigator.pushNamed(context, '/signup')"), isTrue);
    });

    test('the primary email/password form appears before the Google section', () {
      final emailFieldIndex = source.indexOf("label: 'EMAIL'");
      final passwordFieldIndex = source.indexOf("label: 'PASSWORD'");
      final signInButtonIndex = source.indexOf("label: 'Sign in'");
      final dividerIndex = source.indexOf('_AuthDivider(');
      final googleButtonIndex = source.indexOf('GoogleSignInButton(');

      expect(emailFieldIndex, greaterThan(-1));
      expect(passwordFieldIndex, greaterThan(emailFieldIndex));
      expect(signInButtonIndex, greaterThan(passwordFieldIndex));
      expect(dividerIndex, greaterThan(signInButtonIndex));
      expect(googleButtonIndex, greaterThan(dividerIndex));
    });

    test('the Create Account footer appears after the Google section', () {
      final googleButtonIndex = source.indexOf('GoogleSignInButton(');
      final footerIndex = source.indexOf("\"Don't have an account? \"");
      expect(googleButtonIndex, greaterThan(-1));
      expect(footerIndex, greaterThan(googleButtonIndex));
    });

    test('Google sign-in obtains a real credential and lets the server verify it, never trusting a bare email', () {
      expect(source.contains('GoogleAuthService.signIn()'), isTrue);
      expect(source.contains('ApiService.loginWithGoogle('), isTrue);
      expect(source.contains("body: jsonEncode({'email'"), isFalse);
    });

    test('a cancelled Google sign-in does not surface as an error banner', () {
      expect(source.contains('GoogleAuthOutcome.cancelled'), isTrue);
    });

    test('a Google identity that needs phone completion routes to the completion screen, not straight into the app', () {
      expect(source.contains("res['needsPhone'] == true"), isTrue);
      expect(source.contains('GooglePhoneCompletionScreen('), isTrue);
    });

    test('password visibility toggle is present', () {
      expect(source.contains('_obscure = !_obscure'), isTrue);
      expect(source.contains('Icons.visibility'), isTrue);
      expect(source.contains('Icons.visibility_off'), isTrue);
    });

    test('submission uses a compact in-button loading state, not a fullscreen skeleton', () {
      expect(source.contains('SkeletonBox'), isFalse);
      expect(
        RegExp(r'isLoading\s*\?\s*const SizedBox\(\s*width: 20,\s*height: 20,\s*child: CircularProgressIndicator').hasMatch(source),
        isTrue,
      );
    });

    test('existing rate-limit and CSRF security behavior is preserved', () {
      expect(source.contains('RateLimiter.isLocked'), isTrue);
      expect(source.contains('RateLimiter.recordFailure()'), isTrue);
      expect(source.contains('CsrfTokenService.generate()'), isTrue);
    });

    test('every Team Leader sign-in path starts the presence controller', () {
      final startCount = 'TlPresenceController.start()'.allMatches(source).length;
      expect(startCount, 3);
    });

    test('presence start is gated on the Team Leader role, not called unconditionally', () {
      for (final match in RegExp('TlPresenceController.start\\(\\);?').allMatches(source)) {
        final before = source.substring(0, match.start);
        final lineStart = before.lastIndexOf('\n') + 1;
        final line = source.substring(lineStart, match.start);
        expect(line.contains("role == 'Team Leader'"), isTrue);
      }
    });

    test('post-login routing by role is unchanged', () {
      expect(source.contains("role == 'Team Leader'"), isTrue);
      expect(source.contains("'/tl-force-password'"), isTrue);
      expect(source.contains("'/tl-home'"), isTrue);
      expect(source.contains("'/home'"), isTrue);
    });
  });
}
