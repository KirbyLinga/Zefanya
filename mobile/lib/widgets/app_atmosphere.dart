// lib/widgets/app_atmosphere.dart
//
// The app's background, extracted out of main.dart's MaterialApp.builder
// so it exists in exactly one place.
//
// Three glows now, not two: blush (upper-right), sage (lower-left), and
// a smaller, quieter primary-pink glow sitting roughly centered, behind
// whatever content the screen puts on top. It's there for depth at
// rest — two big soft circles on a flat wash reads as empty the moment
// nothing else is moving, and this is what keeps the app-wide static
// version from feeling bare without changing its palette or its calm.
//
// [drift] and [scale] are the animation hooks. Static everywhere except
// landing_screen.dart, which is still the one place that moves this —
// see that file for why: it's the one screen where a screen full of
// motion isn't competing with an active task, per the login-background
// discussion this replaced. Values stay at their defaults (Offset.zero,
// 1.0) everywhere else, so nothing here changes for any other screen.
//
// It paints an opaque base, so a screen that renders its own
// AppAtmosphere on top of the app-wide one fully covers it — no
// double-exposed glows.

import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

class AppAtmosphere extends StatelessWidget {
  const AppAtmosphere({super.key, this.drift = Offset.zero, this.scale = 1.0});

  /// How far the two main glows have travelled from their resting
  /// position. Blush (upper-right) moves by +drift, sage (lower-left)
  /// by -drift, so they ease apart rather than sliding in convoy.
  final Offset drift;

  /// Uniform breathing scale applied to all three glows. 1.0 = resting
  /// size, used everywhere except landing's continuous pulse.
  final double scale;

  @override
  Widget build(BuildContext context) {
    return Stack(
      fit: StackFit.expand,
      children: [
        // Base warm wash.
        DecoratedBox(
          decoration: BoxDecoration(
            gradient: RadialGradient(
              center: Alignment.topCenter,
              radius: 1.3,
              colors: [
                Color.lerp(AppColors.background, Colors.white, 0.55)!,
                AppColors.background,
              ],
            ),
          ),
        ),
        // Center accent glow — quiet, low-alpha, sits behind content.
        // Painted first (under blush/sage) and kept still relative to
        // drift on purpose: if all three glows moved together the
        // composition would just read as "the background is sliding,"
        // the same failure mode a login background animation would
        // have had. This one only breathes with [scale]; it doesn't
        // drift.
        Align(
          alignment: const Alignment(0, -0.1),
          child: Transform.scale(
            scale: scale,
            child: SizedBox(
              width: 360,
              height: 360,
              child: DecoratedBox(
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: RadialGradient(
                    colors: [
                      AppColors.primary.withValues(alpha: 0.4),
                      AppColors.primary.withValues(alpha: 0.0),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
        // Soft blush glow, upper-right — off-canvas center so only the
        // falloff edge is visible, like ambient light rather than a shape.
        Positioned(
          top: -120 + drift.dy,
          right: -140 + drift.dx,
          width: 420,
          height: 420,
          child: Transform.scale(
            scale: scale,
            child: DecoratedBox(
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: RadialGradient(
                  colors: [
                    AppColors.secondary.withValues(alpha: 0.55),
                    AppColors.secondary.withValues(alpha: 0.0),
                  ],
                ),
              ),
            ),
          ),
        ),
        // Soft sage glow, lower-left — the app's most under-used accent,
        // present as atmosphere everywhere instead of one badge.
        Positioned(
          bottom: -160 - drift.dy,
          left: -160 - drift.dx,
          width: 460,
          height: 460,
          child: Transform.scale(
            scale: scale,
            child: DecoratedBox(
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: RadialGradient(
                  colors: [
                    AppColors.tertiary.withValues(alpha: 0.35),
                    AppColors.tertiary.withValues(alpha: 0.0),
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}
