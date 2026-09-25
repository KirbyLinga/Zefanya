// lib/theme/app_theme.dart
//
// "Zefanya" design system.
// Central place for colors, typography, and component themes.
// Import this everywhere instead of hardcoding colors/fonts.

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Raw design tokens, exposed as static constants so widgets can reach
/// for `AppColors.tertiary` etc. directly when a Theme lookup would be
/// overkill (e.g. one-off status chips).
class AppColors {
  AppColors._();

  // Core palette
  static const Color primary = Color(0xFFE2B4BD); // Soft Pink
  static const Color primaryDark = Color(0xFF6B3A45); // Deep rose/mauve
  static const Color secondary = Color(0xFFF7D6D0); // Light Blush
  static const Color tertiary = Color(0xFFADC7AD); // Sage Green
  static const Color neutral = Color(0xFF7C7676); // Muted Slate/Gray

  // Surfaces
  static const Color background = Color(0xFFF1E9E6); // deeper warm neutral — was near-white F9FAFB
  static const Color surface = Color(0xFFFFFFFF);
  // Warm off-white for secondary surfaces (review tiles, seller row,
  // quantity stepper, muted panels). Distinct from pure-white `surface`
  // so there are two real tiers: cards lift off the background, and
  // inset/secondary elements recede against those cards.
  static const Color surfaceMuted = Color(0xFFF7F2EF);

  // Text
  static const Color textPrimary = Color(0xFF2E2A2A);
  // Darkened from 7C7676 — that value sat at ~3.7:1 against the app
  // background (AppColors.background) and ~4.46:1 against white cards,
  // both under the 4.5:1 WCAG AA floor for normal-size text. This is the
  // most-reused text color in the app (bodySmall, labelMedium/Small, hint
  // text, and the all-caps `eyebrow` style, which — at 10pt bold — is too
  // small to qualify for the relaxed "large text" 3:1 threshold). 696363
  // clears AA against both surfaces (~4.9:1 / ~5.9:1) while staying the
  // same warm-neutral hue.
  static const Color textSecondary = Color(0xFF696363);
  static const Color textOnDark = Color(0xFFF9FAFB);

  // Semantic
  static const Color success = tertiary;
  // `success`/`tertiary` (sage ADC7AD) is a fill/icon color only: as TEXT it
  // measures ~1.4:1 on the blush voucher banner and ~1.8:1 on white, far
  // below the 4.5:1 WCAG AA floor. Use this darker sage of the same hue
  // whenever a success/savings message is rendered as text
  // (~4.9:1 on the banner, ~6:1 on white).
  static const Color successText = Color(0xFF3E6B45);
  // Visible sage for icon/fill use on white surfaces — the pale tertiary
  // (#ADC7AD) reads as gray at ~1.8:1 against white. This is the same
  // hue darkened to ~3.5:1, enough for a non-text element like a star or
  // a circle fill while staying clearly green rather than brown-neutral.
  static const Color sageIcon = Color(0xFF6B9B6B);
  static const Color warning = Color(0xFFC48A3D); // was a one-off hex living only in orders_status_screen
  static const Color danger = Color(0xFFB3261E);
  static const Color divider = Color(0xFFE7DEDF);
  static const Color ratingStar = Color(0xFFE8B84B); // was inlined twice in product_details_screen.dart
}

/// Extra tokens that don't map onto Flutter's built-in ColorScheme /
/// TextTheme slots, but that screens still need (e.g. the "inverted"
/// button style from the design board). Access via
/// `Theme.of(context).extension<AppThemeExtension>()!`.
@immutable
class AppThemeExtension extends ThemeExtension<AppThemeExtension> {
  const AppThemeExtension({
    required this.invertedButtonColor,
    required this.invertedButtonTextColor,
    required this.heroRadius,
    required this.cardRadius,
    required this.utilityRadius,
    required this.pillRadius,
  });

  final Color invertedButtonColor;
  final Color invertedButtonTextColor;
  // Shape-language tiers (biggest/softest = most important surface):
  // heroRadius (24)   — feature surfaces: promo banner, profile header, earnings hero
  // cardRadius (16)   — standard content: product cards, list tiles
  // utilityRadius (8) — functional controls: buttons, inputs, chips, tags
  final double heroRadius;
  final double cardRadius;
  final double utilityRadius;
  final double pillRadius;

