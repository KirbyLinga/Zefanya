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
  // The sweep has to travel between two values that actually differ *in
  // the current mode*. On paper that pair is a warm tint → white; on ink
  // it's a lifted dark → the card surface. Both pairs live in the palette,
  // so this is no longer a set of constants.
  Color get _base => AppColors.shimmerBase;
  Color get _highlight => AppColors.shimmerHighlight;

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
              colors: [_base, _highlight, _base],
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
