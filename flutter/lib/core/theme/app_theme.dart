import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// App color tokens — Ghana-ish greens on a warm neutral base.
class AppColors {
  static const Color seed = Color(0xFF0B6E4F);
  static const Color seedDark = Color(0xFF1FA971);
}

ThemeData buildLightTheme() {
  final scheme = ColorScheme.fromSeed(
    seedColor: AppColors.seed,
    brightness: Brightness.light,
  );

  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: scheme.surface,
    appBarTheme: AppBarTheme(
      backgroundColor: scheme.surface,
      foregroundColor: scheme.onSurface,
      elevation: 0,
      centerTitle: false,
    ),
  ).copyWith(
    textTheme: GoogleFonts.interTextTheme(ThemeData.light().textTheme),
  );
}

ThemeData buildDarkTheme() {
  final scheme = ColorScheme.fromSeed(
    seedColor: AppColors.seedDark,
    brightness: Brightness.dark,
  );

  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: scheme.surface,
    appBarTheme: AppBarTheme(
      backgroundColor: scheme.surface,
      foregroundColor: scheme.onSurface,
      elevation: 0,
      centerTitle: false,
    ),
  ).copyWith(
    textTheme: GoogleFonts.interTextTheme(ThemeData.dark().textTheme),
  );
}
