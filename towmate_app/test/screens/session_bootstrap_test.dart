import 'dart:async';
import 'dart:convert';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:towmate_app/core/session_coordinator.dart';
import 'package:towmate_app/main.dart';
import 'package:towmate_app/screens/customer/home_screen.dart';
import 'package:towmate_app/screens/customer/login_screen.dart';
import 'package:towmate_app/screens/customer/terms_acceptance_screen.dart';
import 'package:towmate_app/screens/team_leader/tl_force_password_screen.dart';
import 'package:towmate_app/screens/team_leader/tl_home_screen.dart';
import 'package:towmate_app/services/api_service.dart';
import 'package:towmate_app/services/tl_presence_controller.dart';

http.Response _profile(Map<String, dynamic> body, [int code = 200]) =>
    http.Response(jsonEncode(body), code, headers: {'content-type': 'application/json'});

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    await ApiService.clearSession();
    SessionCoordinator.reset();
  });

  Future<void> pumpApp(WidgetTester tester, http.Client client) async {
    await http.runWithClient(() async {
      await tester.pumpWidget(const MyApp());
      for (var i = 0; i < 12; i++) {
        await tester.pump(const Duration(milliseconds: 50));
      }
    }, () => client);
  }

  void seedLoggedInPrefs() {
    SharedPreferences.setMockInitialValues({
      'onboarding_complete': true,
      'auth_token': 'valid-token',
      'user_role': 'Customer',
      'user_name': 'Jane',
      'user_id': 7,
    });
  }

  testWidgets('cold start, valid token, current terms resolves to Home', (tester) async {
    seedLoggedInPrefs();
    final client = MockClient((r) async => _profile({
          'success': true,
          'requires_terms_acceptance': false,
        }));

    await pumpApp(tester, client);

    expect(find.byType(HomeScreen), findsOneWidget);
    expect(await ApiService.getToken(), 'valid-token');
  });

  testWidgets('cold start, valid token, stale terms resolves to Terms Acceptance and keeps the token', (tester) async {
    seedLoggedInPrefs();
    final client = MockClient((r) async => _profile({
          'success': true,
          'requires_terms_acceptance': true,
        }));

    await pumpApp(tester, client);

    expect(find.byType(TermsAcceptanceScreen), findsOneWidget);
    expect(find.byType(HomeScreen), findsNothing);
    expect(await ApiService.getToken(), 'valid-token');
  });

  testWidgets('cold start, invalid/revoked token clears the session and resolves to Login', (tester) async {
    seedLoggedInPrefs();
    final client = MockClient((r) async => _profile({'message': 'Unauthenticated.'}, 401));

    await pumpApp(tester, client);

    expect(find.byType(LoginScreen), findsOneWidget);
    expect(await ApiService.getToken(), isNull);
    expect(await ApiService.getUserRole(), isNull);
  });

  testWidgets('cold start, server error during validation keeps the session and uses the cached role', (tester) async {
    seedLoggedInPrefs();
    final client = MockClient((r) async => http.Response('Internal Server Error', 500));

    await pumpApp(tester, client);

    expect(find.byType(HomeScreen), findsOneWidget);
    expect(await ApiService.getToken(), 'valid-token');
  });

  void seedTeamLeaderPrefs({required bool mustChange}) {
    SharedPreferences.setMockInitialValues({
      'onboarding_complete': true,
      'auth_token': 'tl-token',
      'user_role': 'Team Leader',
      'user_name': 'Tony',
      'user_id': 9,
      'must_change_password': mustChange,
    });
  }

  testWidgets('cold start, valid Team Leader token resolves to TL Home', (tester) async {
    seedTeamLeaderPrefs(mustChange: false);
    final client = MockClient((r) async => _profile({'success': true}));

    await pumpApp(tester, client);
    TlPresenceController.stop();

    expect(find.byType(TlHomeScreen), findsOneWidget);
    expect(find.byType(TlForcePasswordScreen), findsNothing);
    expect(await ApiService.getToken(), 'tl-token');
  });

  testWidgets('cold start, Team Leader with must_change_password resolves to forced password change', (tester) async {
    seedTeamLeaderPrefs(mustChange: true);
    final client = MockClient((r) async => _profile({'success': true}));

    await pumpApp(tester, client);
    TlPresenceController.stop();

    expect(find.byType(TlForcePasswordScreen), findsOneWidget);
    expect(find.byType(TlHomeScreen), findsNothing);
    expect(await ApiService.getToken(), 'tl-token');
  });

  testWidgets('cold start, revoked Team Leader token goes to Login and does not start presence', (tester) async {
    seedTeamLeaderPrefs(mustChange: false);
    final client = MockClient((r) async => _profile({'message': 'Unauthenticated.'}, 401));

    await pumpApp(tester, client);

    expect(find.byType(LoginScreen), findsOneWidget);
    expect(TlPresenceController.isActive, isFalse);
    expect(await ApiService.getToken(), isNull);
  });

  testWidgets('after logout (cleared session) a refresh reaches no authenticated route and sends no validation request', (tester) async {
    seedLoggedInPrefs();
    await ApiService.clearSession();
    SessionCoordinator.reset();
    var requests = 0;
    final client = MockClient((r) async {
      requests++;
      return _profile({'success': true});
    });

    await pumpApp(tester, client);

    expect(find.byType(HomeScreen), findsNothing);
    expect(find.byType(TlHomeScreen), findsNothing);
    expect(find.byType(LoginScreen), findsOneWidget);
    expect(requests, 0);
  });

  testWidgets('cold start with no token and onboarding complete resolves to Login', (tester) async {
    SharedPreferences.setMockInitialValues({'onboarding_complete': true});
    final client = MockClient((r) async => _profile({'success': true}));

    await pumpApp(tester, client);

    expect(find.byType(LoginScreen), findsOneWidget);
  });

  testWidgets('cold start, timeout during validation keeps the session and uses the cached role', (tester) async {
    seedLoggedInPrefs();
    final client = MockClient((r) async => throw TimeoutException('slow'));

    await pumpApp(tester, client);

    expect(find.byType(HomeScreen), findsOneWidget);
    expect(await ApiService.getToken(), 'valid-token');
  });
}
