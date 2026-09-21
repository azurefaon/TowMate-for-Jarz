import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/customer/signup_screen.dart';

const _brand = Color(0xFFF5A623);
const _textPrimary = Color(0xFF111111);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  SharedPreferences.setMockInitialValues({});

  Future<void> pumpSignup(WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(home: SignupScreen()));
    await tester.pump();
    await tester.pump();
  }

  group('Signup field styling', () {
    testWidgets('EMAIL label stays matte black/dark when unfocused', (tester) async {
      await pumpSignup(tester);

      final labelText = tester.widget<Text>(find.text('EMAIL'));
      expect(labelText.style?.color, _textPrimary);
    });

    testWidgets('EMAIL label stays matte black/dark when the field is focused', (tester) async {
      await pumpSignup(tester);

      await tester.tap(find.byType(TextField).at(2));
      await tester.pump();

      final labelText = tester.widget<Text>(find.text('EMAIL'));
      expect(labelText.style?.color, _textPrimary);
    });

    testWidgets('PASSWORD label stays matte black/dark when focused', (tester) async {
      await pumpSignup(tester);

      await tester.ensureVisible(find.byType(TextField).at(4));
      await tester.tap(find.byType(TextField).at(4));
      await tester.pump();

      final labelText = tester.widget<Text>(find.text('PASSWORD'));
      expect(labelText.style?.color, _textPrimary);
    });

    testWidgets('CONFIRM PASSWORD label stays matte black/dark when focused', (tester) async {
      await pumpSignup(tester);

      await tester.ensureVisible(find.byType(TextField).at(5));
      await tester.tap(find.byType(TextField).at(5));
      await tester.pump();

      final labelText = tester.widget<Text>(find.text('CONFIRM PASSWORD'));
      expect(labelText.style?.color, _textPrimary);
    });

    testWidgets('a focused field does not use the orange brand color for its border', (tester) async {
      await pumpSignup(tester);

      await tester.tap(find.byType(TextField).at(2));
      await tester.pump();

      final container = tester
          .widgetList<AnimatedContainer>(find.byType(AnimatedContainer))
          .elementAt(2);
      final decoration = container.decoration! as BoxDecoration;
      final border = decoration.border! as Border;

      expect(border.top.color, isNot(_brand));
      expect(border.top.width, lessThanOrEqualTo(1.5));
    });

    testWidgets('no field container shows a boxShadow glow, focused or not', (tester) async {
      await pumpSignup(tester);

      await tester.tap(find.byType(TextField).at(2));
      await tester.pump();

      for (final container
          in tester.widgetList<AnimatedContainer>(find.byType(AnimatedContainer))) {
        final decoration = container.decoration! as BoxDecoration;
        expect(decoration.boxShadow, isNull);
      }
    });

    testWidgets('First Name and Last Name use instructional placeholders, not example names', (
      tester,
    ) async {
      await pumpSignup(tester);

      expect(find.text('Enter your first name'), findsOneWidget);
      expect(find.text('Enter your last name'), findsOneWidget);
      expect(find.text('John'), findsNothing);
      expect(find.text('Doe'), findsNothing);
    });

    testWidgets('Email, Password and Confirm Password use instructional placeholders', (
      tester,
    ) async {
      await pumpSignup(tester);

      expect(find.text('Enter your Gmail address'), findsOneWidget);
      expect(find.text('Enter your password'), findsOneWidget);
      expect(find.text('Confirm your password'), findsOneWidget);
    });

    testWidgets('a validation error renders below the field, outside its bordered container', (
      tester,
    ) async {
      await pumpSignup(tester);

      await tester.enterText(find.byType(TextField).at(2), 'someone@yahoo.com');
      await tester.pump();
      await tester.tap(find.byType(TextField).at(0));
      await tester.pump();

      final errorFinder = find.text('Please use a Gmail address.');
      expect(errorFinder, findsOneWidget);

      final emailContainer = find.byType(AnimatedContainer).at(2);
      expect(
        find.descendant(of: emailContainer, matching: errorFinder),
        findsNothing,
      );
    });

    testWidgets(
      'Confirm Password mismatch renders below the field, outside its bordered container',
      (tester) async {
        await pumpSignup(tester);

        await tester.ensureVisible(find.byType(TextField).at(4));
        await tester.enterText(find.byType(TextField).at(4), 'ValidPass!2024xy');
        await tester.pump();
        await tester.ensureVisible(find.byType(TextField).at(5));
        await tester.enterText(find.byType(TextField).at(5), 'Different!2024xy');
        await tester.pump();

        final errorFinder = find.text('Passwords do not match');
        expect(errorFinder, findsOneWidget);

        final confirmContainer = find.byType(AnimatedContainer).at(5);
        expect(
          find.descendant(of: confirmContainer, matching: errorFinder),
          findsNothing,
        );
      },
    );
  });
}
