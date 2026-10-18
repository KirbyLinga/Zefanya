// lib/screens/landing_preview.dart
//
// Swipeable preview pages for the landing screen (motion pass, Task 1).
//
// Page 0 is the brand arrival (owned by landing_screen.dart); these are
// pages 1..3 — real miniature renders of existing UI, not
// illustrations, showing only features that exist:
//
//   Shop    — two real ProductCard-style tiles (mock catalogue data,
//             the same tiles the grids render).
//   Track   — the delivery progress timeline (3 static steps).
//   Save    — a courier earnings bar row + a voucher ticket stub.
//
// No seller-mode preview: none exists. All data is static sample data,
// never live state.
//
// Motion: the parent drives parallax from the PageController offset.
// This file only renders pages; it owns no clock. Reduced motion:
// pages still swipe, no parallax, no auto-advance (parent's job).

import 'package:flutter/material.dart';

import '../data/mock_data.dart';
import '../theme/app_theme.dart';
import '../widgets/editorial_shapes.dart';
import 'buyer/widgets/product_card.dart' show productTint;

/// Number of preview pages after the brand page.
const int landingPreviewCount = 3;

/// Static sample products for the Shop preview — the first two mock
/// products, the same tiles the grids render, at a fixed small size.
List<Product> get landingPreviewProducts => mockProducts.take(2).toList();

/// One preview page: eyebrow + title + miniature render slot.
///
/// Side gutters match the shared 20dp content gutter (the old 28px
/// pushed the artwork off the app's grid). Title gets breathing
/// room: 12px under the eyebrow, 24px above the artwork. (The dots
/// live in the rail below the page box, with their own 10px gap —
/// no strip needed in here.)
class LandingPreviewPage extends StatelessWidget {
  const LandingPreviewPage({
    super.key,
    required this.eyebrow,
    required this.title,
    required this.child,
  });

  final String eyebrow;
  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 0),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(eyebrow, style: AppType.eyebrow),
          const SizedBox(height: 12),
          Text(
            title,
            style: AppType.sectionDisplay.copyWith(fontSize: 30),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 24),
          RepaintBoundary(child: child),
        ],
      ),
    );
  }
}

/// Shop preview: two miniature product tiles in the grid's own shape.
///
/// Tinted tiles with a category icon (not the generic bag): the tint
/// reuses the grid's own [productTint] mapping so the miniatures read
/// as the same catalogue, and the icon names the category the way a
/// photo would. Static sample data — no network, either mode.
class ShopPreview extends StatelessWidget {
  const ShopPreview({super.key});

  /// Category icon for the tile art — mirrors the grid's tint buckets
  /// (Fashion / Beauty / Home & Living, see productTint) with the
  /// closest Material glyph. Default covers anything a seller adds.
  static IconData iconFor(String category) => switch (category) {
        'Fashion' => Icons.checkroom_outlined,
        'Beauty' => Icons.spa_outlined,
        'Home & Living' => Icons.chair_outlined,
        _ => Icons.shopping_bag_outlined,
      };

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final products = landingPreviewProducts;
    return Row(
      children: [
        for (var i = 0; i < products.length; i++) ...[
          if (i > 0) const SizedBox(width: 12),
          Expanded(
            child: Container(
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: editorialRadius(i, ShapeTier.card),
              ),
              padding: const EdgeInsets.fromLTRB(10, 10, 10, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    height: 86,
                    decoration: BoxDecoration(
                      color: productTint(products[i].category),
                      borderRadius:
                          editorialRadius(i, ShapeTier.accent),
                    ),
                    alignment: Alignment.center,
                    child: Icon(
                      iconFor(products[i].category),
                      size: 28,
                      color: AppColors.tintInk,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    products[i].name,
                    style: theme.textTheme.labelSmall?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 2),
                  Text(
                    '\u20b1${products[i].price.toStringAsFixed(0)}',
                    style: AppType.priceNumeral(16),
                  ),
                ],
              ),
            ),
          ),
        ],
      ],
    );
  }
}

/// Track preview: three timeline steps, first done, second active —
/// the same dot-and-line device the tracker renders.
class TrackPreview extends StatelessWidget {
  const TrackPreview({super.key});

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(1, ShapeTier.card),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: const [
          _TrackStep(label: 'Packed', done: true, active: false, isLast: false),
          _TrackStep(label: 'On its way', done: false, active: true, isLast: false),
          _TrackStep(label: 'Delivered', done: false, active: false, isLast: true),
        ],
      ),
    );
  }
}

class _TrackStep extends StatelessWidget {
  const _TrackStep({
    required this.label,
    required this.done,
    required this.active,
    required this.isLast,
  });

  final String label;
  final bool done;
  final bool active;
  final bool isLast;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final color = done || active ? AppColors.primaryDark : AppColors.divider;
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 28,
            child: Column(
              children: [
                Container(
                  width: 22,
                  height: 22,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: done ? AppColors.primaryDark : Colors.transparent,
                    border: Border.all(color: color, width: active ? 2 : 1.5),
                  ),
                  child: done
                      ? Icon(Icons.check, size: 12, color: AppColors.textOnDark)
                      : null,
                ),
                if (!isLast)
                  Expanded(
                    child: Container(
                      width: 2,
                      color: done ? AppColors.primaryDark : AppColors.divider,
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: 12, top: 3),
              child: Text(
                label,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: active
                      ? AppColors.primaryDark
                      : done
                          ? AppColors.textPrimary
                          : AppColors.textSecondary,
                  fontWeight: active ? FontWeight.w700 : FontWeight.w400,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Save preview: a 3-bar earnings row + a voucher ticket stub — the
/// two shapes the profit screen and the wallet render.
class SavePreview extends StatelessWidget {
  const SavePreview({super.key});

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: AppColors.surfaceMuted,
            borderRadius: editorialRadius(1, ShapeTier.card),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              for (final h in const [28.0, 52.0, 40.0])
                Expanded(
                  child: Container(
                    height: h,
                    margin: const EdgeInsets.symmetric(horizontal: 5),
                    decoration: BoxDecoration(
                      color: AppColors.tertiary.withValues(alpha: 0.65),
                      borderRadius: BorderRadius.circular(4),
                    ),
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Container(
          padding:
              const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          decoration: BoxDecoration(
            color: AppColors.surface,
            borderRadius: editorialRadius(0, ShapeTier.card),
            border: Border.all(color: AppColors.divider),
          ),
          child: Row(
            children: [
              Icon(Icons.confirmation_number_outlined,
                  size: 18, color: AppColors.primaryDark),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  '10% off',
                  style: theme.textTheme.bodySmall?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
              Text(
                'SAVE10',
                style: theme.textTheme.labelSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  letterSpacing: 1.0,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// Page dots: position indicator for the landing PageView.
class LandingDots extends StatelessWidget {
  const LandingDots({super.key, required this.count, required this.index});

  final int count;
  final double index;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (var i = 0; i < count; i++)
          AnimatedContainer(
            duration: const Duration(milliseconds: 200),
            margin: const EdgeInsets.symmetric(horizontal: 3),
            width: (index.round() == i) ? 22 : 7,
            height: 7,
            decoration: BoxDecoration(
              borderRadius: radiusOf(ShapeTier.pill),
              color: (index.round() == i)
                  ? AppColors.primaryDark
                  : AppColors.divider,
            ),
          ),
      ],
    );
  }
}
