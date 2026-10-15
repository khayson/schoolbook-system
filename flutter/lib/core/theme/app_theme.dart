import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// NOTEE-inspired ops palette — black + white + lime accent, soft rounds.
class AppColors {
  static const Color lime = Color(0xFFC8F54A);
  static const Color limeSoft = Color(0xFFEAFBC0);
  static const Color ink = Color(0xFF111111);
  static const Color mute = Color(0xFF8A8A8A);
  static const Color line = Color(0xFFE8E8E8);
  static const Color wash = Color(0xFFF5F5F5);
  static const Color surface = Color(0xFFFFFFFF);
  static const Color warn = Color(0xFFF59E0B);
  static const Color danger = Color(0xFFE11D48);

  // Back-compat aliases used across older screens.
  static const Color seed = lime;
  static const Color seedDark = ink;
  static const Color seedSoft = limeSoft;
}

ThemeData buildLightTheme() {
  final text = GoogleFonts.nunitoTextTheme().apply(
    bodyColor: AppColors.ink,
    displayColor: AppColors.ink,
  );

  final base = ThemeData(
    useMaterial3: true,
    brightness: Brightness.light,
    scaffoldBackgroundColor: AppColors.surface,
    colorScheme: const ColorScheme.light(
      primary: AppColors.ink,
      onPrimary: Colors.white,
      secondary: AppColors.lime,
      onSecondary: AppColors.ink,
      surface: AppColors.surface,
      onSurface: AppColors.ink,
      error: AppColors.danger,
      outline: AppColors.line,
    ),
    dividerColor: AppColors.line,
    appBarTheme: AppBarTheme(
      backgroundColor: AppColors.surface,
      foregroundColor: AppColors.ink,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      titleTextStyle: text.titleMedium?.copyWith(
        color: AppColors.ink,
        fontSize: 20,
        fontWeight: FontWeight.w800,
      ),
    ),
    floatingActionButtonTheme: const FloatingActionButtonThemeData(
      backgroundColor: AppColors.lime,
      foregroundColor: AppColors.ink,
      elevation: 0,
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: AppColors.ink,
        foregroundColor: Colors.white,
        elevation: 0,
        shape: const StadiumBorder(),
        minimumSize: const Size(64, 54),
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
        textStyle: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: AppColors.ink,
        side: const BorderSide(color: AppColors.line, width: 1.5),
        shape: const StadiumBorder(),
        minimumSize: const Size(48, 48),
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
      ),
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: AppColors.ink,
        foregroundColor: Colors.white,
        elevation: 0,
        shape: const StadiumBorder(),
        minimumSize: const Size(64, 54),
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: AppColors.wash,
      contentPadding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(18),
        borderSide: BorderSide.none,
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(18),
        borderSide: BorderSide.none,
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(18),
        borderSide: const BorderSide(color: AppColors.ink, width: 1.5),
      ),
    ),
    chipTheme: ChipThemeData(
      backgroundColor: AppColors.wash,
      selectedColor: AppColors.ink,
      labelStyle: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
      secondaryLabelStyle: const TextStyle(
        fontSize: 13,
        color: AppColors.lime,
        fontWeight: FontWeight.w800,
      ),
      side: BorderSide.none,
      shape: const StadiumBorder(),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
    ),
    cardTheme: CardThemeData(
      color: AppColors.surface,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        side: const BorderSide(color: AppColors.line),
        borderRadius: BorderRadius.circular(24),
      ),
    ),
    listTileTheme: const ListTileThemeData(
      contentPadding: EdgeInsets.symmetric(horizontal: 20, vertical: 4),
      minVerticalPadding: 12,
    ),
    progressIndicatorTheme: const ProgressIndicatorThemeData(
      color: AppColors.ink,
      linearTrackColor: AppColors.line,
    ),
  );

  return base.copyWith(textTheme: text);
}

ThemeData buildDarkTheme() {
  return buildLightTheme();
}
