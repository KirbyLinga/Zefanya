// lib/widgets/heart_button.dart
//
// The save-for-later heart. One widget for both places it lives — the
// corner of a grid card ([compact]) and the Product Details app bar — so
// it behaves the same in both: it fills, pops once when you save, gives a
// light haptic, and is announced to a screen reader as a toggle ("Save
// Linen Blend Wrap Dress" / "Remove ... from saved") rather than an
// unlabeled icon.
//
// The visible circle is 30 (compact) or 40 across, but the tap area is
// always 44 — a 30pt target is easy to miss on a card where a miss opens
// the product instead.
//
// Reduced motion: the heart still fills and the haptic still fires; only
// the pop is skipped.

import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../data/mock_data.dart';
import '../state/wishlist_state.dart';
import '../theme/app_theme.dart';
import '../utils/motion.dart';

class HeartButton extends StatefulWidget {
  const HeartButton({super.key, required this.product, this.compact = false});

  final Product product;

  /// Smaller visible circle, for sitting on a product image.
  final bool compact;

  @override
  State<HeartButton> createState() => _HeartButtonState();
}

class _HeartButtonState extends State<HeartButton>
    with TickerProviderStateMixin {
  late final AnimationController _pop = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 220),
  );

  /// A separate, slightly longer controller: the icon pops back quickly while
  /// the sparks are still travelling outward, so the two read as one gesture
  /// rather than a delay followed by a second effect.
  late final AnimationController _burst = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 460),
  );

  late final Animation<double> _scale = TweenSequence<double>([
    TweenSequenceItem(
      tween: Tween(
        begin: 1.0,
        end: 1.32,
      ).chain(CurveTween(curve: Curves.easeOut)),
      weight: 35,
    ),
    TweenSequenceItem(
      tween: Tween(
        begin: 1.32,
        end: 1.0,
      ).chain(CurveTween(curve: Curves.elasticOut)),
      weight: 65,
    ),
  ]).animate(_pop);

  @override
  void dispose() {
    _pop.dispose();
    _burst.dispose();
    super.dispose();
  }

  void _toggle() {
    final nowSaved = context.read<WishlistState>().toggle(widget.product.id);
    HapticFeedback.lightImpact();
    if (!nowSaved || reduceMotion(context)) return;
    _pop.forward(from: 0);
    _burst.forward(from: 0);
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final saved = context.select<WishlistState, bool>(
      (w) => w.isSaved(widget.product.id),
    );
    final diameter = widget.compact ? 30.0 : 40.0;

    return Semantics(
      button: true,
      toggled: saved,
      label: saved
          ? 'Remove ${widget.product.name} from saved'
          : 'Save ${widget.product.name}',
      onTap: _toggle,
      excludeSemantics: true,
      child: Tooltip(
        message: saved ? 'Remove from saved' : 'Save item',
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            customBorder: const CircleBorder(),
            onTap: _toggle,
            child: SizedBox(
              width: 44,
              height: 44,
              child: Stack(
                alignment: Alignment.center,
                clipBehavior: Clip.none,
                children: [
                  // Sparks are painted outside the visible circle, so they
                  // need the whole 44pt tap target rather than the 30/40pt
                  // fill. The key keeps the effect testable without exposing
                  // the private painter.
                  Positioned.fill(
                    child: IgnorePointer(
                      child: AnimatedBuilder(
                        animation: _burst,
                        builder: (context, _) {
                          if (_burst.value <= 0 || _burst.value >= 1) {
                            return const SizedBox.shrink();
                          }
                          return CustomPaint(
                            key: const ValueKey('heart-burst'),
                            painter: _HeartBurstPainter(
                              progress: _burst.value,
                              startRadius: diameter / 2 + 2.5,
                              travel: widget.compact ? 7 : 10,
                              strokeWidth: widget.compact ? 1.8 : 2.2,
                              color: AppColors.primaryDark,
                              accent: AppColors.tertiary,
                            ),
                          );
                        },
                      ),
                    ),
                  ),
                  Container(
                    width: diameter,
                    height: diameter,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: AppColors.surface.withValues(
                        alpha: widget.compact ? 0.92 : 1,
                      ),
                      boxShadow: [
                        BoxShadow(
                          color: AppColors.neutral.withValues(alpha: 0.22),
                          blurRadius: widget.compact ? 5 : 4,
                          offset: const Offset(0, 1),
                        ),
                      ],
                    ),
                    alignment: Alignment.center,
                    child: ScaleTransition(
                      scale: _scale,
                      child: Icon(
                        saved ? Icons.favorite : Icons.favorite_border,
                        size: widget.compact ? 16 : 20,
                        color: saved
                            ? AppColors.primaryDark
                            : AppColors.textPrimary,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Six short radial strokes, alternating rose and sage, that leave the heart
/// as it pops. Deliberately not a confetti shower: this is a save, not a
/// checkout, and the whole burst is gone in under half a second.
class _HeartBurstPainter extends CustomPainter {
  const _HeartBurstPainter({
    required this.progress,
    required this.startRadius,
    required this.travel,
    required this.strokeWidth,
    required this.color,
    required this.accent,
  });

  final double progress;
  final double startRadius;
  final double travel;
  final double strokeWidth;
  final Color color;
  final Color accent;

  @override
  void paint(Canvas canvas, Size size) {
    if (progress <= 0 || progress >= 1) return;

    final center = size.center(Offset.zero);
    final eased = Curves.easeOutCubic.transform(progress);
    final opacity = (1 - progress).clamp(0.0, 1.0);

    for (var i = 0; i < 6; i++) {
      final angle = -math.pi / 2 + i * math.pi / 3 + 0.1;
      final direction = Offset(math.cos(angle), math.sin(angle));
      final start = center + direction * (startRadius + eased * 2);
      final end = center + direction * (startRadius + eased * travel);
      final sparkColor = (i.isEven ? color : accent).withValues(
        alpha: 0.85 * opacity,
      );

      canvas.drawLine(
        start,
        end,
        Paint()
          ..color = sparkColor
          ..strokeWidth = strokeWidth
          ..strokeCap = StrokeCap.round,
      );
      canvas.drawCircle(
        end,
        strokeWidth * 0.7,
        Paint()
          ..color = sparkColor
          ..style = PaintingStyle.fill,
      );
    }
  }

  @override
  bool shouldRepaint(covariant _HeartBurstPainter oldDelegate) =>
      oldDelegate.progress != progress ||
      oldDelegate.startRadius != startRadius ||
      oldDelegate.travel != travel ||
      oldDelegate.strokeWidth != strokeWidth ||
      oldDelegate.color != color ||
      oldDelegate.accent != accent;
}
