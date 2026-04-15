import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

class AppTheme {
  static const Color _brand = Color(0xFF0D47A1);
  static const Color _accent = Color(0xFF42A5F5);
  static const Color _surface = Color(0xFFF8FBFF);

  static ThemeData get light {
    final base = ThemeData(
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(
        seedColor: _brand,
        primary: _brand,
        secondary: _accent,
        surface: _surface,
        brightness: Brightness.light,
      ),
    );

    final textTheme = GoogleFonts.dmSansTextTheme(base.textTheme).copyWith(
      displayLarge: GoogleFonts.sora(fontWeight: FontWeight.w700),
      displayMedium: GoogleFonts.sora(fontWeight: FontWeight.w700),
      displaySmall: GoogleFonts.sora(fontWeight: FontWeight.w700),
      headlineLarge: GoogleFonts.sora(fontWeight: FontWeight.w700),
      headlineMedium: GoogleFonts.sora(fontWeight: FontWeight.w700),
      headlineSmall: GoogleFonts.sora(fontWeight: FontWeight.w700),
      titleLarge: GoogleFonts.sora(fontWeight: FontWeight.w700),
      titleMedium: GoogleFonts.sora(fontWeight: FontWeight.w700),
      titleSmall: GoogleFonts.sora(fontWeight: FontWeight.w700),
    );

    return base.copyWith(
      textTheme: textTheme,
      scaffoldBackgroundColor: _surface,
      appBarTheme: AppBarTheme(
        centerTitle: false,
        backgroundColor: Colors.white,
        foregroundColor: base.colorScheme.onSurface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        shadowColor: Colors.black.withValues(alpha: 0.06),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(22),
          side: BorderSide(
            color: base.colorScheme.outlineVariant.withValues(alpha: 0.30),
          ),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide(color: base.colorScheme.outlineVariant),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide(color: base.colorScheme.outlineVariant),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide(color: base.colorScheme.primary, width: 1.3),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(0, 48),
          maximumSize: const Size(double.infinity, 48),
          backgroundColor: base.colorScheme.primary,
          foregroundColor: base.colorScheme.onPrimary,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
          textStyle: const TextStyle(fontWeight: FontWeight.w700),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(0, 46),
          side: BorderSide(color: base.colorScheme.outlineVariant),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
        ),
      ),
      chipTheme: base.chipTheme.copyWith(
        side: BorderSide(
          color: base.colorScheme.outlineVariant.withValues(alpha: 0.5),
        ),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
      ),
      navigationBarTheme: NavigationBarThemeData(
        height: 74,
        elevation: 0,
        backgroundColor: Colors.white,
        indicatorColor:
            base.colorScheme.primaryContainer.withValues(alpha: 0.75),
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return TextStyle(
            fontWeight: selected ? FontWeight.w700 : FontWeight.w600,
            fontSize: 12,
          );
        }),
      ),
      dividerTheme: DividerThemeData(
        color: base.colorScheme.outlineVariant.withValues(alpha: 0.4),
      ),
    );
  }
}
