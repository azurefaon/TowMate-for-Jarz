import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/widgets/auth_gate_sheet.dart';

class _TestHost extends StatelessWidget {
  const _TestHost();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: ElevatedButton(
          onPressed: () => showAuthGateSheet(context),
          child: const Text('Trigger'),
        ),
      ),
    );
  }
}

MaterialApp _appUnderTest() {
  return MaterialApp(
    onGenerateRoute: (settings) {
      final page = switch (settings.name) {
        '/login' => const Scaffold(body: Text('LOGIN SCREEN')),
        '/signup' => const Scaffold(body: Text('SIGNUP SCREEN')),
        _ => const _TestHost(),
      };
      return MaterialPageRoute(settings: settings, builder: (_) => page);
    },
    home: const _TestHost(),
  );
}

void main() {
  group('showAuthGateSheet', () {
    testWidgets('shows the auth-required message with Login and Create Account actions', (tester) async {
      await tester.pumpWidget(_appUnderTest());
      await tester.tap(find.text('Trigger'));
      await tester.pumpAndSettle();

      expect(find.text('Sign in to request towing'), findsOneWidget);
      expect(find.text('Login'), findsOneWidget);
      expect(find.text('Create Account'), findsOneWidget);
    });

    testWidgets('tapping Login navigates to the existing /login route', (tester) async {
      await tester.pumpWidget(_appUnderTest());
      await tester.tap(find.text('Trigger'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Login'));
      await tester.pumpAndSettle();

      expect(find.text('LOGIN SCREEN'), findsOneWidget);
    });

    testWidgets('tapping Create Account navigates to the existing /signup route', (tester) async {
      await tester.pumpWidget(_appUnderTest());
      await tester.tap(find.text('Trigger'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Create Account'));
      await tester.pumpAndSettle();

      expect(find.text('SIGNUP SCREEN'), findsOneWidget);
    });

    testWidgets('never shows a booking/dispatch action inside the sheet', (tester) async {
      await tester.pumpWidget(_appUnderTest());
      await tester.tap(find.text('Trigger'));
      await tester.pumpAndSettle();

      expect(find.textContaining('Book Now'), findsNothing);
      expect(find.textContaining('Dispatch'), findsNothing);
    });
  });
}
