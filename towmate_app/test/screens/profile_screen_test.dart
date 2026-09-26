import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/route_observer.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/customer/edit_profile_screen.dart';
import 'package:towmate_app/screens/customer/profile_screen.dart';
import 'package:towmate_app/widgets/skeleton_box.dart';
import 'package:towmate_app/widgets/tm_bottom_nav.dart';

final _secureStorageData = <String, String>{};

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

final _profileFixture = {
  'data': {
    'name': 'Faon Delacruz',
    'first_name': 'Faon',
    'last_name': 'Delacruz',
    'email': 'faon@example.com',
    'phone': '+639171234567',
    'auth_provider': 'manual',
  },
};

http.Client _buildClient({Map<String, dynamic>? profileOverride, bool slowProfile = false}) {
  return MockClient((request) async {
    final path = request.url.path;
    if (path.endsWith('/v1/profile')) {
      if (slowProfile) await Future<void>.delayed(const Duration(seconds: 2));
      return _json(profileOverride ?? _profileFixture);
    }
    if (path.endsWith('/v1/profile/image')) {
      return _json({}, status: 404);
    }
    return _json({'success': false}, status: 404);
  });
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pumpProfile(
  WidgetTester tester, {
  Map<String, dynamic>? profileOverride,
  ThemeData? theme,
  ThemeMode? themeMode,
}) async {
  SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
  await http.runWithClient(
    () async {
      await tester.pumpWidget(
        MaterialApp(
          theme: theme,
          themeMode: themeMode,
          home: const ProfileScreen(),
        ),
      );
      await _settle(tester);
    },
    () => _buildClient(profileOverride: profileOverride),
  );
}

Future<void> _pumpProfileWithEditRoute(WidgetTester tester) async {
  SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
  await http.runWithClient(
    () async {
      await tester.pumpWidget(MaterialApp(
        onGenerateRoute: (settings) => MaterialPageRoute(
          builder: (_) => switch (settings.name) {
            '/edit-profile' => const Scaffold(body: Text('Edit Profile Screen')),
            _ => const ProfileScreen(),
          },
        ),
      ));
      await _settle(tester);
    },
    () => _buildClient(),
  );
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  FlutterSecureStorage.setMockInitialValues(_secureStorageData);

  setUp(() => _secureStorageData.clear());

  group('ProfileScreen', () {
    testWidgets('renders a loading skeleton, not a spinner, before content arrives', (tester) async {
      SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: ProfileScreen()));
          await tester.pump();
        },
        () => _buildClient(slowProfile: true),
      );

      expect(find.byType(SkeletonBox), findsWidgets);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(tester.takeException(), isNull);

      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('header and bottom nav remain visible during loading, with no back button', (tester) async {
      SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: ProfileScreen()));
          await tester.pump();
        },
        () => _buildClient(slowProfile: true),
      );

      expect(find.byIcon(Icons.arrow_back_rounded), findsNothing);
      expect(find.text('Tow'), findsNothing);
      expect(find.byType(RichText), findsWidgets);
      expect(find.byType(TmBottomNav), findsOneWidget);

      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('loaded profile renders real data and hides the skeleton', (tester) async {
      await _pumpProfile(tester);

      expect(find.text('Faon Delacruz'), findsWidgets);
      expect(find.text('ACCOUNT SETTINGS'), findsOneWidget);
      expect(find.text('APPEARANCE'), findsOneWidget);
      expect(find.text('Log out'), findsOneWidget);
      expect(find.byType(SkeletonBox), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('account settings rows show Name, Email, Phone, Password labels', (tester) async {
      await _pumpProfile(tester);

      expect(find.text('Name'), findsOneWidget);
      expect(find.text('Email'), findsOneWidget);
      expect(find.text('Phone'), findsOneWidget);
      expect(find.text('Password'), findsOneWidget);
      expect(find.text('••••••••'), findsOneWidget);
    });

    testWidgets('Google-managed account shows Managed by Google and Google password copy', (tester) async {
      await _pumpProfile(
        tester,
        profileOverride: {
          'data': {
            'name': 'Google User',
            'first_name': 'Google',
            'last_name': 'User',
            'email': 'gu@example.com',
            'phone': '+639170000000',
            'auth_provider': 'google',
          },
        },
      );

      expect(find.text('Managed by Google'), findsOneWidget);
      expect(find.text('Signed in with Google'), findsOneWidget);
      expect(find.text('••••••••'), findsNothing);
    });

    testWidgets('Dark Mode switch is present and toggling it does not throw', (tester) async {
      await _pumpProfile(tester);

      expect(find.text('Dark Mode'), findsOneWidget);
      final switchFinder = find.byType(Switch);
      expect(switchFinder, findsOneWidget);

      await tester.ensureVisible(switchFinder);
      await _settle(tester);
      await tester.tap(switchFinder);
      await _settle(tester);

      expect(tester.takeException(), isNull);
    });

    testWidgets('main profile displays the profile photo, full name, and email', (tester) async {
      await _pumpProfile(tester);

      expect(find.byType(CircleAvatar), findsOneWidget);
      expect(find.text('Faon Delacruz'), findsWidgets);
      expect(find.text('faon@example.com'), findsWidgets);
    });

    testWidgets('does not show a direct Change Photo label or button', (tester) async {
      await _pumpProfile(tester);

      expect(find.text('Change photo'), findsNothing);
      expect(find.byIcon(Icons.camera_alt_outlined), findsNothing);
    });

    testWidgets('shows an edit icon beside the name with a tooltip', (tester) async {
      await _pumpProfile(tester);

      expect(find.byIcon(Icons.edit_outlined), findsOneWidget);
      expect(find.byTooltip('Edit profile'), findsOneWidget);
    });

    testWidgets('tapping the edit icon opens the Edit Profile screen', (tester) async {
      await _pumpProfileWithEditRoute(tester);

      await tester.tap(find.byTooltip('Edit profile'));
      await _settle(tester);

      expect(find.text('Edit Profile Screen'), findsOneWidget);
    });

    testWidgets('tapping the Name settings row opens the Edit Profile screen', (tester) async {
      await _pumpProfileWithEditRoute(tester);

      await tester.tap(find.text('Name'));
      await _settle(tester);

      expect(find.text('Edit Profile Screen'), findsOneWidget);
    });

    testWidgets('after Edit Profile saves and pops, Main Profile reloads the updated name and re-fetches the photo', (tester) async {
      var currentName = 'Faon Delacruz';
      var currentFirst = 'Faon';
      var currentLast = 'Delacruz';
      var imageCallCount = 0;

      final client = MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/v1/profile') && request.method == 'GET') {
          return _json({
            'data': {
              'name': currentName,
              'first_name': currentFirst,
              'last_name': currentLast,
              'email': 'faon@example.com',
              'phone': '+639171234567',
              'auth_provider': 'manual',
            },
          });
        }
        if (path.endsWith('/v1/profile/image')) {
          imageCallCount++;
          return _json({}, status: 404);
        }
        if (path.endsWith('/v1/profile/update')) {
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          currentFirst = body['first_name'] as String;
          currentLast = body['last_name'] as String;
          currentName = '$currentFirst $currentLast';
          return _json({
            'success': true,
            'data': {'name': currentName, 'first_name': currentFirst, 'last_name': currentLast},
          });
        }
        return _json({'success': false}, status: 404);
      });

      SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(MaterialApp(
            navigatorObservers: [appRouteObserver],
            onGenerateRoute: (settings) => MaterialPageRoute(
              builder: (_) => switch (settings.name) {
                '/edit-profile' => const EditProfileScreen(),
                _ => const ProfileScreen(),
              },
            ),
          ));
          await _settle(tester);

          expect(find.text('Faon Delacruz'), findsWidgets);
          final imageCallsBeforeReturn = imageCallCount;

          await tester.tap(find.byTooltip('Edit profile'));
          await _settle(tester);

          await tester.enterText(find.byType(TextField), 'Maria Dela Cruz');
          await tester.tap(find.text('Save Changes'));
          await tester.pumpAndSettle(const Duration(milliseconds: 100));

          expect(find.text('Maria Dela Cruz'), findsWidgets);
          expect(imageCallCount, greaterThan(imageCallsBeforeReturn));
        },
        () => client,
      );
    });

    testWidgets('tapping Logout shows the confirmation dialog', (tester) async {
      await _pumpProfile(tester);

      await tester.ensureVisible(find.text('Log out'));
      await _settle(tester);
      await tester.tap(find.text('Log out'));
      await _settle(tester);

      expect(find.text('Log out?'), findsOneWidget);
    });

    for (final width in [320.0, 340.0, 360.0, 375.0, 390.0, 412.0]) {
      testWidgets('renders without horizontal overflow at $width px width', (tester) async {
        final originalSize = tester.view.physicalSize;
        final originalRatio = tester.view.devicePixelRatio;
        tester.view.physicalSize = Size(width, 800);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(() {
          tester.view.physicalSize = originalSize;
          tester.view.devicePixelRatio = originalRatio;
        });

        await _pumpProfile(tester);

        expect(tester.takeException(), isNull);
        expect(find.byType(TmBottomNav), findsOneWidget);
      });
    }

    testWidgets('renders correctly in light mode', (tester) async {
      await _pumpProfile(tester, theme: AppTheme.light, themeMode: ThemeMode.light);

      expect(tester.takeException(), isNull);
      expect(find.text('Faon Delacruz'), findsWidgets);
    });

    testWidgets('renders correctly in dark mode', (tester) async {
      await _pumpProfile(tester, theme: AppTheme.dark, themeMode: ThemeMode.dark);

      expect(tester.takeException(), isNull);
      expect(find.text('Faon Delacruz'), findsWidgets);
    });
  });
}
