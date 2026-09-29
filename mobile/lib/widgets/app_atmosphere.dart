// lib/widgets/app_atmosphere.dart
//
// The app's background, extracted out of main.dart's MaterialApp.builder
// so it exists in exactly one place.
//
// The three glows are the only things that make the background feel alive
// rather than flat, and each mode needs its own values for them: a pale
// glow reads on paper because it contrasts with white, and disappears on
// ink, where the same job needs more alpha over a dark ground. Both sets
// live in the palette (glowCenter / glowBlush / glowSage, and the base
// gradient itself), so this file is geometry and structure only — it has
// no colour literals and no branch on brightness.
//
// Three glows:
//   • Upper-right: blush pink — warm, human, brand-adjacent
//   • Lower-left:  sage green — cool contrast to the blush, keeps
//                  the ground from reading as "just dark red"
//   • Center:      primary rose — subtle depth, anchors the middle
//
// [drift] and [scale] are the animation hooks for landing_screen.dart.
// Static everywhere else.

import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

class AppAtmosphere extends StatelessWidget {
  const AppAtmosphere({super.key, this.drift = Offset.zero, this.scale = 1.0});

  final Offset drift;
  final double scale;

  @override
  Widget build(BuildContext context) {
    return Stack(
      fit: StackFit.expand,
      children: [
        // Base wash — the palette owns the whole gradient per mode: a
        // warm radial lift on paper (lighter at the top), a subtle
        // top-down lift on ink. The glows do the atmospheric work.
        DecoratedBox(
          decoration: BoxDecoration(gradient: AppColors.groundGradient),
        ),
        // Center rose glow — low-alpha, keeps the very middle from
        // reading as a flat void when no card is on screen.
        Align(
          alignment: const Alignment(0, -0.15),
          child: Transform.scale(
            scale: scale,
            child: SizedBox(
              width: 380,
              height: 380,
              child: DecoratedBox(
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: RadialGradient(
                    colors: [
                      AppColors.glowCenter,
                      AppColors.glowCenter.withValues(alpha: 0.0),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
        // Upper-right blush glow.
        Positioned(
          top: -100 + drift.dy,
          right: -120 + drift.dx,
          width: 440,
          height: 440,
          child: Transform.scale(
            scale: scale,
            child: DecoratedBox(
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: RadialGradient(
                  colors: [
                    AppColors.glowBlush,
                    AppColors.glowBlush.withValues(alpha: 0.0),
                  ],
                ),
              ),
            ),
          ),
        ),
        // Lower-left sage glow.
        Positioned(
          bottom: -140 - drift.dy,
          left: -140 - drift.dx,
          width: 480,
          height: 480,
          child: Transform.scale(
            scale: scale,
            child: DecoratedBox(
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: RadialGradient(
                  colors: [
                    AppColors.glowSage,
                    AppColors.glowSage.withValues(alpha: 0.0),
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
