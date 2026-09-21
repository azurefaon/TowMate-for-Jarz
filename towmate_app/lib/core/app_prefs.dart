import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

abstract final class AppPrefs {
  static const _kDarkMode = 'dark_mode';
  static final themeModeNotifier = ValueNotifier<ThemeMode>(ThemeMode.light);

  static Future<bool> getDarkMode() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_kDarkMode) ?? false;
  }

  static Future<void> setDarkMode(bool value) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kDarkMode, value);
  }

  static Future<void> restoreAuthenticatedTheme() async {
    themeModeNotifier.value = await getDarkMode()
        ? ThemeMode.dark
        : ThemeMode.light;
  }

  static void useGuestTheme() {
    themeModeNotifier.value = ThemeMode.light;
  }
}
