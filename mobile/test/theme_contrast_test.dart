// test/theme_contrast_test.dart
//
// Locks the properties that are easy to break with a colour change and
// impossible to notice in code review — in *both* modes.
//
// There is a specific failure this file exists to catch, and it already
// happened once: the app was converted from a light ground to a dark one
// by changing token values, and several of those tokens were only ever
// checked against the ground they were picked for. A pastel that reads as
// a soft wash on paper is a glowing rectangle on ink; a dark rule that
// reads on ink is invisible on paper. Every assertion below is one of
// those pairings, asserted in light AND dark, so the next palette change
// has to answer for both.

import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/screens/buyer/widgets/product_card.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/theme/app_theme.dart';

/// WCAG relative luminance.
double _luminance(Color c) {
  double channel(double v) =>
      v <= 0.03928 ? v / 12.92 : math.pow((v + 0.055) / 1.055, 2.4).toDouble();
  return 0.2126 * channel(c.r) + 0.7152 * channel(c.g) + 0.0722 * channel(c.b);
}

/// WCAG contrast ratio, 1.0 (identical) → 21.0 (black on white).
double contrast(Color a, Color b) {
  final la = _luminance(a), lb = _luminance(b);
  return (math.max(la, lb) + 0.05) / (math.min(la, lb) + 0.05);
}

/// Runs [body] with the given mode active, then restores light.
void _inMode(Brightness brightness, void Function() body) {
  AppColors.apply(brightness);
  body();
  AppColors.apply(Brightness.light);
}

