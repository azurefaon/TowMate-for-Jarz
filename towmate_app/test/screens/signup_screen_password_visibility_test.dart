import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/customer/signup_screen.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  SharedPreferences.setMockInitialValues({});

  Future<void> pumpSignup(WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(home: SignupScreen()));
    await tester.pump();
    await tester.pump();
  }

  group('Signup Password and Confirm Password visibility', () {
    testWidgets('both password fields start obscured with two visibility icons shown', (tester) async {
      await pumpSignup(tester);

      expect(find.byIcon(Icons.visibility_outlined), findsNWidgets(2));
      expect(find.byIcon(Icons.visibility_off_outlined), findsNothing);
    });

    testWidgets('revealing Password does not reveal Confirm password', (tester) async {
      await pumpSignup(tester);

      await tester.tap(find.byIcon(Icons.visibility_outlined).first);
      await tester.pump();

      expect(find.byIcon(Icons.visibility_off_outlined), findsOneWidget);
      expect(find.byIcon(Icons.visibility_outlined), findsOneWidget);
    });

    testWidgets('revealing Confirm password does not reveal Password', (tester) async {
      await pumpSignup(tester);

      await tester.ensureVisible(find.byIcon(Icons.visibility_outlined).last);
      await tester.pump();
      await tester.tap(find.byIcon(Icons.visibility_outlined).last);
      await tester.pump();

      expect(find.byIcon(Icons.visibility_off_outlined), findsOneWidget);
      expect(find.byIcon(Icons.visibility_outlined), findsOneWidget);
    });

    testWidgets('each field toggles independently and keeps its own controller text', (tester) async {
      await pumpSignup(tester);

      final textFields = find.byType(TextField);
      await tester.ensureVisible(textFields.at(4));
      await tester.enterText(textFields.at(4), 'PasswordOne!1');
      await tester.ensureVisible(textFields.at(5));
      await tester.enterText(textFields.at(5), 'PasswordTwo!2');
      await tester.pump();

      await tester.ensureVisible(find.byIcon(Icons.visibility_outlined).first);
      await tester.pump();
      await tester.tap(find.byIcon(Icons.visibility_outlined).first);
      await tester.pump();

      final fields = tester.widgetList<TextField>(find.byType(TextField)).toList();
      final revealed = fields.firstWhere((f) => f.controller?.text == 'PasswordOne!1');
      final stillHidden = fields.firstWhere((f) => f.controller?.text == 'PasswordTwo!2');

      expect(revealed.obscureText, isFalse);
      expect(stillHidden.obscureText, isTrue);
    });
  });
}
