// lib/widgets/shimmer_box.dart
//
// Wrap a static skeleton placeholder in this to get the classic
// left-to-right shimmer sweep instead of a dead gray box sitting there
// while data loads. No new dependency — built from a looping
// AnimationController + a gradient shader mask.
//
// Reduced motion: the sweep never starts. The controller sits at 0,
// which renders as the plain warm-tinted skeleton with no highlight band —
// the correct "end state" for a loop that has no real destination
// beyond "keep moving." A flat skeleton still communicates "loading,"
// which is the one thing this widget actually needs to say.

import 'package:flutter/material.dart';
import '../theme/app_theme.dart';
import '../utils/motion.dart';

class ShimmerBox extends StatefulWidget {
  const ShimmerBox({super.key, required this.child});

  final Widget child;

  @override
  State<ShimmerBox> createState() => _ShimmerBoxState();
}

class _ShimmerBoxState extends State<ShimmerBox> with SingleTickerProviderStateMixin {
  // The sweep used to run surfaceMuted -> surface -> surfaceMuted, but both
  // of those are pure white (0xFFFFFFFF), so the gradient was white-to-white:
  // no visible band, and white tiles on a near-white page barely read as
  // skeletons at all. The band has to travel between two colors that
  // actually differ. `_base` is a warm tint just deeper than the divider, so
  // it holds up against the page gradient; `_highlight` is the card white.
  //
  // (The ShaderMask uses srcATop, which replaces the child's own color with
  // the gradient wherever the child paints — so these two colors ARE the
  // skeleton's look, whatever fill the wrapped Container declares.)
  static const Color _base = Color(0xFFE6DAD8);
  static const Color _highlight = AppColors.surface;

  late final AnimationController _controller =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 1100));

  bool _started = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;
    if (!reduceMotion(context)) _controller.repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, child) {
        return ShaderMask(
          blendMode: BlendMode.srcATop,
          shaderCallback: (bounds) {
            // Sweep a soft highlight band across the box, looping from
            // just off the left edge to just off the right edge.
            final t = _controller.value;
            final dx = bounds.width * 2;
            return LinearGradient(
              begin: Alignment.centerLeft,
              end: Alignment.centerRight,
              colors: const [_base, _highlight, _base],
              stops: const [0.35, 0.5, 0.65],
              transform: _SlideGradient(dx * t - bounds.width),
            ).createShader(bounds);
          },
          child: child,
        );
      },
      child: widget.child,
    );
  }
}

class _SlideGradient extends GradientTransform {
  const _SlideGradient(this.dx);
  final double dx;

  @override
  Matrix4? transform(Rect bounds, {TextDirection? textDirection}) {
    return Matrix4.translationValues(dx, 0, 0);
  }
}
