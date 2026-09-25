// lib/widgets/free_shipping_meter.dart
//
// "You're ₱240 away from free shipping" with a bar that fills as the cart
// grows. It makes the free-shipping promo something the buyer can watch
// happen (and nudges one more item into the basket), instead of a banner
// they have to trust. Driven by config/pricing.dart, so the threshold here
// is the same one checkout charges by.
//
// Reduced motion: the bar sits at its value without animating.

import 'package:flutter/material.dart';

import '../config/pricing.dart';
import '../theme/app_theme.dart';
import '../utils/motion.dart';
import 'editorial_shapes.dart';

class FreeShippingMeter extends StatelessWidget {
  const FreeShippingMeter({super.key, required this.subtotal});

  /// Selected-items subtotal, before any voucher.
  final double subtotal;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final threshold = kFreeShippingThreshold;
    final unlocked = subtotal >= threshold;
    final remaining = threshold - subtotal;
    final progress = (subtotal / threshold).clamp(0.0, 1.0);

    final String message;
    if (unlocked) {
      message = 'Free shipping unlocked';
    } else if (subtotal <= 0) {
      message = 'Free shipping on orders over \u20b1${threshold.toStringAsFixed(0)}';
    } else {
      message = 'Add \u20b1${remaining.toStringAsFixed(0)} more for free shipping';
    }

    return Semantics(
      label: message,
      excludeSemantics: true,
      child: Container(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
        decoration: BoxDecoration(
          color: AppColors.tertiary.withValues(alpha: 0.22),
          borderRadius: editorialRadius(0, big: 24, small: 8),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  unlocked ? Icons.check_circle_outline : Icons.local_shipping_outlined,
                  size: 18,
                  color: unlocked ? AppColors.successText : AppColors.primaryDark,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    message,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                      color: unlocked ? AppColors.successText : AppColors.textPrimary,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            TweenAnimationBuilder<double>(
              tween: Tween<double>(end: progress),
              duration: reduceMotion(context) ? Duration.zero : const Duration(milliseconds: 420),
              curve: Curves.easeOutCubic,
              builder: (context, value, _) => ClipRRect(
                borderRadius: BorderRadius.circular(999),
                child: LinearProgressIndicator(
                  value: value,
                  minHeight: 6,
                  backgroundColor: AppColors.divider,
                  valueColor: AlwaysStoppedAnimation<Color>(
                    unlocked ? AppColors.successText : AppColors.primaryDark,
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
