import 'package:flutter/material.dart';

abstract final class TmColors {
  static const Color yellow  = Color(0xFFFFC107);
  static const Color black   = Color(0xFF1A1A1A);
  static const Color white   = Color(0xFFFFFFFF);
  static const Color error   = Color(0xFFE53935);
  static const Color success = Color(0xFF43A047);
  static const Color grey100 = Color(0xFFF5F5F5);
  static const Color grey300 = Color(0xFFE0E0E0);
  static const Color grey500 = Color(0xFF9E9E9E);
  static const Color grey700 = Color(0xFF616161);

  // Dark mode surfaces
  static const Color dark900  = Color(0xFF121212);
  static const Color dark800  = Color(0xFF1E1E1E);
  static const Color dark700  = Color(0xFF2A2A2A);
  static const Color dark600  = Color(0xFF333333);
  static const Color darkText = Color(0xFFF0F0F0);
}

abstract final class AppTheme {
  static ThemeData get light => ThemeData(
        colorSchemeSeed: TmColors.yellow,
        brightness: Brightness.light,
        scaffoldBackgroundColor: TmColors.white,
        useMaterial3: true,
        dialogTheme: const DialogThemeData(backgroundColor: TmColors.white),
      );

  static ThemeData get dark => ThemeData(
        colorSchemeSeed: TmColors.yellow,
        brightness: Brightness.dark,
        scaffoldBackgroundColor: TmColors.dark900,
        useMaterial3: true,
        dialogTheme: const DialogThemeData(backgroundColor: TmColors.dark800),
      );
}

extension TmContext on BuildContext {
  bool get isDark => Theme.of(this).brightness == Brightness.dark;

  // Backgrounds / surfaces
  Color get bg      => isDark ? TmColors.dark900 : TmColors.white;
  Color get surface => isDark ? TmColors.dark700  : TmColors.grey100;
  Color get card    => isDark ? TmColors.dark800  : TmColors.white;
  Color get divider => isDark ? TmColors.dark600  : TmColors.grey300;

  // Text
  Color get textPrimary   => isDark ? TmColors.darkText : TmColors.black;
  Color get textSecondary => isDark ? TmColors.grey500  : TmColors.grey500;
  Color get textTertiary  => isDark ? TmColors.grey500  : TmColors.grey700;
}
