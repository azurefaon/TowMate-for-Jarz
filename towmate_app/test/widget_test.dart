import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:towmate_app/main.dart';
import 'package:towmate_app/screens/customer/get_started_screen.dart';
import 'package:towmate_app/screens/customer/login_screen.dart';

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  Future<void> pumpApp(WidgetTester tester) async {
    await tester.pumpWidget(const MyApp());
    for (var i = 0; i < 10; i++) {
      await tester.pump(const Duration(milliseconds: 50));
    }
  }

  testWidgets(
    'app launches and resolves to Get Started on first launch when signed out',
    (tester) async {
      SharedPreferences.setMockInitialValues({});

      await pumpApp(tester);

      expect(find.byType(GetStartedScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'app launches and resolves to Login when signed out after onboarding is complete',
    (tester) async {
      SharedPreferences.setMockInitialValues({'onboarding_complete': true});

      await pumpApp(tester);

      expect(find.byType(LoginScreen), findsOneWidget);
      expect(find.byType(GetStartedScreen), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );
}
