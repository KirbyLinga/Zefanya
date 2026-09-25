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

class _HeartButtonState extends State<HeartButton> with SingleTickerProviderStateMixin {
  late final AnimationController _pop = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 220),
  );

  late final Animation<double> _scale = TweenSequence<double>([
    TweenSequenceItem(tween: Tween(begin: 1.0, end: 1.32).chain(CurveTween(curve: Curves.easeOut)), weight: 35),
    TweenSequenceItem(tween: Tween(begin: 1.32, end: 1.0).chain(CurveTween(curve: Curves.elasticOut)), weight: 65),
  ]).animate(_pop);

  @override
  void dispose() {
    _pop.dispose();
    super.dispose();
  }

  void _toggle() {
    final nowSaved = context.read<WishlistState>().toggle(widget.product.id);
    HapticFeedback.lightImpact();
    if (nowSaved && !reduceMotion(context)) _pop.forward(from: 0);
  }

  @override
  Widget build(BuildContext context) {
    final saved = context.select<WishlistState, bool>((w) => w.isSaved(widget.product.id));
    final diameter = widget.compact ? 30.0 : 40.0;

    return Semantics(
      button: true,
      toggled: saved,
      label: saved ? 'Remove ${widget.product.name} from saved' : 'Save ${widget.product.name}',
      onTap: _toggle,
      excludeSemantics: true,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: _toggle,
          child: SizedBox(
            width: 44,
            height: 44,
            child: Center(
              child: Container(
                width: diameter,
                height: diameter,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: AppColors.surface.withValues(alpha: widget.compact ? 0.92 : 1),
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
                    color: saved ? AppColors.primaryDark : AppColors.textPrimary,
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