  @override
  AppThemeExtension copyWith({
    Color? invertedButtonColor,
    Color? invertedButtonTextColor,
    double? heroRadius,
    double? cardRadius,
    double? utilityRadius,
    double? pillRadius,
  }) {
    return AppThemeExtension(
      invertedButtonColor: invertedButtonColor ?? this.invertedButtonColor,
      invertedButtonTextColor:
          invertedButtonTextColor ?? this.invertedButtonTextColor,
      heroRadius: heroRadius ?? this.heroRadius,
      cardRadius: cardRadius ?? this.cardRadius,
      utilityRadius: utilityRadius ?? this.utilityRadius,
      pillRadius: pillRadius ?? this.pillRadius,
    );
  }

  @override
  AppThemeExtension lerp(ThemeExtension<AppThemeExtension>? other, double t) {
    if (other is! AppThemeExtension) return this;
    return AppThemeExtension(
      invertedButtonColor:
          Color.lerp(invertedButtonColor, other.invertedButtonColor, t)!,
      invertedButtonTextColor: Color.lerp(
          invertedButtonTextColor, other.invertedButtonTextColor, t)!,
      heroRadius: heroRadius,
      cardRadius: cardRadius,
      utilityRadius: utilityRadius,
      pillRadius: pillRadius,
    );
  }
}

/// Editorial type scale (critique item #2).
///
/// The TextTheme below is a *safe* scale — everything in it sits
/// between roughly 11 and 24pt, which is why no screen has ever had a
/// focal point: when every size is within a few points of every other
/// size, weight and family differences can't carry the hierarchy on
/// their own. These styles are deliberately outside that band.
///
/// The rule when using them: one per screen, maximum. A masthead only
/// reads as a masthead if nothing else nearby is competing, and the
/// tiny [eyebrow] is what makes the big sizes look big — the contrast
/// is the point, not the bigness.
class AppType {
  AppType._();

  /// The "Zefanya" wordmark lockup. Used on Home, and — deliberately,
  /// not by drift — on Login and Landing too: they share the exact same
  /// eyebrow/masthead/monogram construction so the brand reads
  /// identically across the pre-auth flow and the app itself (see the
  /// design-pass note atop login_screen.dart). None of those screens are
  /// ever visible at the same time, so "biggest thing in the app" still
  /// holds per-screen; Landing goes further still with its own
  /// `.copyWith(fontSize: 64)` for the same reason — see the comment at
  /// that call site.
  static TextStyle get masthead => GoogleFonts.playfairDisplay(
        fontSize: 54,
        height: 0.92,
        fontWeight: FontWeight.w600,
        letterSpacing: -1.8,
        color: AppColors.textPrimary,
      );

  /// Section headings on Home — roughly double the old titleLarge.
  static TextStyle get sectionDisplay => GoogleFonts.playfairDisplay(
        fontSize: 34,
        height: 1.0,
        fontWeight: FontWeight.w600,
        letterSpacing: -0.9,
        color: AppColors.textPrimary,
      );

  /// Pull-quote scale, for seller voice / editorial copy.
  static TextStyle get pullQuote => GoogleFonts.playfairDisplay(
        fontSize: 27,
        height: 1.28,
        fontWeight: FontWeight.w400,
        fontStyle: FontStyle.italic,
        color: AppColors.textPrimary,
      );

  /// Oversized price numeral. Tabular figures so a column of prices
  /// doesn't wobble, and tight tracking because Playfair's default
  /// spacing looks loose at display sizes.
  static TextStyle priceNumeral(double size, {Color color = AppColors.primaryDark}) =>
      GoogleFonts.playfairDisplay(
        fontSize: size,
        height: 1.0,
        fontWeight: FontWeight.w600,
        letterSpacing: size * -0.02,
        color: color,
        fontFeatures: const [FontFeature.tabularFigures()],
      );

  /// Tiny all-caps kicker. The small end of the contrast — always
  /// pairs with one of the display sizes above, never used alone.
  static TextStyle get eyebrow => GoogleFonts.montserrat(
        fontSize: 10,
        fontWeight: FontWeight.w700,
        letterSpacing: 2.6,
        color: AppColors.textSecondary,
      );
}

