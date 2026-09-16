import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/customer/login_screen.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  SharedPreferences.setMockInitialValues({});

  Future<void> pumpLogin(WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(home: LoginScreen()));
    await tester.pump();
    await tester.pump();
  }

  group('Login password visibility', () {
    testWidgets('the password field starts obscured with the visibility icon shown', (tester) async {
      await pumpLogin(tester);

      final field = tester.widget<TextField>(find.byType(TextField).last);
      expect(field.obscureText, isTrue);
      expect(find.byIcon(Icons.visibility), findsOneWidget);
      expect(find.byIcon(Icons.visibility_off), findsNothing);
    });

    testWidgets('tapping the eye reveals the password and swaps the icon', (tester) async {
      await pumpLogin(tester);

      await tester.enterText(find.byType(TextFormField).last, 'MyPassword!123');
      await tester.pump();

      await tester.tap(find.byIcon(Icons.visibility));
      await tester.pump();

      final field = tester.widget<TextField>(find.byType(TextField).last);
      expect(field.obscureText, isFalse);
      expect(field.controller!.text, 'MyPassword!123');
      expect(find.byIcon(Icons.visibility_off), findsOneWidget);
      expect(find.byIcon(Icons.visibility), findsNothing);
    });

    testWidgets('tapping the eye a second time hides the password again', (tester) async {
      await pumpLogin(tester);

      await tester.tap(find.byIcon(Icons.visibility));
      await tester.pump();
      await tester.tap(find.byIcon(Icons.visibility_off));
      await tester.pump();

      final field = tester.widget<TextField>(find.byType(TextField).last);
      expect(field.obscureText, isTrue);
      expect(find.byIcon(Icons.visibility), findsOneWidget);
    });
  });
}
