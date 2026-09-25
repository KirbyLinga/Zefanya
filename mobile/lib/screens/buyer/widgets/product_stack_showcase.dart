// lib/screens/buyer/widgets/product_stack_showcase.dart
//
// Two products presented as a fanned, overlapping pair — one smaller
// and set back, one larger and set forward, both slightly rotated —
// instead of the grid's straight aligned rows. Portable version of the
// "stacked cards" composition: pure layout/transform, no dependency on
// real photography or the video/variant-swatch stuff that came with it.

import 'package:flutter/material.dart';
import '../../../data/mock_data.dart';
import '../../../theme/app_theme.dart';
import '../product_details_screen.dart';
import '../../../widgets/app_page_route.dart';
import 'product_card.dart';

class ProductStackShowcase extends StatelessWidget {
  const ProductStackShowcase({
    super.key,
    required this.back,
    required this.front,
    required this.heroTagPrefix,
  });

  final Product back;
  final Product front;
  final String heroTagPrefix;

  @override
  Widget build(BuildContext context) {
    // Scale both cards proportionally to the available width so the
    // composition holds on narrow phones and wider constrained layouts.
    return LayoutBuilder(
      builder: (context, constraints) {
        final w = constraints.maxWidth;
        // Back card: ~42% of width. Front card: ~50%.
        // At 380px (typical phone): back ≈ 160, front ≈ 190 — close to
        // the old hardcoded values. Scales down cleanly on narrower screens.
        final backWidth    = (w * 0.42).clamp(120.0, 185.0);
        final frontWidth   = (w * 0.50).clamp(145.0, 210.0);
        final backImgH     = backWidth;
        final frontImgH    = frontWidth * 1.07;
        final stackHeight  = frontImgH + 90;

        return SizedBox(
          height: stackHeight,
          child: Stack(
            clipBehavior: Clip.none,
            children: [
              Positioned(
                left: 0,
                top: 26,
                child: Transform.rotate(
                  angle: -0.05,
                  child: _StackCard(
                    product: back,
                    width: backWidth,
                    imageHeight: backImgH,
                    onTap: () => Navigator.of(context).push(
                      AppPageRoute(builder: (_) => ProductDetailsScreen(product: back, heroTag: '$heroTagPrefix-${back.id}')),
                    ),
                    heroTag: '$heroTagPrefix-${back.id}',
                  ),
                ),
              ),
              Positioned(
                right: 4,
                top: 0,
                child: Transform.rotate(
                  angle: 0.035,
                  child: _StackCard(
                    product: front,
                    width: frontWidth,
                    imageHeight: frontImgH,
                    elevated: true,
                    onTap: () => Navigator.of(context).push(
                      AppPageRoute(builder: (_) => ProductDetailsScreen(product: front, heroTag: '$heroTagPrefix-${front.id}')),
                    ),
                    heroTag: '$heroTagPrefix-${front.id}',
                  ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

class _StackCard extends StatelessWidget {
  const _StackCard({
    required this.product,
    required this.width,
    required this.imageHeight,
    required this.onTap,
    required this.heroTag,
    this.elevated = false,
  });

  final Product product;
  final double width;
  final double imageHeight;
  final VoidCallback onTap;
  final String heroTag;
  final bool elevated;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final tint = productTint(product.category);

    return SizedBox(
      width: width,
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(ext.cardRadius),
        elevation: elevated ? 10 : 4,
        shadowColor: Colors.black.withValues(alpha: elevated ? 0.28 : 0.16),
        child: InkWell(
          borderRadius: BorderRadius.circular(ext.cardRadius),
          onTap: onTap,
          child: Container(
            decoration: BoxDecoration(
              color: AppColors.surface,
              borderRadius: BorderRadius.circular(ext.cardRadius),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                ClipRRect(
                  borderRadius: BorderRadius.vertical(top: Radius.circular(ext.cardRadius)),
                  child: SizedBox(
                    height: imageHeight,
                    width: double.infinity,
                    child: Stack(
                      children: [
                        Hero(tag: heroTag, child: ProductImage(productId: product.id, tint: tint)),
                        Positioned(
                          left: 8,
                          top: 8,
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: AppColors.surface.withValues(alpha: 0.9),
                              borderRadius: BorderRadius.circular(999),
                            ),
                            child: Text(
                              product.category.toUpperCase(),
                              style: theme.textTheme.labelSmall?.copyWith(
                                fontWeight: FontWeight.w700,
                                letterSpacing: 0.4,
                                color: AppColors.primaryDark,
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(10, 8, 10, 10),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        product.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleLarge?.copyWith(fontSize: 13, fontWeight: FontWeight.w600),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        '₱${product.price.toStringAsFixed(0)}',
                        style: theme.textTheme.labelMedium?.copyWith(fontWeight: FontWeight.w700, color: AppColors.primaryDark),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