class AppTheme {
  AppTheme._();

  static const double _heroRadius = 24;
  static const double _cardRadius = 16;
  static const double _utilityRadius = 8; // was 12 — tightened for clearer contrast against card/hero tiers
  static const double _pillRadius = 999;

  static ThemeData get light {
    final base = ThemeData(useMaterial3: true, brightness: Brightness.light);

    final colorScheme = ColorScheme.fromSeed(
      seedColor: AppColors.primary,
      brightness: Brightness.light,
      primary: AppColors.primaryDark,
      secondary: AppColors.tertiary,
      tertiary: AppColors.secondary,
      surface: AppColors.surface,
      error: AppColors.danger,
    );

    final textTheme = _buildTextTheme(base.textTheme);

    return base.copyWith(
      colorScheme: colorScheme,
      // Transparent — the actual background is now painted once, app-wide,
      // by MaterialApp's builder in main.dart (a soft top-down gradient)
      // instead of every screen individually painting the same flat solid
      // fill. This is the single biggest, most-repeated surface in the
      // whole app, so a flat solid there reads as "flat" no matter how
      // much depth the cards/heroes on top of it have.
      scaffoldBackgroundColor: Colors.transparent,
      textTheme: textTheme,
      primaryTextTheme: textTheme,

      // Every current spinner/RefreshIndicator in the app happens to set
      // its own color explicitly, but without this, one that doesn't would
      // silently fall back to colorScheme.primary — the pale pastel seed
      // color, not the deep-rose primaryDark used as the actual brand
      // accent everywhere else. Same mismatch class as the segmented
      // button fix earlier, closed here for anything added later.
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: AppColors.primaryDark,
      ),

      // ----- AppBar -----
      appBarTheme: AppBarTheme(
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent, // stop Material 3's default scroll-tint overlay from breaking the seamless gradient
        elevation: 0,
        scrolledUnderElevation: 0, // stop Material 3 from drawing a scroll shadow over the new transparent/gradient background
        foregroundColor: AppColors.textPrimary,
        centerTitle: true,
        titleTextStyle: textTheme.titleLarge?.copyWith(
          fontFamily: GoogleFonts.playfairDisplay().fontFamily,
          fontWeight: FontWeight.w600,
        ),
        iconTheme: const IconThemeData(color: AppColors.textPrimary),
      ),

      // ----- Cards -----
      cardTheme: CardThemeData(
        color: AppColors.surfaceMuted,
        elevation: 3,
        shadowColor: AppColors.neutral.withValues(alpha: 0.2),
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(_cardRadius),
        ),
      ),

