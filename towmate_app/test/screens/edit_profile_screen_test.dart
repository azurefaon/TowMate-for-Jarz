import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:image_picker_platform_interface/image_picker_platform_interface.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/customer/edit_profile_screen.dart';

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

final _initialPngBytes = Uint8List.fromList([137, 80, 78, 71, 13, 10, 26, 10, 0, 0, 0, 13, 73, 72, 68, 82, 0, 0, 0, 1, 0, 0, 0, 1, 8, 2, 0, 0, 0, 144, 119, 83, 222, 0, 0, 0, 12, 73, 68, 65, 84, 120, 156, 99, 248, 207, 192, 0, 0, 3, 1, 1, 0, 201, 254, 146, 239, 0, 0, 0, 0, 73, 69, 78, 68, 174, 66, 96, 130]);
final _pickedPngBytes = Uint8List.fromList([137, 80, 78, 71, 13, 10, 26, 10, 0, 0, 0, 13, 73, 72, 68, 82, 0, 0, 0, 1, 0, 0, 0, 1, 8, 2, 0, 0, 0, 144, 119, 83, 222, 0, 0, 0, 12, 73, 68, 65, 84, 120, 156, 99, 96, 248, 207, 0, 0, 2, 2, 1, 0, 123, 9, 129, 120, 0, 0, 0, 0, 73, 69, 78, 68, 174, 66, 96, 130]);
final _updatedPngBytes = Uint8List.fromList([137, 80, 78, 71, 13, 10, 26, 10, 0, 0, 0, 13, 73, 72, 68, 82, 0, 0, 0, 1, 0, 0, 0, 1, 8, 2, 0, 0, 0, 144, 119, 83, 222, 0, 0, 0, 12, 73, 68, 65, 84, 120, 156, 99, 96, 96, 248, 15, 0, 1, 3, 1, 0, 8, 137, 194, 236, 0, 0, 0, 0, 73, 69, 78, 68, 174, 66, 96, 130]);

class _FakeImagePicker extends ImagePickerPlatform {
  _FakeImagePicker(this.result);
  final XFile? result;

  @override
  Future<XFile?> getImageFromSource({
    required ImageSource source,
    ImagePickerOptions options = const ImagePickerOptions(),
  }) async => result;
}

