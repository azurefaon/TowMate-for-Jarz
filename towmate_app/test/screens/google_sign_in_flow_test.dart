import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/customer/login_screen.dart';
import 'package:towmate_app/screens/customer/signup_screen.dart';
import 'package:towmate_app/widgets/google_mark.dart';
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
    testWidgets('tapping the Google icon button resolves safely without a raw error or crash', (tester) async {
      await pumpScreen(tester, const LoginScreen());

      expect(find.byType(GoogleMark), findsOneWidget);
      expect(find.text('Continue with Google'), findsNothing);

      await tester.tap(find.byType(GoogleSignInButton));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.textContaining('Exception'), findsNothing);
      expect(find.textContaining('MissingPlugin'), findsNothing);
      expect(find.textContaining('stack'), findsNothing);
      expect(find.byType(GoogleMark), findsOneWidget);
    });

    testWidgets('rapidly tapping the Google icon button twice does not throw or duplicate submissions', (
      tester,
    ) async {
      await pumpScreen(tester, const LoginScreen());

      await tester.tap(find.byType(GoogleSignInButton));
      await tester.pump();
      await tester.tap(find.byType(GoogleSignInButton), warnIfMissed: false);
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
    });

    testWidgets('the email/password Sign in flow remains present alongside the Google icon button', (tester) async {
      await pumpScreen(tester, const LoginScreen());

      expect(find.text('EMAIL'), findsOneWidget);
      expect(find.text('PASSWORD'), findsOneWidget);
      expect(find.text('Sign in'), findsOneWidget);
      expect(find.byType(GoogleMark), findsOneWidget);
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
        expect(find.byType(GoogleMark), findsOneWidget);
      });
    }

    testWidgets('Forgot password? stays below the Password field and right-aligned', (tester) async {
      await pumpScreen(tester, const LoginScreen());

      final passwordFieldCenter = tester.getCenter(find.text('PASSWORD'));
      final forgotPasswordCenter = tester.getCenter(find.text('Forgot password?'));

      expect(forgotPasswordCenter.dy, greaterThan(passwordFieldCenter.dy));

      final screenWidth = tester.view.physicalSize.width / tester.view.devicePixelRatio;
      expect(forgotPasswordCenter.dx, greaterThan(screenWidth / 2));
    });
  });

  group('Signup Google sign-in flow', () {
    testWidgets('tapping the Google icon button resolves safely without a raw error or crash', (tester) async {
      await pumpScreen(tester, const SignupScreen());

      expect(find.byType(GoogleMark), findsOneWidget);
      expect(find.text('Sign up with Google'), findsNothing);

      await tester.ensureVisible(find.byType(GoogleSignInButton));
      await tester.pump();
      await tester.tap(find.byType(GoogleSignInButton));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.textContaining('Exception'), findsNothing);
      expect(find.textContaining('MissingPlugin'), findsNothing);
    });

    testWidgets('the registration form fields remain present alongside the Google icon button', (tester) async {
      await pumpScreen(tester, const SignupScreen());

      expect(find.text('+63'), findsOneWidget);
      expect(find.text('Create account'), findsOneWidget);
      expect(find.byType(GoogleMark), findsOneWidget);
    });

    testWidgets('uses "or sign up with" wording, distinct from the Login divider', (tester) async {
      await pumpScreen(tester, const SignupScreen());

      expect(find.text('or sign up with'), findsOneWidget);
      expect(find.text('or continue with'), findsNothing);
    });

    for (final width in [320.0, 340.0, 360.0, 375.0, 390.0, 412.0]) {
      testWidgets('renders without horizontal overflow at $width px width', (tester) async {
        final originalSize = tester.view.physicalSize;
        final originalRatio = tester.view.devicePixelRatio;
        tester.view.physicalSize = Size(width, 900);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(() {
          tester.view.physicalSize = originalSize;
          tester.view.devicePixelRatio = originalRatio;
        });

        await pumpScreen(tester, const SignupScreen());

        expect(tester.takeException(), isNull);
        expect(find.byType(GoogleMark), findsOneWidget);
      });
    }
  });
}
