// lib/theme/app_theme.dart
//
// "Zefanya" design system.
// Central place for colors, typography, and component themes.
// Import this everywhere instead of hardcoding colors/fonts.

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// One mode's worth of colour tokens.
///
/// Two instances exist — [AppPalette.light] and [AppPalette.dark] — and
/// every widget in the app reaches them through [AppColors], never
/// through this class directly.
///
/// Keeping the two modes as *data* (rather than as colour literals
/// scattered through widget code) is what makes "does this screen work
/// in both modes?" answerable by reading one file. It exists because the
/// app spent a while with exactly one palette hardcoded as static
/// constants, which meant the only way to change the look was to edit
/// call sites — which is how a light app became a dark app without
/// anyone deciding to build a dark mode.
@immutable
class AppPalette {
  const AppPalette({
    required this.isDark,
    required this.primary,
    required this.primaryDark,
    required this.secondary,
    required this.tertiary,
    required this.neutral,
    required this.accent,
    required this.background,
    required this.surface,
    required this.surfaceMuted,
    required this.textPrimary,
    required this.textSecondary,
    required this.textOnDark,
    required this.textOnLight,
    required this.roseGround,
    required this.successText,
    required this.sageIcon,
    required this.warning,
    required this.danger,
    required this.divider,
    required this.ratingStar,
    required this.tintInk,
    required this.groundGradient,
    required this.glowCenter,
    required this.glowBlush,
    required this.glowSage,
    required this.shimmerBase,
    required this.shimmerHighlight,
    required this.navShadow,
    required this.navSelectedFill,
    required this.cardElevation,
    required this.cardShadow,
    required this.snackBarBackground,
    required this.snackBarText,
    required this.snackBarAction,
    required this.promoSageGround,
    required this.promoSageAccent,
    required this.promoTerracottaGround,
    required this.promoTerracottaAccent,
    required this.onPromoGround,
  });

  /// True for the dark instance. The only place widget code should ever
  /// branch on brightness — everything else should resolve a token.
  final bool isDark;

  Brightness get brightness => isDark ? Brightness.dark : Brightness.light;

  // ── Brand hues ───────────────────────────────────────────────────────
  final Color primary;      // soft blush — glow, subtle accent
  final Color primaryDark;  // brand fills, buttons, selected states
  final Color secondary;    // light blush — delicate fills
  final Color tertiary;     // sage — success, secondary accent
  final Color neutral;      // muted slate
  final Color accent;       // terracotta — price numerals, primary CTAs

  // ── Surfaces ─────────────────────────────────────────────────────────
  final Color background;    // the page the whole app sits on
  final Color surface;       // cards and bands
  final Color surfaceMuted;  // inset panels, one step in from surface

  // ── Text ─────────────────────────────────────────────────────────────
  final Color textPrimary;
  final Color textSecondary;
  final Color textOnDark;    // text/icons on a *dark* fill (heroes, buttons)

  /// Ink for a *light* fill — the mode-invariant pastels ([secondary],
  /// [tertiary], [primary]) that are the same pale value in both modes.
  ///
  /// The counterpart to [textOnDark]. It exists because those pastels were
  /// carrying near-white ink: ~1.6:1, in *both* modes, on every sage
  /// avatar, category badge and chat initial in the app. A fill that
  /// doesn't change between modes needs an ink that doesn't either, which
  /// is why this is one constant rather than a per-palette value.
  final Color textOnLight;

  /// A rose ground that small light text can actually sit on.
  ///
  /// [primaryDark] is a *fill* token: dark mode lifts it (#6B3A45 →
  /// #B06070) so it reads against ink. The side effect is that it can no
  /// longer host text — white on the lifted rose caps out at 4.42:1, so
  /// **no** ink clears AA on it. That's how the "Bestseller" tag ended up
  /// at 3.82:1 and the first promo slide's eyebrow below 3:1 in dark mode.
  /// Anything that puts text on rose uses this instead: deep enough for
  /// near-white at ~7.8:1 in both modes.
  final Color roseGround;

  // ── Semantic ─────────────────────────────────────────────────────────
  final Color successText;
  final Color sageIcon;
  final Color warning;
  final Color danger;
  final Color divider;
  final Color ratingStar;

  /// Ink for icons and loaders sitting on a [AppColors.hueOnSurface] fill.
  /// Flips with the mode because those fills flip: a tint is a *light*
  /// pastel in light mode and a *dark* hue-lean in dark mode, so the
  /// thing drawn on top of it has to invert or it disappears into it.
  final Color tintInk;

