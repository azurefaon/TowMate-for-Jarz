import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/customer/login_screen.dart';
import 'package:towmate_app/screens/customer/signup_screen.dart';

const _errorRed = Color(0xFFE53935);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  Future<void> pumpLogin(WidgetTester tester, {double width = 360}) async {
    SharedPreferences.setMockInitialValues({});
    tester.view.physicalSize = Size(width, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(const MaterialApp(home: LoginScreen()));
    await tester.pump();
    await tester.pump();
  }

  Future<void> submit(WidgetTester tester) async {
    final btn = find.text('Sign in');
    await tester.ensureVisible(btn);
    await tester.tap(btn);
    await tester.pump();
    await tester.pump();
  }

  Finder fieldBox(int index) => find
      .ancestor(of: find.byType(TextField).at(index), matching: find.byType(AnimatedContainer))
      .first;

  testWidgets('demo TL email passes login validation (no email error)', (tester) async {
    await pumpLogin(tester);
    await tester.enterText(find.byType(TextField).at(0), 'tl.assigned.demo@example.com');
    await submit(tester);

    expect(find.text('Please use a Gmail address.'), findsNothing);
    expect(find.text('Enter a valid email address.'), findsNothing);
    expect(find.text('Email is required'), findsNothing);
  });

  testWidgets('a Gmail address passes login validation', (tester) async {
    await pumpLogin(tester);
    await tester.enterText(find.byType(TextField).at(0), 'customer@gmail.com');
    await submit(tester);

    expect(find.text('Enter a valid email address.'), findsNothing);
    expect(find.text('Email is required'), findsNothing);
  });

  testWidgets('empty and malformed emails fail', (tester) async {
    await pumpLogin(tester);
    await submit(tester);
    expect(find.text('Email is required'), findsOneWidget);

    await tester.enterText(find.byType(TextField).at(0), 'not-an-email');
    await submit(tester);
    expect(find.text('Enter a valid email address.'), findsOneWidget);
  });

  testWidgets('error text is below the rounded field and the border turns red', (tester) async {
    await pumpLogin(tester);
    await tester.enterText(find.byType(TextField).at(0), 'bad');
    await submit(tester);

    final box = tester.getRect(fieldBox(0));
    final err = tester.getRect(find.byKey(const Key('login_field_error')).first);
    expect(err.top, greaterThanOrEqualTo(box.bottom + 4));
    expect(err.top, lessThanOrEqualTo(box.bottom + 8));

    final deco = tester.widget<AnimatedContainer>(fieldBox(0)).decoration as BoxDecoration;
    expect((deco.border as Border).top.color, _errorRed);

    // The input itself lives inside the rounded container.
    final input = tester.getRect(find.byType(TextField).at(0));
    expect(box.contains(input.center), isTrue);
  });

  testWidgets('password eye icon stays put when its error appears', (tester) async {
    await pumpLogin(tester);
    final toggle = find.byKey(const Key('login_password_toggle'));
    final relBefore = tester.getCenter(toggle) - tester.getRect(fieldBox(1)).topLeft;


    await tester.enterText(find.byType(TextField).at(0), 'a@b.co');
    await submit(tester); // empty password -> error
    expect(find.text('Password is required'), findsOneWidget);

    final boxAfter = tester.getRect(fieldBox(1));
    final after = tester.getCenter(toggle);
    expect(after - boxAfter.topLeft, relBefore);
    expect(boxAfter.contains(after), isTrue);
    final err = tester.getRect(find.byKey(const Key('login_field_error')).last);
    expect(err.top, greaterThan(boxAfter.bottom));
  });

  for (final w in [320.0, 360.0]) {
    testWidgets('no overflow with errors at $w px', (tester) async {
      await pumpLogin(tester, width: w);
      await tester.enterText(find.byType(TextField).at(0), 'bad');
      await submit(tester);
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('signup still rejects non-Gmail and accepts Gmail', (tester) async {
    SharedPreferences.setMockInitialValues({});
    tester.view.physicalSize = const Size(400, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(const MaterialApp(home: SignupScreen()));
    await tester.pump();

    final email = find.byType(TextField).at(3);
    final create = find.text('Create account');
    await tester.ensureVisible(find.byType(Checkbox));
    await tester.tap(find.byType(Checkbox));
    await tester.pump();

    await tester.enterText(email, 'x@example.com');
    await tester.ensureVisible(create);
    await tester.tap(create);
    await tester.pump();
    await tester.pump();
    expect(find.text('Please use a Gmail address.'), findsOneWidget);

    await tester.enterText(email, 'x@gmail.com');
    await tester.pump();
    await tester.pump();
    expect(find.text('Please use a Gmail address.'), findsNothing);
  });
}
