import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/app_prefs.dart';

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    AppPrefs.useGuestTheme();
  });

  test(
    'restores authenticated dark preference and guest theme stays light',
    () async {
      await AppPrefs.setDarkMode(true);
      await AppPrefs.restoreAuthenticatedTheme();

      expect(AppPrefs.themeModeNotifier.value, ThemeMode.dark);

      AppPrefs.useGuestTheme();

      expect(AppPrefs.themeModeNotifier.value, ThemeMode.light);
      expect(await AppPrefs.getDarkMode(), isTrue);
    },
  );
}
