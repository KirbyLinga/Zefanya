// lib/widgets/zefanya_mark.dart
//
// The branded loading moment (part of critique item #4).
//
// Every wait in the app used to be a CircularProgressIndicator — the
// single most generic thing a Flutter app can show. This draws the
// Zefanya "Z" monogram as a stroke that traces itself on, holds, then
// retracts, so the wait is the brand instead of Material's default.
//
// It's a path trace, not an asset: no image to load, tints to whatever
// color it's given, and scales to any size. Use ZefanyaLoader anywhere
// a spinner would have gone.
//
// Three widgets here, one painter:
//   ZefanyaMark      — static, fully drawn (app bars, empty states)
//   ZefanyaLoader    — loops forever (waits, spinners)
//   ZefanyaMarkTrace — draws on once, externally driven (the landing
//                      screen's arrival). Looping is right for a
//                      spinner and wrong for an arrival, which is why
//                      this is a separate widget rather than a flag on
//                      the loader.

import 'package:flutter/material.dart';

import '../theme/app_theme.dart';
import '../utils/motion.dart';

/// The monogram path, drawn inside a normalized 100x100 box and scaled
/// to fit whatever size it's painted at.
///
/// Deliberately ONE continuous subpath — top bar, diagonal, bottom bar,
/// drawn without lifting the pen. Each `moveTo` would start a new
/// subpath, and since the trace animation advances every subpath at
/// the same time, a multi-part Z would draw all its strokes at once
/// instead of travelling like handwriting.
Path buildZefanyaMarkPath(Size size) {
  final s = size.shortestSide / 100;
  double x(double v) => v * s + (size.width - 100 * s) / 2;
  double y(double v) => v * s + (size.height - 100 * s) / 2;

  return Path()
    ..moveTo(x(16), y(24))
    ..lineTo(x(84), y(24))
    ..lineTo(x(20), y(76))
    ..lineTo(x(86), y(76));
}

/// Static monogram — for app bars, empty states, splash marks.
class ZefanyaMark extends StatelessWidget {
  const ZefanyaMark({
    super.key,
    this.size = 28,
    this.color = AppColors.primaryDark,
    this.strokeWidth = 5,
  });

  final double size;
  final Color color;
  final double strokeWidth;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: CustomPaint(
        painter: _MarkPainter(progress: 1, color: color, strokeWidth: strokeWidth),
      ),
    );
  }
}

/// Animated monogram — the app's loading state.
class ZefanyaLoader extends StatefulWidget {
  const ZefanyaLoader({
    super.key,
    this.size = 34,
    this.color = AppColors.primaryDark,
    this.strokeWidth = 5,
  });

  final double size;
  final Color color;
  final double strokeWidth;

  @override
  State<ZefanyaLoader> createState() => _ZefanyaLoaderState();
}

class _ZefanyaLoaderState extends State<ZefanyaLoader> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1900),
  );

  bool _started = false;
  bool _reduced = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;

    // Reduced motion: show the completed mark and leave it. A spinner
    // that doesn't spin is a worse signal than a static mark, but it's
    // the honest trade — the alternative is ignoring the setting on the
    // one widget that appears during every wait in the app.
    _reduced = reduceMotion(context);
    if (!_reduced) _controller.repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  /// One cycle: draw on (0.00-0.45), hold (0.45-0.65), retract (0.65-1.0).
  double _progressFor(double t) {
    if (t < 0.45) return Curves.easeInOut.transform(t / 0.45);
    if (t < 0.65) return 1;
    return 1 - Curves.easeInOut.transform((t - 0.65) / 0.35);
  }

  @override
  Widget build(BuildContext context) {
    if (_reduced) {
      return ZefanyaMark(
        size: widget.size,
        color: widget.color,
        strokeWidth: widget.strokeWidth,
      );
    }

    return SizedBox(
      width: widget.size,
      height: widget.size,
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, _) {
          return CustomPaint(
            painter: _MarkPainter(
              progress: _progressFor(_controller.value),
              color: widget.color,
              strokeWidth: widget.strokeWidth,
              // Retraction eats the path from the start, so the stroke
              // travels rather than just shrinking in place.
              fromStart: _controller.value >= 0.65,
            ),
          );
        },
      ),
    );
  }
}

/// Monogram drawn to an externally supplied [progress] (0 = nothing,
/// 1 = fully drawn). No controller of its own — the caller owns the
/// timing, which is what lets the landing screen sequence the trace
/// against the wordmark fade on one shared clock.
///
/// Same painter as the other two, so if the stroke ever changes it
/// changes in all three.
class ZefanyaMarkTrace extends StatelessWidget {
  const ZefanyaMarkTrace({
    super.key,
    required this.progress,
    this.size = 34,
    this.color = AppColors.primaryDark,
    this.strokeWidth = 5,
  });

  final double progress;
  final double size;
  final Color color;
  final double strokeWidth;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: CustomPaint(
        painter: _MarkPainter(
          progress: progress.clamp(0.0, 1.0),
          color: color,
          strokeWidth: strokeWidth,
        ),
      ),
    );
  }
}

class _MarkPainter extends CustomPainter {
  const _MarkPainter({
    required this.progress,
    required this.color,
    required this.strokeWidth,
    this.fromStart = false,
  });

  final double progress;
  final Color color;
  final double strokeWidth;
  final bool fromStart;

  @override
  void paint(Canvas canvas, Size size) {
    if (progress <= 0) return;

    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth * (size.shortestSide / 100) * 3.4
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;

    final full = buildZefanyaMarkPath(size);

    for (final metric in full.computeMetrics()) {
      final length = metric.length;
      final start = fromStart ? length * (1 - progress) : 0.0;
      final end = fromStart ? length : length * progress;
      if (end <= start) continue;
      canvas.drawPath(metric.extractPath(start, end), paint);
    }
  }

  @override
  bool shouldRepaint(covariant _MarkPainter oldDelegate) =>
      oldDelegate.progress != progress ||
      oldDelegate.color != color ||
      oldDelegate.strokeWidth != strokeWidth ||
      oldDelegate.fromStart != fromStart;
}
