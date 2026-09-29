import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/customer/privacy_policy_screen.dart';
import 'package:towmate_app/screens/customer/terms_acceptance_screen.dart';
import 'package:towmate_app/screens/customer/terms_of_use_screen.dart';

Future<void> settle(WidgetTester tester) async {
  for (var i = 0; i < 12; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> pumpScreen(WidgetTester tester) async {
  await tester.pumpWidget(
    MaterialApp(
      home: const TermsAcceptanceScreen(),
      routes: {
        '/home': (_) => const Scaffold(body: Text('HOME_PLACEHOLDER')),
        '/login': (_) => const Scaffold(body: Text('LOGIN_PLACEHOLDER')),
      },
    ),
  );
  await settle(tester);
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  setUp(() {
    SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
  });

  testWidgets('does not look like a registration form: no name/email/password fields', (
    tester,
  ) async {
    await pumpScreen(tester);

    expect(find.text('Terms & Privacy'), findsOneWidget);
    expect(find.byType(TextField), findsNothing);
    expect(find.byType(TextFormField), findsNothing);
  });

  testWidgets('Continue is disabled until the checkbox is checked', (tester) async {
    await pumpScreen(tester);

    final button = tester.widget<ElevatedButton>(find.widgetWithText(ElevatedButton, 'Continue'));
    expect(button.onPressed, isNull);

    await tester.tap(find.byType(Checkbox));
    await settle(tester);

    final enabledButton = tester.widget<ElevatedButton>(
      find.widgetWithText(ElevatedButton, 'Continue'),
    );
    expect(enabledButton.onPressed, isNotNull);
  });

  testWidgets('tapping Read Terms of Use opens the Terms of Use screen', (tester) async {
    await pumpScreen(tester);

    await tester.tap(find.text('Read Terms of Use'));
    await settle(tester);

    expect(find.byType(TermsOfUseScreen), findsOneWidget);
  });

  testWidgets('tapping Read Privacy Policy opens the Privacy Policy screen', (tester) async {
    await pumpScreen(tester);

    await tester.tap(find.text('Read Privacy Policy'));
    await settle(tester);

    expect(find.byType(PrivacyPolicyScreen), findsOneWidget);
  });

  testWidgets('accepting successfully routes to Customer Home with no redirect loop', (
    tester,
  ) async {
    await pumpScreen(tester);

    await http.runWithClient(() async {
      await tester.tap(find.byType(Checkbox));
      await settle(tester);
      await tester.tap(find.text('Continue'));
      await settle(tester);
    }, () => MockClient((request) async {
      return http.Response(
        jsonEncode({'success': true, 'message': 'Terms accepted.'}),
        200,
        headers: {'content-type': 'application/json'},
      );
    }));

    expect(find.text('HOME_PLACEHOLDER'), findsOneWidget);
    expect(find.byType(TermsAcceptanceScreen), findsNothing);
  });

  testWidgets('a failed acceptance keeps the user on the Terms screen with an error, not stuck silently', (
    tester,
  ) async {
    await pumpScreen(tester);

    await http.runWithClient(() async {
      await tester.tap(find.byType(Checkbox));
      await settle(tester);
      await tester.tap(find.text('Continue'));
      await settle(tester);
    }, () => MockClient((request) async {
      return http.Response(
        jsonEncode({'success': false, 'message': 'Could not save your acceptance.'}),
        500,
        headers: {'content-type': 'application/json'},
      );
    }));

    expect(find.text('HOME_PLACEHOLDER'), findsNothing);
    expect(find.byType(TermsAcceptanceScreen), findsOneWidget);
    expect(find.textContaining('Could not save'), findsOneWidget);
  });

  testWidgets('Log out instead routes back to Login, not stuck on the Terms screen forever', (
    tester,
  ) async {
    await pumpScreen(tester);

    await tester.tap(find.text('Log out instead'));
    await settle(tester);

    expect(find.text('LOGIN_PLACEHOLDER'), findsOneWidget);
  });
}