  // ── Atmosphere ───────────────────────────────────────────────────────
  final Gradient groundGradient;   // app-wide background base
  final Color glowCenter;
  final Color glowBlush;
  final Color glowSage;

  // ── Motion / chrome ──────────────────────────────────────────────────
  final Color shimmerBase;
  final Color shimmerHighlight;
  final Color navShadow;
  final Color navSelectedFill;
  final double cardElevation;
  final Color cardShadow;

  // ── Snack bar ────────────────────────────────────────────────────────
  final Color snackBarBackground;
  final Color snackBarText;
  final Color snackBarAction;

  // ── Promo carousel grounds ───────────────────────────────────────────
  // Content, not chrome, but they still need a value per mode or the
  // carousel is either blinding or invisible on one of the two grounds.
  final Color promoSageGround;
  final Color promoSageAccent;
  final Color promoTerracottaGround;
  final Color promoTerracottaAccent;
  final Color onPromoGround;

  // ─────────────────────────────────────────────────────────────────────
  // The two modes
  // ─────────────────────────────────────────────────────────────────────

  /// Light mode — the app's original identity: a warm paper ground, white
  /// cards, deep-rose brand accents, terracotta prices. These surface and
  /// text values are the ones this app shipped with before it was
  /// converted to a dark ground, restored here rather than re-invented.
  ///
  /// [accent] is the one value that is new: the dark palette introduced
  /// terracotta for prices, and terracotta has to be *darker* on white
  /// than on ink to clear AA (~5:1 here vs ~5.4:1 there).
  static const AppPalette light = AppPalette(
    isDark: false,
    primary: Color(0xFFE2B4BD),
    primaryDark: Color(0xFF6B3A45),
    secondary: Color(0xFFF7D6D0),
    tertiary: Color(0xFFADC7AD),
    neutral: Color(0xFF7C7676),
    accent: Color(0xFFA85B33),
    background: Color(0xFFF1E9E6),
    surface: Color(0xFFFFFFFF),
    surfaceMuted: Color(0xFFF7F2EF),
    textPrimary: Color(0xFF2E2A2A),
    textSecondary: Color(0xFF696363),
    textOnDark: Color(0xFFF9FAFB),
    textOnLight: Color(0xFF2E2A2A),
    roseGround: Color(0xFF6B3A45),
    successText: Color(0xFF3E6B45),
    sageIcon: Color(0xFF6B9B6B),
    warning: Color(0xFFC48A3D),
    danger: Color(0xFFB3261E),
    divider: Color(0xFFE7DEDF),
    ratingStar: Color(0xFFB07C12),
    // Was 0xFFE8B84B — a soft gold measuring 1.84:1 against this side's
    // surface, so a star was a barely-visible smudge on paper (the same value
    // scores 8.58:1 in dark mode, which is why the two modes no longer share
    // it). A star communicates a rating, which makes it a graphic that has to
    // be *understood* → WCAG 1.4.11's 3:1 floor, not the 4.5:1 text floor.
    // Measured against *both* grounds a star appears on, because a token that
    // only clears one isn't a token: surface 3.66:1, surfaceMuted 3.29:1. The
    // first attempt (0xFFBE8620) cleared surface at 3.17 and failed on
    // surfaceMuted at 2.86 — caught by test/theme_contrast_test.dart, which
    // now asserts this in both modes on both grounds.
    // Ink on a blush/sage-tinted fill: deep rose, because the fill is a
    // pale pastel on this side.
    tintInk: Color(0xFF6B3A45),
    // Warm wash, slightly lighter at the top.
    groundGradient: RadialGradient(
      center: Alignment.topCenter,
      radius: 1.3,
      colors: [Color(0xFFF9F5F4), Color(0xFFF1E9E6)],
    ),
    // Richer, more saturated glow-only variants of primary/secondary/
    // tertiary — NOT the same tokens used for buttons/badges/text, which
    // stay exactly as verified. A pale pastel blended into an
    // already-pale cream ground can't create luminance contrast no
    // matter how high the alpha (light mode's old glows were actually
    // HIGHER alpha than dark mode's and still read as flatter — that
    // was the tell). These are the same hues pushed to real saturation
    // via HSL (s: 0.44→0.62, l: 0.80→0.62 for the rose, etc.) so the
    // glow has something to contrast against paper with, the way dark
    // mode's glows contrast against ink. Alpha is lower than before
    // (28–34% vs the old 35–55%) because a more saturated color needs
    // less opacity to register — this was never a "turn it up" problem.
    glowCenter: Color(0x52DA627A), // rich rose @ 32%
    glowBlush: Color(0x47E29081),  // rich coral-blush @ 28%
    glowSage: Color(0x576BB76B),   // rich sage-green @ 34%
    shimmerBase: Color(0xFFE6DAD8),
    shimmerHighlight: Color(0xFFFFFFFF),
    // Stronger than before (18%→24% alpha) — the old value made cards
    // barely differentiate from the page. Dark mode's cards get real
    // lift from luminance contrast alone; light mode needs the shadow
    // to do more of that work since surface (white) and background
    // (warm cream) are close in lightness to begin with.
    navShadow: Color(0x3D7C7676),  // neutral @ 24%
    navSelectedFill: Color(0xFFF7D6D0),
    cardElevation: 4,
    cardShadow: Color(0x407C7676), // neutral @ 25%
    // A snack bar has to be the highest-contrast surface on screen to
    // read as transient chrome. On a light page that means a dark bar;
    // on a dark page it means the opposite (see [dark]).
    snackBarBackground: Color(0xFF2E2A2A),
    snackBarText: Color(0xFFF9FAFB),
    snackBarAction: Color(0xFFE2B4BD),
    // Deep saturated ground + pale accent on top — not pale-pastel-as-
    // ground. This is the same technique dark mode already uses for its
    // promo slides (and the one this app's very first promo banner used,
    // long before dark mode existed: a dark burgundy ground with a pale
    // accent). A pastel-fill promo card sitting on an already-pastel page
    // reads as flat; a deep-colour promo card reads as a deliberate
    // moment, the way it always has elsewhere in this app.
    promoSageGround: Color(0xFF2C4B2C),
    promoSageAccent: Color(0xFFADC7AD),
    promoTerracottaGround: Color(0xFF6A2A1F),
    promoTerracottaAccent: Color(0xFFE8A98C),
    // Was dark text (correct for the old pale grounds) — now light,
    // because the grounds themselves just went dark. Leaving this dark
    // would make the promo copy illegible: exactly the "changed the
    // fill, forgot the ink" failure this whole palette rewrite exists
    // to prevent.
    onPromoGround: Color(0xFFF9F5F4),
  );

