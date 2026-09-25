// lib/widgets/landing_backdrop.dart
//
// A dedicated, continuously-looping animated background for the landing
// screen. NOT a variant of AppAtmosphere and not shared with any other
// screen — this is the one place in the app where a genuinely animated
// background belongs, so it gets its own widget rather than motion
// smuggled into the app-wide atmosphere every other screen also renders.
//
// Five soft-edge circles drift in independent elliptical orbits —
// different radius, speed and starting phase each — across the whole
// canvas at once. Positions are fractions of the available size (via
// LayoutBuilder), so the composition holds on any device.
//
// -------------------------------------------------------------------
// Second pass, after the first one read as "barely visible" even with
// wide orbits and a full-canvas loop. The orbits weren't the problem —
// contrast was. Every color in this palette is a pastel sitting close
// in lightness to the pale background wash, and a gradient that fades
// all the way to transparent at the shape's edge means even a big
// shape sweeping across a third of the screen reads as a faint tint
// shift, not motion. Two changes address that directly, and one
// addresses perception rather than physics:
//
//   1. Higher alpha at the core, AND a held mid-stop. The old gradient
//      was a straight two-stop fade (full color -> nothing), which
//      means most of the shape's visible radius was already quite
//      faint. A third stop holds the color near peak alpha out to 55%
//      of the radius before falling off, so the shape has a real,
//      visible body instead of just a soft hint at its center.
//   2. The loop is roughly half the length it was (14s vs 26s). Real
//      motion you can register in a two-second glance, not something
//      that only becomes obvious if you stare at one spot for a while.
//
// The center blob (index 2, primary-colored, sitting roughly behind
// the wordmark) is deliberately NOT pushed as hard as the other four —
// it sits directly behind the "Zefanya" text and tagline, and Text's
// contrast against the backdrop matters more there than raw visibility
// does. The Begin button doesn't have this problem (opaque fill), so
// nothing near the bottom of the screen needed the same caution.
//
// Reduced motion: the controller never starts. Every shape sits at its
// t=0 resting position, completely still.
// -------------------------------------------------------------------

import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

class LandingBackdrop extends StatefulWidget {
  const LandingBackdrop({super.key, this.reduceMotion = false});

  final bool reduceMotion;

  @override
  State<LandingBackdrop> createState() => _LandingBackdropState();
}

class _LandingBackdropState extends State<LandingBackdrop>
    with SingleTickerProviderStateMixin {
  // One full loop every 14s. Individual shapes move at 0.6x-1.3x this
  // (see _blobs), so nothing on screen repeats its exact path on a
  // predictable beat.
  static const _loopDuration = Duration(seconds: 14);

  late final AnimationController _controller =
      AnimationController(vsync: this, duration: _loopDuration);

  @override
  void initState() {
    super.initState();
    if (!widget.reduceMotion) _controller.repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final w = constraints.maxWidth;
        final h = constraints.maxHeight;

        return Stack(
          fit: StackFit.expand,
          children: [
            // Base warm wash — same recipe as AppAtmosphere's, so this
            // screen still sits on the app's usual background color.
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
            AnimatedBuilder(
              animation: _controller,
              builder: (context, _) {
                final t = _controller.value; // 0..1, loops forever
                return Stack(
                  children: [for (final blob in _blobs) _buildBlob(blob, t, w, h)],
                );
              },
            ),
          ],
        );
      },
    );
  }

  Widget _buildBlob(_Blob blob, double t, double w, double h) {
    final angle = 2 * math.pi * (t * blob.speed + blob.phase);
    final cx = blob.centerX * w + math.cos(angle) * blob.orbitX * w;
    final cy = blob.centerY * h + math.sin(angle) * blob.orbitY * h;
    final scale = 1 + math.sin(angle * 1.3) * 0.08;

    return Positioned(
      left: cx - blob.size / 2,
      top: cy - blob.size / 2,
      width: blob.size,
      height: blob.size,
      child: Transform.scale(
        scale: scale,
        child: DecoratedBox(
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            // Three stops, not two: the color holds near peak alpha
            // out to just past half the radius, THEN falls off. A
            // plain two-stop fade looks soft everywhere; this gives
            // the shape an actual visible body with a soft edge,
            // rather than being soft everywhere.
            gradient: RadialGradient(
              colors: [
                blob.color.withValues(alpha: blob.alpha),
                blob.color.withValues(alpha: blob.alpha * 0.85),
                blob.color.withValues(alpha: 0),
              ],
              stops: const [0.0, 0.55, 1.0],
            ),
          ),
        ),
      ),
    );
  }
}

class _Blob {
  const _Blob({
    required this.color,
    required this.size,
    required this.alpha,
    required this.centerX,
    required this.centerY,
    required this.orbitX,
    required this.orbitY,
    required this.speed,
    required this.phase,
  });

  final Color color;
  final double size;
  final double alpha;
  final double centerX;
  final double centerY;
  final double orbitX;
  final double orbitY;
  final double speed;
  final double phase;
}

const _blobs = [
  _Blob(
    color: AppColors.secondary,
    size: 460,
    alpha: 0.78,
    centerX: 0.82,
    centerY: 0.1,
    orbitX: 0.30,
    orbitY: 0.14,
    speed: 1.0,
    phase: 0.0,
  ),
  _Blob(
    color: AppColors.tertiary,
    size: 480,
    alpha: 0.65,
    centerX: 0.12,
    centerY: 0.92,
    orbitX: 0.26,
    orbitY: 0.16,
    speed: 0.8,
    phase: 0.32,
  ),
  // Center blob — kept gentler than the rest on purpose. It sits
  // behind the wordmark and tagline; visibility here trades directly
  // against text contrast, so it stays the most conservative of the
  // five rather than getting the same alpha bump as the corner shapes.
  _Blob(
    color: AppColors.primary,
    size: 280,
    alpha: 0.48,
    centerX: 0.5,
    centerY: 0.32,
    orbitX: 0.20,
    orbitY: 0.14,
    speed: 1.3,
    phase: 0.55,
  ),
  _Blob(
    color: AppColors.primary,
    size: 210,
    alpha: 0.58,
    centerX: 0.2,
    centerY: 0.22,
    orbitX: 0.16,
    orbitY: 0.20,
    speed: 0.6,
    phase: 0.15,
  ),
  _Blob(
    color: AppColors.tertiary,
    size: 200,
    alpha: 0.55,
    centerX: 0.82,
    centerY: 0.68,
    orbitX: 0.20,
    orbitY: 0.15,
    speed: 1.15,
    phase: 0.72,
  ),
];
