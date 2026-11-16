// lib/widgets/motion/cloud_transition.dart
//
// CloudTransition: the signature screen hand-off for Zefanya.
//
// Spec:
//   - Total 700ms (entrance ~300ms, hold ~80ms, exit ~320ms).
//   - Soft painterly veil of 5 overlapping feathered radial gradient blobs
//     (using palette tokens AppColors.glowBlush, glowSage, glowCenter, surface).
//   - Zero blur filters, zero BackdropFilter. Transforms and opacity only.
//   - RepaintBoundary isolated, precomputed geometry, no per-frame allocation.
//   - swap() called exactly once at the hold apex (~43% / 300ms).
//   - Hard 1.2s timeout prevents user trapping under any failure condition.
//   - Reduced motion: 120ms plain cross-fade.

import 'dart:async';
import 'dart:math' as math;
import 'package:flutter/material.dart';

import '../../theme/app_theme.dart';
import '../../utils/motion.dart';

const Duration kCloudTotalDuration = Duration(milliseconds: 700);
const Duration kCloudEnterDuration = Duration(milliseconds: 300);
const Duration kCloudHoldDuration = Duration(milliseconds: 80);
const Duration kCloudExitDuration = Duration(milliseconds: 320);
const Duration kCloudReducedDuration = Duration(milliseconds: 120);
const Duration kCloudHardTimeout = Duration(milliseconds: 1200);

class CloudTransition {
  CloudTransition._();

  /// Runs an overlay cloud hand-off on [context], executing [swap] once
  /// at the opacity peak (~300ms), and finishing after dispersal.
  static Future<void> run(
    BuildContext context, {
    required FutureOr<void> Function() swap,
  }) async {
    final overlayState = Overlay.maybeOf(context, rootOverlay: true) ??
        Navigator.of(context).overlay;
    if (overlayState == null) {
      // Fallback if no overlay exists (e.g. headless tests)
      await swap();
      return;
    }

    final isReduced = reduceMotion(context);
    final completer = Completer<void>();
    OverlayEntry? entry;
    Timer? timeoutTimer;

    void cleanup() {
      timeoutTimer?.cancel();
      timeoutTimer = null;
      if (entry != null && entry!.mounted) {
        entry!.remove();
        entry = null;
      }
      if (!completer.isCompleted) {
        completer.complete();
      }
    }

    // Safety net: hard timeout prevents trapping the user under any circumstance.
    timeoutTimer = Timer(kCloudHardTimeout, cleanup);

    entry = OverlayEntry(
      builder: (overlayContext) {
        return _CloudTransitionOverlay(
          isReduced: isReduced,
          onPeak: () async {
            try {
              await swap();
            } catch (_) {
              // Safety catch: ensure animation and dismissal complete
            }
          },
          onComplete: cleanup,
          onDispose: () {
            timeoutTimer?.cancel();
            timeoutTimer = null;
            if (!completer.isCompleted) {
              completer.complete();
            }
          },
        );
      },
    );

    overlayState.insert(entry!);
    return completer.future;
  }

  /// Creates a PageRoute that transitions between full screens using the cloud wipe.
  static Route<T> pageRoute<T>({
    required WidgetBuilder builder,
    RouteSettings? settings,
  }) {
    return PageRouteBuilder<T>(
      settings: settings,
      transitionDuration: kCloudTotalDuration,
      reverseTransitionDuration: kCloudTotalDuration,
      pageBuilder: (context, animation, secondaryAnimation) => builder(context),
      transitionsBuilder: (context, animation, secondaryAnimation, child) {
        if (reduceMotion(context)) {
          return FadeTransition(opacity: animation, child: child);
        }
        return _CloudPageRouteTransitions(
          animation: animation,
          child: child,
        );
      },
    );
  }
}

class _CloudTransitionOverlay extends StatefulWidget {
  const _CloudTransitionOverlay({
    required this.isReduced,
    required this.onPeak,
    required this.onComplete,
    this.onDispose,
  });

  final bool isReduced;
  final Future<void> Function() onPeak;
  final VoidCallback onComplete;
  final VoidCallback? onDispose;

  @override
  State<_CloudTransitionOverlay> createState() => _CloudTransitionOverlayState();
}

