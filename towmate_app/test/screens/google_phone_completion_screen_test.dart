import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/customer/google_phone_completion_screen.dart';

void main() {
  late String source;

  setUpAll(() {
    source = File(
      'lib/screens/customer/google_phone_completion_screen.dart',
    ).readAsStringSync();
  });

  group('Google phone completion — Terms of Use / Privacy Policy acceptance', () {
    test('a TermsAgreementCheckbox is rendered on the phone completion screen', () {
      expect(source.contains('TermsAgreementCheckbox('), isTrue);
    });

    test('the Continue button is disabled until the Terms checkbox is checked', () {
      expect(
        source.contains('onPressed: (_isLoading || !_acceptTerms) ? null : _submit'),
        isTrue,
      );
    });

    test('tapping Terms of Use / Privacy Policy opens the in-app readable screens', () {
      expect(source.contains('const TermsOfUseScreen()'), isTrue);
      expect(source.contains('const PrivacyPolicyScreen()'), isTrue);
      expect(source.contains('onTermsTap: _openTerms'), isTrue);
      expect(source.contains('onPrivacyTap: _openPrivacy'), isTrue);
    });

    test('the accepted value is forwarded to the Google completion API call', () {
      expect(source.contains('acceptTerms: _acceptTerms'), isTrue);
    });

    test('the phone number requirement is still present, unchanged by the Terms addition', () {
      expect(source.contains('ApiService.completeGoogleSignup('), isTrue);
      expect(source.contains('phone: phone'), isTrue);
      expect(source.contains('PhMobilePhone.validateLocal('), isTrue);
    });
  });
  group('Google completion — canonical first / optional middle / required last name', () {
    Future<List<Map<String, dynamic>>> pumpAndSubmit(
      WidgetTester tester, {
      required String firstName,
      required String lastName,
      List<String> enter = const [],
    }) async {
      final bodies = <Map<String, dynamic>>[];
      final client = MockClient((request) async {
        bodies.add(jsonDecode(request.body) as Map<String, dynamic>);
        return http.Response(
          jsonEncode({'success': false, 'message': 'stop'}),
          422,
          headers: {'content-type': 'application/json'},
        );
      });
      tester.view.physicalSize = const Size(400, 1600);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);
      SharedPreferences.setMockInitialValues({});
      await http.runWithClient(() async {
        await tester.pumpWidget(MaterialApp(
          home: GooglePhoneCompletionScreen(
            completionToken: 'tok',
            firstName: firstName,
            lastName: lastName,
          ),
        ));
        await tester.pump();
        final fields = find.byType(TextFormField);
        for (var i = 0; i < enter.length; i++) {
          if (enter[i].isNotEmpty) await tester.enterText(fields.at(i), enter[i]);
        }
        await tester.enterText(fields.at(3), '9171234567');
        await tester.ensureVisible(find.byType(Checkbox));
        await tester.tap(find.byType(Checkbox));
        await tester.pump();
        await tester.ensureVisible(find.text('Continue'));
        await tester.tap(find.text('Continue'));
        await tester.pump(const Duration(milliseconds: 200));
      }, () => client);
      return bodies;
    }

    testWidgets('prefills the structured Google names and keeps middle name optional', (tester) async {
      final bodies = await pumpAndSubmit(tester, firstName: 'Maria', lastName: 'Reyes');

      final fields = tester.widgetList<TextFormField>(find.byType(TextFormField)).toList();
      expect(fields[0].controller?.text, 'Maria');
      expect(fields[1].controller?.text, '');
      expect(fields[2].controller?.text, 'Reyes');
      expect(find.text('Middle name (optional)'), findsOneWidget);
      expect(bodies, hasLength(1));
      expect(bodies.single['first_name'], 'Maria');
      expect(bodies.single['last_name'], 'Reyes');
      expect(bodies.single.containsKey('middle_name'), isFalse);
    });

    testWidgets('a missing family name blocks submission until a last name is entered', (tester) async {
      final bodies = await pumpAndSubmit(tester, firstName: 'Maria', lastName: '');

      expect(find.text('Last name is required'), findsOneWidget);
      expect(bodies, isEmpty);
    });

    testWidgets('the entered last name and optional middle name are sent', (tester) async {
      final bodies = await pumpAndSubmit(
        tester,
        firstName: 'Maria',
        lastName: '',
        enter: ['', 'Luz', 'Reyes'],
      );

      expect(bodies.single['first_name'], 'Maria');
      expect(bodies.single['middle_name'], 'Luz');
      expect(bodies.single['last_name'], 'Reyes');
    });

    testWidgets('a missing given name also requires a first name', (tester) async {
      final bodies = await pumpAndSubmit(tester, firstName: '', lastName: 'Reyes');

      expect(find.text('First name is required'), findsOneWidget);
      expect(bodies, isEmpty);
    });
  });
}
