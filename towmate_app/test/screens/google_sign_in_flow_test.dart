import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/customer/login_screen.dart';
import 'package:towmate_app/screens/customer/signup_screen.dart';
import 'package:towmate_app/widgets/google_signin_button.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  SharedPreferences.setMockInitialValues({});

  Future<void> pumpScreen(WidgetTester tester, Widget screen) async {
    await tester.pumpWidget(MaterialApp(home: screen));
    await tester.pump();
    await tester.pump();
  }

  group('Login Google sign-in flow', () {
    testWidgets('tapping Continue with Google resolves safely without a raw error or crash', (tester) async {
      await pumpScreen(tester, const LoginScreen());

      expect(find.text('Continue with Google'), findsOneWidget);

      await tester.tap(find.byType(GoogleSignInButton));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.textContaining('Exception'), findsNothing);
      expect(find.textContaining('MissingPlugin'), findsNothing);
      expect(find.textContaining('stack'), findsNothing);
      expect(find.text('Continue with Google'), findsOneWidget);
    });

    testWidgets('rapidly tapping Continue with Google twice does not throw or duplicate submissions', (
      tester,
    ) async {
      await pumpScreen(tester, const LoginScreen());

      await tester.tap(find.byType(GoogleSignInButton));
      await tester.pump();
      await tester.tap(find.byType(GoogleSignInButton), warnIfMissed: false);
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
    });

    testWidgets('the email/password Sign in flow remains present alongside the Google button', (tester) async {
      await pumpScreen(tester, const LoginScreen());

      expect(find.text('Email'), findsOneWidget);
      expect(find.text('Password'), findsOneWidget);
      expect(find.text('Sign in'), findsOneWidget);
      expect(find.text('Continue with Google'), findsOneWidget);
    });

    for (final width in [320.0, 340.0, 360.0, 375.0, 390.0, 412.0]) {
      testWidgets('renders without horizontal overflow at $width px width', (tester) async {
        final originalSize = tester.view.physicalSize;
        final originalRatio = tester.view.devicePixelRatio;
        tester.view.physicalSize = Size(width, 800);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(() {
          tester.view.physicalSize = originalSize;
          tester.view.devicePixelRatio = originalRatio;
        });

        await pumpScreen(tester, const LoginScreen());

        expect(tester.takeException(), isNull);
        expect(find.text('Forgot password?'), findsOneWidget);
        expect(find.text('Continue with Google'), findsOneWidget);
      });
    }

    testWidgets('Forgot password? stays below the Password field and right-aligned', (tester) async {
      await pumpScreen(tester, const LoginScreen());

      final passwordFieldCenter = tester.getCenter(find.text('Password'));
      final forgotPasswordCenter = tester.getCenter(find.text('Forgot password?'));

      expect(forgotPasswordCenter.dy, greaterThan(passwordFieldCenter.dy));

      final screenWidth = tester.view.physicalSize.width / tester.view.devicePixelRatio;
      expect(forgotPasswordCenter.dx, greaterThan(screenWidth / 2));
    });
  });

  group('Signup Google sign-in flow', () {
    testWidgets('tapping Sign up with Google resolves safely without a raw error or crash', (tester) async {
      await pumpScreen(tester, const SignupScreen());

      expect(find.text('Sign up with Google'), findsOneWidget);

      await tester.ensureVisible(find.byType(GoogleSignInButton));
      await tester.pump();
      await tester.tap(find.byType(GoogleSignInButton));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.textContaining('Exception'), findsNothing);
      expect(find.textContaining('MissingPlugin'), findsNothing);
    });

    testWidgets('the registration form fields remain present alongside the Google button', (tester) async {
      await pumpScreen(tester, const SignupScreen());

      expect(find.text('Phone number'), findsOneWidget);
      expect(find.text('Create account'), findsOneWidget);
      expect(find.text('Sign up with Google'), findsOneWidget);
    });

    testWidgets('uses "or sign up with" wording, distinct from the Login divider', (tester) async {
      await pumpScreen(tester, const SignupScreen());

      expect(find.text('or sign up with'), findsOneWidget);
      expect(find.text('or continue with'), findsNothing);
    });

    testWidgets('renders without horizontal overflow at a narrow mobile viewport', (tester) async {
      final originalSize = tester.view.physicalSize;
      final originalRatio = tester.view.devicePixelRatio;
      tester.view.physicalSize = const Size(360, 800);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() {
        tester.view.physicalSize = originalSize;
        tester.view.devicePixelRatio = originalRatio;
      });

      await pumpScreen(tester, const SignupScreen());

      expect(tester.takeException(), isNull);
      expect(find.text('Sign up with Google'), findsOneWidget);
    });
  });
}