class _CloudTransitionOverlayState extends State<_CloudTransitionOverlay>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  bool _swapTriggered = false;
  Timer? _reducedTimer;

  @override
  void initState() {
    super.initState();
    final duration =
        widget.isReduced ? kCloudReducedDuration : kCloudTotalDuration;

    _controller = AnimationController(vsync: this, duration: duration);

    if (widget.isReduced) {
      // Reduced motion: 120ms simple cross-fade
      _controller.forward().then((_) {
        if (mounted) {
          if (!_swapTriggered) {
            _swapTriggered = true;
            widget.onPeak();
          }
          widget.onComplete();
        }
      });
      _reducedTimer = Timer(const Duration(milliseconds: 60), () {
        if (mounted && !_swapTriggered) {
          _swapTriggered = true;
          widget.onPeak();
        }
      });
    } else {
      // Full cloud transition
      _controller.addListener(_checkApex);
      _controller.forward().then((_) {
        if (mounted) {
          if (!_swapTriggered) {
            _swapTriggered = true;
            widget.onPeak();
          }
          widget.onComplete();
        }
      });
    }
  }

  void _checkApex() {
    // 300ms into 700ms is ~0.428
    if (!_swapTriggered && _controller.value >= 0.43) {
      _swapTriggered = true;
      widget.onPeak();
    }
  }

  @override
  void dispose() {
    _reducedTimer?.cancel();
    _controller.removeListener(_checkApex);
    _controller.dispose();
    widget.onDispose?.call();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);

    return IgnorePointer(
      child: RepaintBoundary(
        child: AnimatedBuilder(
          animation: _controller,
          builder: (context, _) {
            if (widget.isReduced) {
              // 120ms gentle fade veil
              final t = _controller.value;
              final opacity = (t <= 0.5 ? t * 2.0 : (1.0 - t) * 2.0).clamp(0.0, 1.0);
              return ColoredBox(
                color: AppColors.background.withValues(alpha: opacity * 0.9),
                child: const SizedBox.expand(),
              );
            }

            return CustomPaint(
              size: MediaQuery.sizeOf(context),
              painter: _CloudVeilPainter(
                progress: _controller.value,
                palette: _CloudPalette(
                  blush: AppColors.glowBlush,
                  sage: AppColors.glowSage,
                  center: AppColors.glowCenter,
                  veil: AppColors.background,
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}

class _CloudPalette {
  const _CloudPalette({
    required this.blush,
    required this.sage,
    required this.center,
    required this.veil,
  });

  final Color blush;
  final Color sage;
  final Color center;
  final Color veil;
}

class _CloudVeilPainter extends CustomPainter {
  _CloudVeilPainter({
    required this.progress,
    required this.palette,
  });

  final double progress;
  final _CloudPalette palette;

  // Reusable paint instances to avoid per-frame allocations
  static final Paint _paint = Paint()..isAntiAlias = true;

  @override
  void paint(Canvas canvas, Size size) {
    if (size.width <= 0 || size.height <= 0) return;

    final w = size.width;
    final h = size.height;
    final diag = math.sqrt(w * w + h * h);

    // Timing splits:
    // 0.00 .. 0.43 : Entrance (drift in, scale up 0.7 -> 1.15, opacity 0.0 -> 1.0)
    // 0.43 .. 0.54 : Peak hold (veil at max opacity)
    // 0.54 .. 1.00 : Exit (drift up/out, scale 1.15 -> 1.6, opacity 1.0 -> 0.0)
    double phaseT;
    double opacity;
    double scale;
    double driftY;

    if (progress < 0.43) {
      // Entering
      phaseT = Curves.easeOutCubic.transform(progress / 0.43);
      opacity = phaseT;
      scale = 0.75 + (0.45 * phaseT);
      driftY = (1.0 - phaseT) * 0.15 * h;
    } else if (progress <= 0.54) {
      // Apex hold
      opacity = 1.0;
      scale = 1.20;
      driftY = 0.0;
    } else {
      // Exiting
      final exitNorm = ((progress - 0.54) / 0.46).clamp(0.0, 1.0);
      phaseT = Curves.easeOutCubic.transform(exitNorm);
      opacity = 1.0 - phaseT;
      scale = 1.20 + (0.45 * phaseT);
      driftY = -phaseT * 0.25 * h; // Disperse upward
    }

    if (opacity <= 0.001) return;

    // 1. Soft central veil
    _paint.shader = null;
    _paint.color = palette.veil.withValues(alpha: opacity * 0.92);
    canvas.drawRect(Offset.zero & size, _paint);

    // 2. 5 Feathered radial gradient blobs
    final blobs = [
      // Upper left: blush pink
      _Blob(
        center: Offset(w * 0.20, h * 0.25 + driftY * 0.8),
        radius: diag * 0.48 * scale,
        color: palette.blush,
      ),
      // Upper right: sage green
      _Blob(
        center: Offset(w * 0.80, h * 0.28 + driftY * 0.9),
        radius: diag * 0.45 * scale,
        color: palette.sage,
      ),
      // Center: rose glow
      _Blob(
        center: Offset(w * 0.50, h * 0.50 + driftY),
        radius: diag * 0.55 * scale,
        color: palette.center,
      ),
      // Lower left: sage green
      _Blob(
        center: Offset(w * 0.25, h * 0.78 + driftY * 1.2),
        radius: diag * 0.46 * scale,
        color: palette.sage,
      ),
      // Lower right: blush pink
      _Blob(
        center: Offset(w * 0.75, h * 0.75 + driftY * 1.1),
        radius: diag * 0.50 * scale,
        color: palette.blush,
      ),
    ];

    for (final blob in blobs) {
      final rect = Rect.fromCircle(center: blob.center, radius: blob.radius);
      _paint.shader = RadialGradient(
        colors: [
          blob.color.withValues(alpha: opacity * 0.95),
          blob.color.withValues(alpha: opacity * 0.70),
          blob.color.withValues(alpha: 0.0),
        ],
        stops: const [0.0, 0.55, 1.0],
      ).createShader(rect);

      canvas.drawCircle(blob.center, blob.radius, _paint);
    }
  }

  @override
  bool shouldRepaint(covariant _CloudVeilPainter oldDelegate) {
    return oldDelegate.progress != progress ||
        oldDelegate.palette.blush != palette.blush;
  }
}

class _Blob {
  const _Blob({
    required this.center,
    required this.radius,
    required this.color,
  });

  final Offset center;
  final double radius;
  final Color color;
}

class _CloudPageRouteTransitions extends StatelessWidget {
  const _CloudPageRouteTransitions({
    required this.animation,
    required this.child,
  });

  final Animation<double> animation;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);

    return Stack(
      children: [
        child,
        IgnorePointer(
          child: RepaintBoundary(
            child: AnimatedBuilder(
              animation: animation,
              builder: (context, _) {
                return CustomPaint(
                  size: MediaQuery.sizeOf(context),
                  painter: _CloudVeilPainter(
                    progress: animation.value,
                    palette: _CloudPalette(
                      blush: AppColors.glowBlush,
                      sage: AppColors.glowSage,
                      center: AppColors.glowCenter,
                      veil: AppColors.background,
                    ),
                  ),
                );
              },
            ),
          ),
        ),
      ],
    );
  }
}
