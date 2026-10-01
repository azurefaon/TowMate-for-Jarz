import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/customer/login_screen.dart';
import 'package:towmate_app/screens/customer/signup_screen.dart';

const _authFiles = [
  'lib/screens/customer/login_screen.dart',
  'lib/screens/customer/signup_screen.dart',
  'lib/screens/customer/forgot_password_screen.dart',
  'lib/screens/customer/email_otp_screen.dart',
  'lib/screens/customer/reset_otp_screen.dart',
  'lib/screens/customer/reset_password_screen.dart',
  'lib/screens/customer/google_phone_completion_screen.dart',
  'lib/screens/customer/terms_acceptance_screen.dart',
];

void main() {
  test('the canonical auth yellow is the shared TmColors.yellow used by Forgot Password', () {
    expect(TmColors.yellow, const Color(0xFFFACC15));
    expect(
      File('lib/screens/customer/forgot_password_screen.dart').readAsStringSync(),
      contains('backgroundColor: TmColors.yellow'),
    );
  });

  test('no auth screen keeps the old orange accent', () {
    for (final path in _authFiles) {
      final source = File(path).readAsStringSync();
      expect(source.contains('F5A623'), isFalse, reason: path);
      expect(source.contains('E8960D'), isFalse, reason: path);
      expect(source.contains('_buttonGradientEnd'), isFalse, reason: path);
    }
  });

  test('login and signup take their accent from the shared token', () {
    for (final path in [
      'lib/screens/customer/login_screen.dart',
      'lib/screens/customer/signup_screen.dart',
    ]) {
      expect(File(path).readAsStringSync(), contains('const _brand = TmColors.yellow;'), reason: path);
    }
  });

  testWidgets('the login Mate wordmark and Sign in button use the canonical yellow', (tester) async {
    SharedPreferences.setMockInitialValues({});
    tester.view.physicalSize = const Size(400, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(const MaterialApp(home: LoginScreen()));
    await tester.pump();

    final mate = tester.widgetList<RichText>(find.byType(RichText)).expand((r) {
      final spans = <TextSpan>[];
      r.text.visitChildren((s) {
        if (s is TextSpan && s.text == 'Mate') spans.add(s);
        return true;
      });
      return spans;
    }).toList();
    expect(mate, isNotEmpty);
    expect(mate.first.style?.color, TmColors.yellow);

    final buttons = tester.widgetList<Container>(find.byType(Container)).where((c) {
      final d = c.decoration;
      return d is BoxDecoration && d.color == TmColors.yellow && d.gradient == null;
    });
    expect(buttons, isNotEmpty);
  });

  testWidgets('the signup Create account button uses the canonical yellow with no gradient', (tester) async {
    SharedPreferences.setMockInitialValues({});
    tester.view.physicalSize = const Size(400, 2000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(const MaterialApp(home: SignupScreen()));
    await tester.pump();

    final buttons = tester.widgetList<Container>(find.byType(Container)).where((c) {
      final d = c.decoration;
      return d is BoxDecoration && d.color == TmColors.yellow && d.gradient == null;
    });
    expect(buttons, isNotEmpty);
  });

  testWidgets('signup shows first, optional middle and last name without mobile overflow', (tester) async {
    SharedPreferences.setMockInitialValues({});
    for (final width in [320.0, 360.0, 412.0]) {
      tester.view.physicalSize = Size(width, 900);
      tester.view.devicePixelRatio = 1.0;
      await tester.pumpWidget(const MaterialApp(home: SignupScreen()));
      await tester.pump();

      expect(find.text('FIRST NAME'), findsOneWidget);
      expect(find.text('MIDDLE NAME (OPTIONAL)'), findsOneWidget);
      expect(find.text('LAST NAME'), findsOneWidget);
      expect(tester.takeException(), isNull, reason: 'width $width');
    }
    addTearDown(tester.view.reset);
  });
}