  /// Dark mode — the warm-ink ground: deep brown-ink page, lifted warm-dark
  /// cards, warm off-white text. Same hue family as light mode so the two
  /// feel like one brand in two lighting conditions, not two products.
  static const AppPalette dark = AppPalette(
    isDark: true,
    primary: Color(0xFFE2B4BD),
    primaryDark: Color(0xFFB06070),
    secondary: Color(0xFFF7D6D0),
    tertiary: Color(0xFFADC7AD),
    neutral: Color(0xFF7C7676),
    accent: Color(0xFFC8825A),
    background: Color(0xFF1A1410),
    surface: Color(0xFF2C2018),
    surfaceMuted: Color(0xFF382A1E),
    textPrimary: Color(0xFFF5EDE8),
    textSecondary: Color(0xFFAD9488),
    textOnDark: Color(0xFFF5EDE8),
    // Same two values as light mode, deliberately: see the field notes —
    // these are the pairings that don't depend on the ground.
    textOnLight: Color(0xFF2E2A2A),
    roseGround: Color(0xFF6B3A45),
    successText: Color(0xFF8FCA96),
    sageIcon: Color(0xFF8FCA96),
    warning: Color(0xFFD4963F),
    danger: Color(0xFFCF6679),
    divider: Color(0xFF3D2D22),
    ratingStar: Color(0xFFE8B84B),
    // Same ink role as light mode, inverted: the tinted fill is dark here.
    tintInk: Color(0xFFE2B4BD),
    groundGradient: LinearGradient(
      begin: Alignment.topCenter,
      end: Alignment.bottomCenter,
      colors: [Color(0xFF221810), Color(0xFF1A1410)],
    ),
    glowCenter: Color(0x38B06070), // primaryDark @ 22%
    glowBlush: Color(0x4DE2B4BD),  // primary @ 30%
    glowSage: Color(0x33ADC7AD),   // tertiary @ 20%
    shimmerBase: Color(0xFF2C2018),
    shimmerHighlight: Color(0xFF382A1E),
    navShadow: Color(0x59000000),  // black @ 35% — ink casts no visible shadow
    navSelectedFill: Color(0x47B06070),
    // Flat editorial: depth comes from the surface step, not shadows.
    cardElevation: 0,
    cardShadow: Color(0x00000000),
    snackBarBackground: Color(0xFF382A1E),
    snackBarText: Color(0xFFF5EDE8),
    snackBarAction: Color(0xFFC8825A),
    promoSageGround: Color(0xFF2A3D2A),
    promoSageAccent: Color(0xFFADC7AD),
    promoTerracottaGround: Color(0xFF3D2018),
    promoTerracottaAccent: Color(0xFFC8825A),
    onPromoGround: Color(0xFFF5EDE8),
  );