http.Client _buildClient({
  bool updateSucceeds = true,
  String updateFailureMessage = 'That name is not allowed.',
  bool uploadSucceeds = true,
  String uploadFailureMessage = 'Could not update your profile photo.',
  Uint8List? initialImageBytes,
  Uint8List? updatedImageBytes,
}) {
  var imageFetchCount = 0;
  return MockClient((request) async {
    final path = request.url.path;
    if (path.endsWith('/v1/profile') && request.method == 'GET') {
      return _json(_profileFixture);
    }
    if (path.endsWith('/v1/profile/image') && request.method == 'GET') {
      imageFetchCount++;
      final bytes = imageFetchCount == 1 ? initialImageBytes : updatedImageBytes;
      if (bytes == null) return _json({}, status: 404);
      return http.Response.bytes(bytes, 200);
    }
    if (path.endsWith('/v1/profile/image') && request.method == 'POST') {
      if (!uploadSucceeds) {
        return _json({'success': false, 'message': uploadFailureMessage}, status: 422);
      }
      return _json({'success': true});
    }
    if (path.endsWith('/v1/profile/update')) {
      if (!updateSucceeds) {
        return _json({'success': false, 'message': updateFailureMessage}, status: 422);
      }
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      return _json({
        'success': true,
        'data': {
          'name': '${body['first_name']} ${body['last_name']}',
          'first_name': body['first_name'],
          'last_name': body['last_name'],
        },
      });
    }
    return _json({'success': false}, status: 404);
  });
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pumpEditProfile(
  WidgetTester tester, {
  http.Client? client,
  double width = 390,
  ThemeData? theme,
  Future<void> Function()? interact,
}) async {
  SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  await http.runWithClient(
    () async {
      await tester.pumpWidget(MaterialApp(
        theme: theme,
        home: const EditProfileScreen(),
      ));
      await _settle(tester);
      if (interact != null) await interact();
    },
    () => client ?? _buildClient(),
  );
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  FlutterSecureStorage.setMockInitialValues(_secureStorageData);

  setUp(() => _secureStorageData.clear());

  group('EditProfileScreen', () {
    testWidgets('has a back button', (tester) async {
      await _pumpEditProfile(tester);

      expect(find.byIcon(Icons.arrow_back_rounded), findsOneWidget);
    });

    testWidgets('loads and prefills the current full name', (tester) async {
      await _pumpEditProfile(tester);

      final field = tester.widget<TextField>(find.byType(TextField));
      expect(field.controller?.text, 'Faon Delacruz');
    });

    testWidgets('email is displayed read-only, not in an editable field', (tester) async {
      await _pumpEditProfile(tester);

      expect(find.text('faon@example.com'), findsOneWidget);
      expect(find.byType(TextField), findsOneWidget);
    });

    testWidgets('empty name cannot be saved', (tester) async {
      await _pumpEditProfile(tester);

      await tester.enterText(find.byType(TextField), '   ');
      await tester.tap(find.text('Save Changes'));
      await _settle(tester);

      expect(find.textContaining('required'), findsOneWidget);
    });

    testWidgets('a single-word name cannot be saved', (tester) async {
      await _pumpEditProfile(tester);

      await tester.enterText(find.byType(TextField), 'Faon');
      await tester.tap(find.text('Save Changes'));
      await _settle(tester);

      expect(find.text('Please enter your first and last name.'), findsOneWidget);
    });

    testWidgets('successful name save updates state and closes the screen', (tester) async {
      SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(MaterialApp(
            home: Builder(
              builder: (context) => Scaffold(
                body: Center(
                  child: ElevatedButton(
                    onPressed: () => Navigator.of(context).push(
                      MaterialPageRoute(builder: (_) => const EditProfileScreen()),
                    ),
                    child: const Text('open'),
                  ),
                ),
              ),
            ),
          ));
          await tester.tap(find.text('open'));
          await _settle(tester);

          await tester.enterText(find.byType(TextField), 'Maria Dela Cruz');
          await tester.tap(find.text('Save Changes'));
          await tester.pumpAndSettle(const Duration(milliseconds: 100));

          expect(find.byType(EditProfileScreen), findsNothing);
        },
        () => _buildClient(),
      );
    });

    testWidgets('a failed save surfaces the server error and keeps the screen open', (tester) async {
      await _pumpEditProfile(
        tester,
        client: _buildClient(updateSucceeds: false),
        interact: () async {
          await tester.enterText(find.byType(TextField), 'Maria Dela Cruz');
          await tester.tap(find.text('Save Changes'));
          await _settle(tester);
        },
      );

      expect(find.text('That name is not allowed.'), findsOneWidget);
      expect(find.byType(EditProfileScreen), findsOneWidget);
    });

    testWidgets('photo-change control is present with a tooltip', (tester) async {
      await _pumpEditProfile(tester);

      expect(find.byIcon(Icons.camera_alt_outlined), findsOneWidget);
      expect(find.byTooltip('Change photo'), findsOneWidget);
    });

    testWidgets('successful photo upload displays the freshly fetched server image, not the local file', (tester) async {
      ImagePickerPlatform.instance = _FakeImagePicker(
        XFile.fromData(_pickedPngBytes, path: 'photo.jpg', name: 'photo.jpg', mimeType: 'image/jpeg'),
      );

      await _pumpEditProfile(
        tester,
        client: _buildClient(
          initialImageBytes: _initialPngBytes,
          updatedImageBytes: _updatedPngBytes,
        ),
        interact: () async {
          final avatarBefore = tester.widget<CircleAvatar>(find.byType(CircleAvatar));
          expect((avatarBefore.backgroundImage as MemoryImage).bytes, _initialPngBytes);

          await tester.tap(find.byTooltip('Change photo'));
          await _settle(tester);
          await _settle(tester);

          final avatarAfter = tester.widget<CircleAvatar>(find.byType(CircleAvatar));
          final displayedBytes = (avatarAfter.backgroundImage as MemoryImage).bytes;
          expect(displayedBytes, _updatedPngBytes);
          expect(displayedBytes, isNot(_pickedPngBytes));
          expect(tester.takeException(), isNull);
        },
      );
    });

    testWidgets('a failed photo upload keeps the previous valid image and shows an error', (tester) async {
      ImagePickerPlatform.instance = _FakeImagePicker(
        XFile.fromData(_pickedPngBytes, path: 'photo.jpg', name: 'photo.jpg', mimeType: 'image/jpeg'),
      );

      await _pumpEditProfile(
        tester,
        client: _buildClient(
          initialImageBytes: _initialPngBytes,
          uploadSucceeds: false,
          uploadFailureMessage: 'Please choose a valid image up to 5 MB.',
        ),
        interact: () async {
          await tester.tap(find.byTooltip('Change photo'));
          await _settle(tester);
          await _settle(tester);

          final avatar = tester.widget<CircleAvatar>(find.byType(CircleAvatar));
          expect((avatar.backgroundImage as MemoryImage).bytes, _initialPngBytes);
          expect(find.text('Please choose a valid image up to 5 MB.'), findsOneWidget);
          expect(tester.takeException(), isNull);
        },
      );

      expect(find.byType(EditProfileScreen), findsOneWidget);
    });

    for (final width in [320.0, 360.0, 390.0, 412.0]) {
      testWidgets('renders without overflow at ${width.toInt()}px', (tester) async {
        await _pumpEditProfile(tester, width: width);

        expect(tester.takeException(), isNull);
      });
    }

    testWidgets('renders correctly in light mode', (tester) async {
      await _pumpEditProfile(tester, theme: AppTheme.light);

      expect(tester.takeException(), isNull);
      expect(find.text('faon@example.com'), findsOneWidget);
    });

    testWidgets('renders correctly in dark mode', (tester) async {
      await _pumpEditProfile(tester, theme: AppTheme.dark);

      expect(tester.takeException(), isNull);
    });

    testWidgets('a long name does not overflow', (tester) async {
      await _pumpEditProfile(
        tester,
        client: _buildClient(),
        width: 320,
      );

      await tester.enterText(
        find.byType(TextField),
        'Maria Antonietta Consolacion Delacruz-Villanueva',
      );
      await _settle(tester);

      expect(tester.takeException(), isNull);
    });
  });
}