      // ----- Inputs -----
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.surface,
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        hintStyle: textTheme.bodyMedium?.copyWith(color: AppColors.textSecondary),
        prefixIconColor: AppColors.textSecondary,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(_utilityRadius),
          borderSide: const BorderSide(color: AppColors.divider),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(_utilityRadius),
          borderSide: const BorderSide(color: AppColors.divider),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(_utilityRadius),
          borderSide: const BorderSide(color: AppColors.primaryDark, width: 1.5),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(_utilityRadius),
          borderSide: const BorderSide(color: AppColors.danger),
        ),
      ),

      // ----- Buttons -----
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.primaryDark,
          foregroundColor: AppColors.textOnDark,
          disabledBackgroundColor: AppColors.divider,
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
          textStyle: textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(_utilityRadius),
          ),
          elevation: 0,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: AppColors.textPrimary,
          side: const BorderSide(color: AppColors.divider),
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
          textStyle: textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(_utilityRadius),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: AppColors.primaryDark,
          textStyle: textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
        ),
      ),

      // ----- Segmented button (e.g. the Buyer/Courier "Login as" toggle) -----
      // Without this, Material 3 derives the selected fill from
      // ColorScheme.secondaryContainer, which — because the scheme is
      // seeded from the soft-pink AppColors.primary — renders as a pale
      // pastel pill. That pastel then sits right above the much darker
      // primaryDark "Login" button, creating two different accent
      // intensities on the same screen. Pin it to primaryDark instead so
      // every "selected/primary" state on a screen reads as one accent.
      segmentedButtonTheme: SegmentedButtonThemeData(
        style: SegmentedButton.styleFrom(
          backgroundColor: AppColors.surface,
          foregroundColor: AppColors.textPrimary,
          selectedBackgroundColor: AppColors.primaryDark,
          selectedForegroundColor: AppColors.textOnDark,
          side: const BorderSide(color: AppColors.divider),
          textStyle: textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
        ),
      ),

      // ----- Chips (used for category pills / "Secondary" style tokens) -----
      chipTheme: base.chipTheme.copyWith(
        backgroundColor: AppColors.secondary,
        selectedColor: AppColors.primaryDark,
        labelStyle: textTheme.labelMedium?.copyWith(color: AppColors.textPrimary),
        secondaryLabelStyle:
            textTheme.labelMedium?.copyWith(color: AppColors.textOnDark),
        shape: StadiumBorder(side: BorderSide(color: AppColors.divider)),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      ),

      // ----- Bottom nav (base fallback; the floating pill nav is a
      // custom widget — see widgets/floating_nav_bar.dart) -----
      bottomNavigationBarTheme: BottomNavigationBarThemeData(
        backgroundColor: AppColors.surface,
        selectedItemColor: AppColors.primaryDark,
        unselectedItemColor: AppColors.textSecondary,
        type: BottomNavigationBarType.fixed,
        showUnselectedLabels: true,
      ),

      dividerTheme: const DividerThemeData(
        color: AppColors.divider,
        thickness: 1,
        space: 1,
      ),

      iconTheme: const IconThemeData(color: AppColors.textPrimary),

      snackBarTheme: SnackBarThemeData(
        backgroundColor: AppColors.textPrimary,
        contentTextStyle: textTheme.bodyMedium?.copyWith(color: AppColors.textOnDark),
        // Without this, the action label (e.g. "View Cart") falls back to
        // Material 3's auto-derived colorScheme.inversePrimary — computed
        // off the seed tonal palette rather than anything in AppColors.
        // Same accidental-pastel trap as the segmented button above; pin
        // it to the palette on purpose instead. ~7.8:1 against the
        // textPrimary background above, well clear of AA.
        actionTextColor: AppColors.primary,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(_utilityRadius),
        ),
      ),

      extensions: const [
        AppThemeExtension(
          invertedButtonColor: AppColors.textPrimary,
          invertedButtonTextColor: AppColors.textOnDark,
          heroRadius: _heroRadius,
          cardRadius: _cardRadius,
          utilityRadius: _utilityRadius,
          pillRadius: _pillRadius,
        ),
      ],
    );
  }

  static TextTheme _buildTextTheme(TextTheme base) {
    final headline = GoogleFonts.playfairDisplayTextTheme(base);
    final body = GoogleFonts.montserratTextTheme(base);

    return body.copyWith(
      displayLarge: headline.displayLarge?.copyWith(color: AppColors.textPrimary),
      displayMedium: headline.displayMedium?.copyWith(color: AppColors.textPrimary),
      displaySmall: headline.displaySmall?.copyWith(color: AppColors.textPrimary),
      headlineLarge: headline.headlineLarge?.copyWith(color: AppColors.textPrimary),
      headlineMedium: headline.headlineMedium?.copyWith(color: AppColors.textPrimary),
      headlineSmall: headline.headlineSmall?.copyWith(
        color: AppColors.textPrimary,
        fontWeight: FontWeight.w600,
      ),
      titleLarge: headline.titleLarge?.copyWith(
        color: AppColors.textPrimary,
        fontWeight: FontWeight.w600,
      ),
      titleMedium: body.titleMedium?.copyWith(color: AppColors.textPrimary),
      titleSmall: body.titleSmall?.copyWith(color: AppColors.textPrimary),
      bodyLarge: body.bodyLarge?.copyWith(color: AppColors.textPrimary),
      bodyMedium: body.bodyMedium?.copyWith(color: AppColors.textPrimary),
      bodySmall: body.bodySmall?.copyWith(color: AppColors.textSecondary),
      labelLarge: body.labelLarge?.copyWith(color: AppColors.textPrimary),
      labelMedium: body.labelMedium?.copyWith(color: AppColors.textSecondary),
      labelSmall: body.labelSmall?.copyWith(color: AppColors.textSecondary),
    );
  }
}