  static AppPalette of(Brightness brightness) =>
      brightness == Brightness.dark ? dark : light;
}

/// An [InheritedWidget] that broadcasts palette changes to every
/// descendant that calls [AppPaletteScope.of].
///
/// Sits above [MaterialApp] in `main.dart`. When [brightness] changes,
/// Flutter marks every element registered via [of] as dirty — so they
/// rebuild and re-read `AppColors.*` against the new palette without the
/// [Navigator] being recreated or the route stack being reset.
///
/// Widgets that read `AppColors.*` should call `AppColors.watch(context)`
/// (which calls [of] internally) once at the top of their `build()`.
class AppPaletteScope extends InheritedWidget {
  const AppPaletteScope({
    super.key,
    required this.brightness,
    required super.child,
  });

  final Brightness brightness;

  /// Registers a rebuild dependency; returns the current brightness.
  static Brightness of(BuildContext context) {
    return context
        .dependOnInheritedWidgetOfExactType<AppPaletteScope>()!
        .brightness;
  }

  @override
  bool updateShouldNotify(AppPaletteScope old) => brightness != old.brightness;
}

/// The app's live colour tokens.
///
/// Every widget reads colours from here — `AppColors.surface`,
/// `AppColors.textSecondary`, and so on, at roughly 460 call sites — so
/// this stayed the API rather than migrating all of them to
/// `Theme.of(context)`. What changed is that it is no longer a single
/// hardcoded palette: it resolves against whichever mode is active.
///
/// ## How the active mode is set
///
/// [apply] is called once per root build, from `MarketplaceApp`, before
/// the `MaterialApp` is constructed — driven by `ThemeState`. Flipping
/// the toggle changes that state, the root rebuilds, and every widget
/// re-reads these getters against the new palette. So this is a single
/// app-wide global, not something scoped to a subtree: there is exactly
/// one theme on screen at a time, and this app never renders two.
///
/// The trade-off is deliberate. A context-scoped lookup
/// (`Theme.of(context).extension<…>()`, or an InheritedWidget palette)
/// is the textbook answer and needs no global — but it also means
/// touching all ~460 call sites in one commit, with no way to check each
/// by eye. This gets a working two-mode system now and keeps that
/// migration mechanical if it is ever wanted.
class AppColors {
  AppColors._();

  static AppPalette _active = AppPalette.light;

  /// The palette widget code currently reads. Defaults to light — the
  /// app's original identity — until `ThemeState` says otherwise.
  static AppPalette get palette => _active;

  static bool get isDark => _active.isDark;

/// Switches the palette every [AppColors] getter resolves against.
  /// Called from the root build; see the class note above.
  static void apply(Brightness brightness) {
    _active = AppPalette.of(brightness);
  }

  /// Register a rebuild dependency on the current palette.
  ///
  /// Call this once at the top of any `build()` method that reads
  /// `AppColors.*` directly. It registers the caller as a dependent of
  /// [AppPaletteScope], so when the brightness changes Flutter marks
  /// this widget dirty and calls `build()` again — re-reading every
  /// `AppColors.*` getter against the new palette. Without this call,
  /// Flutter's reconciler may skip rebuilding the widget because its
  /// widget configuration hasn't changed.
  ///
  /// No-op when [AppPaletteScope] is not in the tree (e.g. in unit/widget
  /// tests that pump widgets directly). Tests call [apply] in their setUp
  /// instead.
  ///
  /// Example:
  /// ```dart
  /// @override
  /// Widget build(BuildContext context) {
  ///   AppColors.watch(context); // register palette dependency
  ///   final theme = Theme.of(context);
  ///   return Container(color: AppColors.surface, ...);
  /// }
  /// ```
  static void watch(BuildContext context) {
    context.dependOnInheritedWidgetOfExactType<AppPaletteScope>();
    // Null is fine here: tests don't mount AppPaletteScope.
    // The call still registers a dependency when the scope IS present.
  }

