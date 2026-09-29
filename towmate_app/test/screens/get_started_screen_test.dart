import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/app_prefs.dart';
import 'package:towmate_app/screens/customer/get_started_screen.dart';

void main() {
  Future<void> pumpScreen(WidgetTester tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: const GetStartedScreen(),
        routes: {
          '/login': (_) => const Scaffold(body: Text('LOGIN_PLACEHOLDER')),
        },
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('shows the JARZ logo, TowMate wordmark and Get started button', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({});
    await pumpScreen(tester);

    expect(find.byType(Image), findsWidgets);
    expect(find.textContaining('Get started'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'tapping Get started persists onboarding completion and navigates to Login',
    (tester) async {
      SharedPreferences.setMockInitialValues({});
      await pumpScreen(tester);

      expect(await AppPrefs.getOnboardingComplete(), isFalse);

      await tester.tap(find.textContaining('Get started'));
      await tester.pumpAndSettle();

      expect(find.text('LOGIN_PLACEHOLDER'), findsOneWidget);
      expect(await AppPrefs.getOnboardingComplete(), isTrue);
    },
  );
}