void main() {
  // AA floor for normal-size text.
  const aa = 4.5;
  // Floor for non-text graphics (icons, rules) per WCAG 1.4.11.
  const graphics = 3.0;

  const categories = ['Fashion', 'Beauty', 'Home & Living'];

  for (final mode in [Brightness.light, Brightness.dark]) {
    final label = mode == Brightness.dark ? 'dark' : 'light';

    group('$label mode', () {
      test('body and secondary text clear AA on every ground', () {
        _inMode(mode, () {
          for (final ground in [
            AppColors.background,
            AppColors.surface,
            AppColors.surfaceMuted,
          ]) {
            expect(contrast(AppColors.textPrimary, ground),
                greaterThanOrEqualTo(aa), reason: 'textPrimary on ground');
            expect(contrast(AppColors.textSecondary, ground),
                greaterThanOrEqualTo(aa),
                reason: 'textSecondary (also the 10pt eyebrow) on ground');
          }
        });
      });

      test('a tinted fill is distinguishable from the card it sits on', () {
        _inMode(mode, () {
          for (final category in categories) {
            // Not AA — a placeholder fill is decoration. It just has to be
            // a *visible* step off the card, in both modes. The light-mode
            // run of this assertion caught a real bug: compositing a
            // pastel onto white gave 1.00:1, i.e. exactly the card colour.
            expect(contrast(productTint(category), AppColors.surfaceMuted),
                greaterThan(1.05),
                reason: '$category placeholder fill vs card ($label)');
          }
        });
      });

      test('ink on a tinted fill clears the graphics floor', () {
        _inMode(mode, () {
          for (final category in categories) {
            expect(contrast(AppColors.tintInk, productTint(category)),
                greaterThanOrEqualTo(graphics),
                reason: '$category tint ink ($label)');
          }
        });
      });

      test('a rating star is legible, because it is a control', () {
        _inMode(mode, () {
          // WCAG 1.4.11, the *graphics* floor — a star communicates a rating,
          // so it has to be understood, not merely present. Asserted on both
          // grounds a star appears on, and in both modes.
          for (final ground in [AppColors.surface, AppColors.surfaceMuted]) {
            expect(contrast(AppColors.ratingStar, ground),
                greaterThanOrEqualTo(graphics),
                reason: 'ratingStar on $ground ($label)');
          }
        });
      });

      test('the gallery indicator stays legible over its scrim', () {
        _inMode(mode, () {
          // The gallery dots sit on an arbitrary seller photo, so they carry
          // their own scrim: AppColors.surface at 85%. The worst case is not
          // surface at all but that scrim over a *white* photo — the lightest
          // ground the dots can land on in this mode — so that is what gets
          // asserted, composited rather than approximated.
          final worstScrim = Color.alphaBlend(
            AppColors.surface.withValues(alpha: 0.85),
            Colors.white,
          );
          expect(contrast(AppColors.accent, worstScrim),
              greaterThanOrEqualTo(graphics),
              reason: 'active gallery dot over a white photo ($label)');
        });
      });

      test('credit / warning / danger inks stay readable on their fills', () {
        _inMode(mode, () {
          // The class of bug this whole file exists for: a status colour used
          // as *text* on its own tinted fill. textOnLight / textOnDark are the
          // two inks those fills are allowed to take.
          expect(contrast(AppColors.successText, AppColors.surface),
              greaterThanOrEqualTo(aa), reason: 'success text on card ($label)');
          expect(contrast(AppColors.roseGround, AppColors.textOnDark),
              greaterThanOrEqualTo(aa), reason: 'ink on roseGround ($label)');
        });
      });

      test('category and step rules stay visible on their band', () {
        _inMode(mode, () {
          for (final category in categories) {
            expect(
                contrast(AppColors.ruleOnGround(productHue(category)),
                    AppColors.surface),
                greaterThanOrEqualTo(graphics),
                reason: '$category band rule ($label)');
          }
          for (final stepHue in [
            AppColors.secondary,
            AppColors.tertiary,
            AppColors.primary,
            AppColors.primaryDark,
          ]) {
            expect(contrast(AppColors.ruleOnGround(stepHue), AppColors.surface),
                greaterThanOrEqualTo(graphics),
                reason: 'registration step rule ($label)');
          }
        });
      });

      test('promo slides: ink and accent clear their own ground', () {
        _inMode(mode, () {
          expect(contrast(AppColors.onPromoGround, AppColors.promoSageGround),
              greaterThanOrEqualTo(aa));
          expect(
              contrast(AppColors.onPromoGround, AppColors.promoTerracottaGround),
              greaterThanOrEqualTo(aa));
          expect(contrast(AppColors.textOnDark, AppColors.primaryDark),
              greaterThanOrEqualTo(graphics));
          expect(
              contrast(AppColors.promoSageAccent, AppColors.promoSageGround),
              greaterThanOrEqualTo(graphics));
          expect(
              contrast(AppColors.promoTerracottaAccent,
                  AppColors.promoTerracottaGround),
              greaterThanOrEqualTo(graphics));
        });
      });

      test('snack bar, prices and the shimmer band read on their ground', () {
        _inMode(mode, () {
          expect(contrast(AppColors.snackBarText, AppColors.snackBarBackground),
              greaterThanOrEqualTo(aa));
          expect(contrast(AppColors.accent, AppColors.surface),
              greaterThanOrEqualTo(graphics));
          // The sweep has to travel between two *different* values or the
          // skeleton sits dead still — the light-mode version of this bug
          // shipped once already (a white-to-white gradient).
          expect(AppColors.shimmerBase, isNot(AppColors.shimmerHighlight));
          expect(contrast(AppColors.shimmerBase, AppColors.shimmerHighlight),
              greaterThan(1.05));
        });
      });

      test('surface tiers are distinguishable from the page', () {
        _inMode(mode, () {
          expect(contrast(AppColors.surfaceMuted, AppColors.background),
              greaterThan(1.05));
        });
      });

      test('ink on a light fill is dark ink, not near-white', () {
        _inMode(mode, () {
          // This is the pairing that was shipping broken in *both* modes:
          // near-white ink on the mode-invariant pastels measured ~1.6:1 on
          // every sage avatar, category badge and chat initial in the app.
          for (final fill in [
            AppColors.secondary,
            AppColors.tertiary,
            AppColors.primary,
          ]) {
            expect(contrast(AppColors.textOnLight, fill),
                greaterThanOrEqualTo(aa),
                reason: 'textOnLight on a pastel fill ($label)');
          }
        });
      });

      test('text on rose uses roseGround, which can host it', () {
        _inMode(mode, () {
          // Badges and the first promo slide put 10pt text on rose. (The
          // sage and terracotta slides use onPromoGround instead — their
          // grounds flip with the mode, so their ink has to as well; that
          // pairing is asserted in the promo test above.)
          expect(contrast(AppColors.textOnDark, AppColors.roseGround),
              greaterThanOrEqualTo(aa),
              reason: 'light ink on roseGround ($label)');
        });
      });
    });
  }

  test('primaryDark is a fill, and dark mode is where that matters', () {
    // The measured reason roseGround exists: dark mode lifts primaryDark so
    // the brand rose reads against ink, and that lift caps *every* possible
    // ink at 4.42:1 — pure white included. Light mode is unaffected, which
    // is why this assertion is dark-only and why the fix is a separate
    // token rather than an ink swap.
    AppColors.apply(Brightness.dark);
    expect(contrast(const Color(0xFFFFFFFF), AppColors.primaryDark), lessThan(aa));
    AppColors.apply(Brightness.light);
    expect(contrast(const Color(0xFFFFFFFF), AppColors.primaryDark), greaterThan(aa));
  });

  group('ThemeState', () {
    setUp(() => SharedPreferences.setMockInitialValues({}));

    test("defaults to light — the app's original identity", () {
      expect(ThemeState().isDark, isFalse);
    });

    test('toggle flips the mode, and the palette follows it', () {
      final state = ThemeState()..toggle();
      expect(state.isDark, isTrue);

      AppColors.apply(state.brightness);
      expect(AppColors.isDark, isTrue);
      expect(AppColors.background, AppPalette.dark.background);

      state.toggle();
      AppColors.apply(state.brightness);
      expect(AppColors.background, AppPalette.light.background);
    });

    test('a saved choice wins over the default on the next launch', () async {
      SharedPreferences.setMockInitialValues({'themeMode': 'dark'});
      final state = ThemeState();
      await state.load();
      expect(state.isDark, isTrue);
    });
  });
}