  // ── Brand hues ───────────────────────────────────────────────────────
  static Color get primary => _active.primary;
  static Color get primaryDark => _active.primaryDark;
  static Color get secondary => _active.secondary;
  static Color get tertiary => _active.tertiary;
  static Color get neutral => _active.neutral;
  static Color get accent => _active.accent;

  // ── Surfaces ─────────────────────────────────────────────────────────
  static Color get background => _active.background;
  static Color get surface => _active.surface;
  static Color get surfaceMuted => _active.surfaceMuted;

  // ── Text ─────────────────────────────────────────────────────────────
  static Color get textPrimary => _active.textPrimary;
  static Color get textSecondary => _active.textSecondary;
  static Color get textOnDark => _active.textOnDark;

  /// Ink for the mode-invariant light fills (sage, blush). See the field
  /// note on [AppPalette.textOnLight].
  static Color get textOnLight => _active.textOnLight;

  /// A rose ground that small light text can sit on (~7.8:1 in both
  /// modes), unlike [primaryDark], which is a fill. See the field note on
  /// [AppPalette.roseGround].
  static Color get roseGround => _active.roseGround;

  // ── Semantic ─────────────────────────────────────────────────────────
  static Color get success => _active.tertiary;
  static Color get successText => _active.successText;
  static Color get sageIcon => _active.sageIcon;
  static Color get warning => _active.warning;
  static Color get danger => _active.danger;
  static Color get divider => _active.divider;
  static Color get ratingStar => _active.ratingStar;

  /// Ink for icons and loaders drawn on a [hueOnSurface] fill.
  static Color get tintInk => _active.tintInk;

  // ── Atmosphere ───────────────────────────────────────────────────────
  static Gradient get groundGradient => _active.groundGradient;
  static Color get glowCenter => _active.glowCenter;
  static Color get glowBlush => _active.glowBlush;
  static Color get glowSage => _active.glowSage;

  // ── Chrome ───────────────────────────────────────────────────────────
  static Color get shimmerBase => _active.shimmerBase;
  static Color get shimmerHighlight => _active.shimmerHighlight;
  static Color get navShadow => _active.navShadow;
  static Color get navSelectedFill => _active.navSelectedFill;
  static double get cardElevation => _active.cardElevation;
  static Color get cardShadow => _active.cardShadow;

  // ── Snack bar ────────────────────────────────────────────────────────
  static Color get snackBarBackground => _active.snackBarBackground;
  static Color get snackBarText => _active.snackBarText;
  static Color get snackBarAction => _active.snackBarAction;

  // ── Promo carousel grounds ───────────────────────────────────────────
  static Color get promoSageGround => _active.promoSageGround;
  static Color get promoSageAccent => _active.promoSageAccent;
  static Color get promoTerracottaGround => _active.promoTerracottaGround;
  static Color get promoTerracottaAccent => _active.promoTerracottaAccent;
  static Color get onPromoGround => _active.onPromoGround;

  /// A *hue lean* on the card ground — the one correct way to tint a
  /// surface in either mode.
  ///
  /// On paper this is what a pastel token already looked like: a pale
  /// wash. On ink, that same pastel token painted directly is a glowing
  /// rectangle in the one place a product photo belongs, so a loading
  /// tile looks broken rather than loading.
  ///
  /// This composites onto [surface] in whichever mode is active, which is
  /// why it reads as a wash on paper and a lifted dark panel on ink with
  /// no branch on brightness at any call site.
  ///
  /// [amount] is the hue's share *on ink*, where a 30% lean is already a
  /// visible step off the card (~2:1). Paper needs much more hue for the
  /// same job: a card there is near-white, so 30% of a pastel composites
  /// to a colour 1.0:1 against it — literally the same colour. Light mode
  /// therefore scales the share up (see [_lightLean]) so a tint stays a
  /// visible, hue-specific patch on a white card instead of a non-event.
  static Color hueOnSurface(Color hue, [double amount = 0.30]) =>
      Color.alphaBlend(
        hue.withValues(alpha: isDark ? amount : _lightLean(amount)),
        _active.surface,
      );

