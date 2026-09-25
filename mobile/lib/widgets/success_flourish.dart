// lib/widgets/success_flourish.dart
//
// The order-confirmation moment — previously a plain gradient circle
// with a Material check icon and a glow, unrevisited since the editorial
// pass touched every other "big moment" in the app (add-to-cart, every
// loading state) but not this one. That's backwards: completing a
// purchase is the actual business goal, and it was shipping with less
// craft than adding a single item to a cart.
//
// What this does instead, once, on mount:
//   1. The Zefanya mark traces on (same stroke as ZefanyaLoader, but a
//      single pass rather than a loop — this is a conclusion, not a wait).
//   2. It holds for a beat, then cross-fades into a checkmark stroke that
//      draws itself the same way.
//   3. A soft ring expands and fades outward once, like a single pulse
//      rather than a spinner — confirmation, not activity.
// Everything is vector strokes on an asymmetric (editorial_shapes) blob,
// not a perfect circle, so the shape language matches the rest of the
// app instead of reverting to a plain circle for the one screen that
// matters most.
//
// Reduced motion: the whole sequence resolves to its finished frame
// instantly instead of playing — mark hidden, checkmark fully drawn,
// ring fully faded (a pulse that's already finished is just "gone",
// which is correct; a one-shot animation frozen at t=1 IS its end
// state, there's nothing left to hold). The haptics stay: they're not
// visual motion, and they're this widget's only other way of
// confirming "this succeeded" — cutting them along with the animation
// would leave reduced-motion users with strictly less confirmation
// than everyone else gets, not just less motion.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../theme/app_theme.dart';
import '../utils/motion.dart';
import 'editorial_shapes.dart';
import 'zefanya_mark.dart';

class SuccessFlourish extends StatefulWidget {
  const SuccessFlourish({super.key, this.size = 96});

  final double size;

  @override
  State<SuccessFlourish> createState() => _SuccessFlourishState();
}

class _SuccessFlourishState extends State<SuccessFlourish> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1500),
  );

  // Named breakpoints on the single 0..1 timeline, rather than chaining
  // several separate controllers — keeps the sequence (mark -> hold ->
  // checkmark -> ring) easy to read and re-time in one place.
  static const double _markDrawEnd = 0.36;
  static const double _holdEnd = 0.5;
  static const double _checkDrawEnd = 0.82;

  bool _started = false;

  @override
  void initState() {
    super.initState();
    // Haptics fire regardless of reduceMotion -- see file header.
    HapticFeedback.mediumImpact();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;
    if (reduceMotion(context)) {
      _controller.value = 1;
      // The controller jumping straight to 1 means checkProgress is
      // already 1 on first build, so the postFrameCallback below fires
      // on the very first frame instead of partway through a play —
      // still exactly once, still the right confirmation click.
    } else {
      _controller.forward();
    }
  }

  // Set once the checkmark finishes drawing, so its confirming tap
  // (distinct from the heavier impact on mount) fires exactly once
  // rather than on every rebuild while checkProgress sits at 1.
  bool _checkTicked = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  double _progressIn(double t, double start, double end) {
    if (t <= start) return 0;
    if (t >= end) return 1;
    return (t - start) / (end - start);
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: widget.size * 1.8,
      height: widget.size * 1.8,
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, _) {
          final t = _controller.value;
          final markProgress = _progressIn(t, 0, _markDrawEnd);
          final checkProgress = _progressIn(t, _holdEnd, _checkDrawEnd);
          final ringProgress = _progressIn(t, _holdEnd, 1);

          if (checkProgress >= 1 && !_checkTicked) {
            _checkTicked = true;
            WidgetsBinding.instance.addPostFrameCallback((_) => HapticFeedback.selectionClick());
          }

          // Mark fades out exactly as the checkmark fades in, so the two
          // cross-fade rather than both being visible at once.
          final markOpacity = t < _holdEnd ? 1.0 : (1 - checkProgress).clamp(0.0, 1.0);
          final checkOpacity = checkProgress.clamp(0.0, 1.0);

          return Stack(
            alignment: Alignment.center,
            children: [
              // The pulse ring — one expansion, not a repeating loop.
              if (ringProgress > 0)
                Opacity(
                  opacity: (1 - ringProgress).clamp(0.0, 1.0),
                  child: Container(
                    width: widget.size * (1 + ringProgress * 0.8),
                    height: widget.size * (1 + ringProgress * 0.8),
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      border: Border.all(color: AppColors.tertiary, width: 2),
                    ),
                  ),
                ),
              // The asymmetric ground — editorial shape, not a circle.
              Container(
                width: widget.size,
                height: widget.size,
                decoration: BoxDecoration(
                  borderRadius: editorialRadius(0, big: widget.size * 0.42, small: widget.size * 0.14),
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [AppColors.tertiary, Color.lerp(AppColors.tertiary, AppColors.primaryDark, 0.35)!],
                  ),
                  boxShadow: [
                    BoxShadow(color: AppColors.tertiary.withValues(alpha: 0.35), blurRadius: 24, spreadRadius: 2),
                  ],
                ),
                child: Stack(
                  alignment: Alignment.center,
                  children: [
                    if (markOpacity > 0)
                      Opacity(
                        opacity: markOpacity,
                        child: CustomPaint(
                          size: Size(widget.size * 0.5, widget.size * 0.5),
                          painter: _TracePainter(
                            path: buildZefanyaMarkPath(Size(widget.size * 0.5, widget.size * 0.5)),
                            progress: markProgress,
                            color: AppColors.textOnDark,
                            strokeWidth: widget.size * 0.05,
                          ),
                        ),
                      ),
                    if (checkOpacity > 0)
                      Opacity(
                        opacity: checkOpacity,
                        child: CustomPaint(
                          size: Size(widget.size * 0.5, widget.size * 0.5),
                          painter: _TracePainter(
                            path: _buildCheckPath(Size(widget.size * 0.5, widget.size * 0.5)),
                            progress: checkProgress,
                            color: AppColors.textOnDark,
                            strokeWidth: widget.size * 0.06,
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

/// A single-subpath checkmark, drawn the same continuous-stroke way as
/// the Zefanya mark, inside a normalized box.
Path _buildCheckPath(Size size) {
  final s = size.shortestSide / 100;
  double x(double v) => v * s + (size.width - 100 * s) / 2;
  double y(double v) => v * s + (size.height - 100 * s) / 2;

  return Path()
    ..moveTo(x(18), y(52))
    ..lineTo(x(42), y(74))
    ..lineTo(x(84), y(26));
}

class _TracePainter extends CustomPainter {
  const _TracePainter({
    required this.path,
    required this.progress,
    required this.color,
    required this.strokeWidth,
  });

  final Path path;
  final double progress;
  final Color color;
  final double strokeWidth;

  @override
  void paint(Canvas canvas, Size size) {
    if (progress <= 0) return;
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;

    for (final metric in path.computeMetrics()) {
      final end = metric.length * progress;
      if (end <= 0) continue;
      canvas.drawPath(metric.extractPath(0, end), paint);
    }
  }

  @override
  bool shouldRepaint(covariant _TracePainter oldDelegate) =>
      oldDelegate.progress != progress || oldDelegate.color != color || oldDelegate.strokeWidth != strokeWidth;
}
