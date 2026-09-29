import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/customer/privacy_policy_screen.dart';
import 'package:towmate_app/screens/customer/terms_of_use_screen.dart';

Future<void> settle(WidgetTester tester) async {
  for (var i = 0; i < 12; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('TermsOfUseScreen', () {
    testWidgets('renders the Owner-published content and version fetched from the backend', (
      tester,
    ) async {
      await http.runWithClient(() async {
        await tester.pumpWidget(
          const MaterialApp(home: TermsOfUseScreen()),
        );
        await settle(tester);

        expect(find.text('Version 2.3'), findsOneWidget);
        expect(find.text('Owner-published intro paragraph.'), findsOneWidget);
        expect(find.text('Custom Heading From Owner'), findsOneWidget);
        expect(find.text('Custom body text the Owner typed in the dashboard.'), findsOneWidget);
      }, () => MockClient((request) async {
        if (request.url.path.contains('customer/content')) {
          return http.Response(
            jsonEncode({
              'terms_of_use': {
                'version': '2.3',
                'content': 'Owner-published intro paragraph.\n\n'
                    '## Custom Heading From Owner\n'
                    'Custom body text the Owner typed in the dashboard.',
              },
              'privacy_policy': {'version': null, 'content': null},
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response('{}', 404);
      }));
    });

    testWidgets('falls back to the built-in document when the backend has no content yet', (
      tester,
    ) async {
      await http.runWithClient(() async {
        await tester.pumpWidget(
          const MaterialApp(home: TermsOfUseScreen()),
        );
        await settle(tester);

        expect(find.text('Version 1.0'), findsOneWidget);
        expect(find.text('1. Account Registration'), findsOneWidget);
      }, () => MockClient((request) async {
        return http.Response(
          jsonEncode({
            'terms_of_use': {'version': null, 'content': null},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }));
    });

    testWidgets('falls back to the built-in document when the request fails outright', (
      tester,
    ) async {
      await http.runWithClient(() async {
        await tester.pumpWidget(
          const MaterialApp(home: TermsOfUseScreen()),
        );
        await settle(tester);

        expect(find.text('Version 1.0'), findsOneWidget);
        expect(find.text('1. Account Registration'), findsOneWidget);
        expect(tester.takeException(), isNull);
      }, () => MockClient((request) async {
        return http.Response('Internal Server Error', 500);
      }));
    });
  });

  group('PrivacyPolicyScreen', () {
    testWidgets('renders the Owner-published content and version fetched from the backend', (
      tester,
    ) async {
      await http.runWithClient(() async {
        await tester.pumpWidget(
          const MaterialApp(home: PrivacyPolicyScreen()),
        );
        await settle(tester);

        expect(find.text('Version 4.1'), findsOneWidget);
        expect(find.text('Owner-published privacy intro.'), findsOneWidget);
      }, () => MockClient((request) async {
        return http.Response(
          jsonEncode({
            'privacy_policy': {
              'version': '4.1',
              'content': 'Owner-published privacy intro.',
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }));
    });

    testWidgets('falls back to the built-in document when the backend has no content yet', (
      tester,
    ) async {
      await http.runWithClient(() async {
        await tester.pumpWidget(
          const MaterialApp(home: PrivacyPolicyScreen()),
        );
        await settle(tester);

        expect(find.text('Version 1.0'), findsOneWidget);
        expect(find.text('1. Information You Provide'), findsOneWidget);
      }, () => MockClient((request) async {
        return http.Response(
          jsonEncode({
            'privacy_policy': {'version': null, 'content': null},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }));
    });
  });
}