  /// On paper, a lean needs roughly double the hue to read as a fill:
  /// measured against surfaceMuted, 0.30 → 1.0:1 (invisible) and
  /// 0.75 → ~1.15:1 with an unmistakable hue shift. Capped below 1.0 so a
  /// fill is never just the raw token (which is what made pastels glare on
  /// the dark ground in the first place).
  static double _lightLean(double darkAmount) =>
      (darkAmount * 2.2).clamp(0.0, 0.92);

  /// A saturated *rule* colour for a brand hue, per mode.
  ///
  /// Rules need the opposite treatment to fills. On ink a pale hue reads
  /// beautifully (blush on #2C2018 is ~10:1); on paper that same pale hue
  /// painted 3px thick against white is invisible (#F7D6D0 on white is
  /// 1.4:1). So on paper the hue is pushed toward ink until it becomes a
  /// line you can actually see, and on ink it is used as-is.
  ///
  /// Used for the category band and the registration step band, where the
  /// hue is what tells you which category or step you're in.
  static Color ruleOnGround(Color hue) =>
      isDark ? hue : Color.lerp(hue, _active.textPrimary, 0.45)!;
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
/// The rule when using them: **one display-scale *role* per zone, used
/// consistently for that role** — not one instance per screen.
///
/// The distinction matters, because the literal reading doesn't survive
/// contact with Home. A masthead is Home's single identity moment;
/// [sectionDisplay] is each section's one heading moment; [pullQuote] is
/// the seller-voice moment; [priceNumeral] is the grid's one price role,
/// repeated on every tile. That last one is the point: a role may appear
/// many times *when every instance is playing the same part*, and that
/// isn't visual noise — twenty prices at one size read as a grid. What
/// breaks the hierarchy is a second element claiming a role already taken
/// in the same zone: two 34pt headings in one scroll, a price-size numeral
/// used as a heading, a second wordmark.
///
/// So the test is "does this thing have a role nothing else in this zone
/// has?", not "how many big things can I count?" A masthead only reads as
/// a masthead if nothing nearby is competing, and the tiny [eyebrow] is
/// what makes the big sizes look big — the contrast is the point, not the
/// bigness.
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
  ///
  /// Defaults to [AppColors.accent] — terracotta, which is a different
  /// value per mode (darker on paper, lighter on ink) but the same role:
  /// the one high-chroma colour the price is allowed to spend.
  ///
  /// [color] is nullable rather than defaulted because the palette
  /// resolves at runtime now, and a default argument has to be const.
  static TextStyle priceNumeral(double size, {Color? color}) =>
      GoogleFonts.playfairDisplay(
        fontSize: size,
        height: 1.0,
        fontWeight: FontWeight.w600,
        letterSpacing: size * -0.02,
        color: color ?? AppColors.accent,
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

/// The app's shape tiers — one vocabulary for every corner in the app.
///
/// The app's signature shape is an *asymmetric* corner pair: one long
/// radius on a diagonal, one short radius opposite it, so a card reads as
/// a deliberate leaf rather than a default box. That's why each tier
/// carries [big] and [small]. [symmetric] is the same tier expressed as a
/// single radius, for Material surfaces that can't take an asymmetric
/// corner — sheets, buttons, ink splashes, app-bar chrome.
///
/// ## Why this exists
///
/// The tiers were introduced once before (as `EditorialTier`), with a doc
/// comment asking call sites to prefer them over raw numbers. It didn't
/// take: call sites kept passing `big:`/`small:`, and the app drifted to
/// **17 distinct corner pairs** — a shape vocabulary in name only, where
/// 22/8 on one screen and 24/8 on another were supposed to be the same
/// surface. Two passes documented the intent and moved on.
///
/// So the rule is now enforced by the API rather than by a comment:
/// `editorialRadius(index, tier)` takes a tier, full stop. If a shape
/// genuinely can't use a tier — because its corners are proportional to
/// its own measured size — it has to say so out loud via
/// `editorialRadiusRaw` (two call sites do; both pass fractions of a size).
///
/// | tier    | asymmetric | symmetric | for                                    |
/// |---------|------------|-----------|----------------------------------------|
/// | hero    | 44 / 10    | 24        | full-bleed feature surfaces, sheets    |
/// | card    | 28 / 8     | 16        | content cards, tiles, list rows        |
/// | accent  | 20 / 6     | 12        | inset surfaces: review rows, uploads   |
/// | control | 8 / 4      | 8         | buttons, fields, chips, steppers       |
/// | pill    | 999        | 999       | pills, badges, dots — fully round      |
///
/// Documented exceptions that are *not* tiers, and shouldn't be "fixed"
/// into one: chat bubble tails (asymmetric by message direction, not by
/// size), icon-glyph geometry (`nav_icons`), the arch/wave/diagonal
/// painters, and hairline details under 4px.
enum ShapeTier {
  /// 44 / 10 — full-bleed feature surfaces: profile hero, earnings
  /// summary, gallery, the wide grid-interrupt tile.
  hero(44, 10, 24),

  /// 28 / 8 — standard content surfaces: order cards, job cards, section
  /// panels, product tiles.
  card(28, 8, 16),

  /// 20 / 6 — smaller inset surfaces: review tiles, seller row, proof
  /// upload box, rails.
  accent(20, 6, 12),

  /// 8 / 4 — controls: buttons, text fields, chips, quantity steppers.
  control(8, 4, 8),

  /// Fully round. No asymmetric form — a pill has no "long diagonal".
  pill(999, 999, 999);

  const ShapeTier(this.big, this.small, this.symmetric);

  /// The long radius of the asymmetric pair.
  final double big;

  /// The short radius, opposite [big].
  final double small;

  /// The same tier as one radius, for symmetric Material surfaces.
  final double symmetric;
}

/// The app's component themes, one per mode.
class AppTheme {
  AppTheme._();

  /// Both modes, built from [AppPalette] data.
  ///
  /// `MaterialApp` evaluates BOTH `theme` and `darkTheme` on every build
  /// (it needs both to run the mode-switch animation), so these factories
  /// must never read [AppColors] — the *active* palette. If they did,
  /// whichever one ran second would win for both. That is why [_build]
  /// takes its palette as an argument and every line below says `p.`
  /// rather than `AppColors.`. Widgets still read `AppColors.` exactly as
  /// before; only the ThemeData factories are palette-parameterised.
  static ThemeData get light => _build(AppPalette.light);
  static ThemeData get dark => _build(AppPalette.dark);

  static ThemeData _build(AppPalette p) {
    final base = ThemeData(useMaterial3: true, brightness: p.brightness);

    final colorScheme = ColorScheme.fromSeed(
      seedColor: p.primary,
      brightness: p.brightness,
      primary: p.primaryDark,
      secondary: p.tertiary,
      tertiary: p.accent,
      surface: p.surface,
      error: p.danger,
    );

    final textTheme = _buildTextTheme(base.textTheme, p);

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
      progressIndicatorTheme: ProgressIndicatorThemeData(
        color: p.primaryDark,
      ),

      // ----- AppBar -----
      appBarTheme: AppBarTheme(
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent, // stop Material 3's default scroll-tint overlay from breaking the seamless gradient
        elevation: 0,
        scrolledUnderElevation: 0, // stop Material 3 from drawing a scroll shadow over the new transparent/gradient background
        foregroundColor: p.textPrimary,
        centerTitle: true,
        titleTextStyle: textTheme.titleLarge?.copyWith(
          fontFamily: GoogleFonts.playfairDisplay().fontFamily,
          fontWeight: FontWeight.w600,
        ),
        iconTheme: IconThemeData(color: p.textPrimary),
      ),

      // ----- Cards -----
      // Elevation and shadow are palette values, not constants: on paper
      // a card lifts with a soft shadow, on ink a shadow is invisible and
      // the lift has to come from the surface colour step
      // (background → surface → surfaceMuted) instead.
      cardTheme: CardThemeData(
        color: p.surfaceMuted,
        elevation: p.cardElevation,
        shadowColor: p.cardShadow,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(ShapeTier.card.symmetric),
        ),
      ),

      // ----- Inputs -----
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: p.surface,
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        hintStyle: textTheme.bodyMedium?.copyWith(color: p.textSecondary),
        prefixIconColor: p.textSecondary,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(ShapeTier.control.symmetric),
          borderSide: BorderSide(color: p.divider),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(ShapeTier.control.symmetric),
          borderSide: BorderSide(color: p.divider),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(ShapeTier.control.symmetric),
          borderSide: BorderSide(color: p.primaryDark, width: 1.5),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(ShapeTier.control.symmetric),
          borderSide: BorderSide(color: p.danger),
        ),
      ),

