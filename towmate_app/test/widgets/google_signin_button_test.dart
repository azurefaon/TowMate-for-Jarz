import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/widgets/google_signin_button.dart';

void main() {
  Widget host(Widget child) => MaterialApp(home: Scaffold(body: child));

  group('GoogleSignInButton', () {
    testWidgets('shows the Continue with Google label when idle', (tester) async {
      await tester.pumpWidget(host(GoogleSignInButton(isLoading: false, onPressed: () {})));

      expect(find.text('Continue with Google'), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsNothing);
    });

    testWidgets('tapping the button invokes onPressed exactly once', (tester) async {
      var tapCount = 0;
      await tester.pumpWidget(host(GoogleSignInButton(isLoading: false, onPressed: () => tapCount++)));

      await tester.tap(find.byType(GoogleSignInButton));
      await tester.pump();

      expect(tapCount, 1);
    });

    testWidgets('shows a compact in-button progress indicator while loading, and blocks repeated taps', (tester) async {
      var tapCount = 0;
      await tester.pumpWidget(host(GoogleSignInButton(isLoading: true, onPressed: () => tapCount++)));

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(find.text('Continue with Google'), findsNothing);

      await tester.tap(find.byType(GoogleSignInButton), warnIfMissed: false);
      await tester.pump();

      expect(tapCount, 0);
    });

    testWidgets('shows a custom label when overridden for the signup context', (tester) async {
      await tester.pumpWidget(
        host(
          GoogleSignInButton(
            isLoading: false,
            onPressed: () {},
            label: 'Sign up with Google',
            webText: GoogleButtonText.signUp,
          ),
        ),
      );

      expect(find.text('Sign up with Google'), findsOneWidget);
      expect(find.text('Continue with Google'), findsNothing);
    });
  });

  group('OrContinueDivider', () {
    testWidgets('renders the divider label between two dividers', (tester) async {
      await tester.pumpWidget(host(const OrContinueDivider()));

      expect(find.text('or continue with'), findsOneWidget);
      expect(find.byType(Divider), findsNWidgets(2));
    });

    testWidgets('renders a custom label when overridden for the signup context', (tester) async {
      await tester.pumpWidget(host(const OrContinueDivider(label: 'or sign up with')));

      expect(find.text('or sign up with'), findsOneWidget);
      expect(find.text('or continue with'), findsNothing);
      expect(find.byType(Divider), findsNWidgets(2));
    });
  });
}