      // ----- Buttons -----
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: p.primaryDark,
          foregroundColor: p.textOnDark,
          disabledBackgroundColor: p.divider,
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
          textStyle: textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(ShapeTier.control.symmetric),
          ),
          elevation: 0,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: p.textPrimary,
          side: BorderSide(color: p.divider),
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
          textStyle: textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(ShapeTier.control.symmetric),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: p.primaryDark,
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
          backgroundColor: p.surface,
          foregroundColor: p.textPrimary,
          selectedBackgroundColor: p.primaryDark,
          selectedForegroundColor: p.textOnDark,
          side: BorderSide(color: p.divider),
          textStyle: textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w600),
        ),
      ),

      // ----- Chips (used for category pills / "Secondary" style tokens) -----
      chipTheme: base.chipTheme.copyWith(
        backgroundColor: p.surface,
        selectedColor: p.primaryDark,
        labelStyle: textTheme.labelMedium?.copyWith(color: p.textPrimary),
        secondaryLabelStyle:
            textTheme.labelMedium?.copyWith(color: p.textOnDark),
        shape: StadiumBorder(side: BorderSide(color: p.divider)),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      ),

      // ----- Bottom nav (base fallback; the floating pill nav is a
      // custom widget — see widgets/floating_nav_bar.dart) -----
      bottomNavigationBarTheme: BottomNavigationBarThemeData(
        backgroundColor: p.surface,
        selectedItemColor: p.primaryDark,
        unselectedItemColor: p.textSecondary,
        type: BottomNavigationBarType.fixed,
        showUnselectedLabels: true,
      ),

      dividerTheme: DividerThemeData(
        color: p.divider,
        thickness: 1,
        space: 1,
      ),

      iconTheme: IconThemeData(color: p.textPrimary),

      snackBarTheme: SnackBarThemeData(
        // A snack bar has to be the highest-contrast surface on screen to
        // read as transient chrome, which means it inverts against the
        // page: dark bar on paper, light-ish surface on ink. Both modes
        // end up with the same relationship, from opposite directions —
        // see the two palettes.
        backgroundColor: p.snackBarBackground,
        contentTextStyle: textTheme.bodyMedium?.copyWith(color: p.snackBarText),
        actionTextColor: p.snackBarAction,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(ShapeTier.control.symmetric),
        ),
      ),

      extensions: [
        AppThemeExtension(
          invertedButtonColor: p.textPrimary,
          invertedButtonTextColor: p.textOnDark,
          heroRadius: ShapeTier.hero.symmetric,
          cardRadius: ShapeTier.card.symmetric,
          utilityRadius: ShapeTier.control.symmetric,
          pillRadius: ShapeTier.pill.symmetric,
        ),
      ],
    );
  }

  static TextTheme _buildTextTheme(TextTheme base, AppPalette p) {
    final headline = GoogleFonts.playfairDisplayTextTheme(base);
    final body = GoogleFonts.montserratTextTheme(base);

    return body.copyWith(
      displayLarge: headline.displayLarge?.copyWith(color: p.textPrimary),
      displayMedium: headline.displayMedium?.copyWith(color: p.textPrimary),
      displaySmall: headline.displaySmall?.copyWith(color: p.textPrimary),
      headlineLarge: headline.headlineLarge?.copyWith(color: p.textPrimary),
      headlineMedium: headline.headlineMedium?.copyWith(color: p.textPrimary),
      headlineSmall: headline.headlineSmall?.copyWith(
        color: p.textPrimary,
        fontWeight: FontWeight.w600,
      ),
      titleLarge: headline.titleLarge?.copyWith(
        color: p.textPrimary,
        fontWeight: FontWeight.w600,
      ),
      titleMedium: body.titleMedium?.copyWith(color: p.textPrimary),
      titleSmall: body.titleSmall?.copyWith(color: p.textPrimary),
      bodyLarge: body.bodyLarge?.copyWith(color: p.textPrimary),
      bodyMedium: body.bodyMedium?.copyWith(color: p.textPrimary),
      bodySmall: body.bodySmall?.copyWith(color: p.textSecondary),
      labelLarge: body.labelLarge?.copyWith(color: p.textPrimary),
      labelMedium: body.labelMedium?.copyWith(color: p.textSecondary),
      labelSmall: body.labelSmall?.copyWith(color: p.textSecondary),
    );
  }
}
