// lib/screens/buyer/widgets/recently_viewed_rail.dart
//
// "Does the app remember you?" — before this, no. No recently-viewed,
// no welcome-back moment, nothing that distinguished a first-time visit
// from a fifth one. This is the real version of that: RecentlyViewedState
// actually tracks what got opened (see state/recently_viewed_state.dart),
// so the rail only ever shows genuine history, never fabricated activity.
//
// Deliberately absent from this file: any kind of "12 people viewing
// this" or "3 sold in the last hour" liveness counter. Without a real
// backend behind it, that number can only be made up, and a fabricated
// activity signal is a different kind of feature from this one — this
// rail is real because every entry in it is something this device
// actually did.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../data/mock_data.dart';
import '../../../state/recently_viewed_state.dart';
import '../../../theme/app_theme.dart';
import '../../../widgets/editorial_shapes.dart';
import '../../../widgets/heart_button.dart';
import '../../../widgets/pressable_scale.dart';
import 'product_card.dart' show ProductImage, productTint;

class RecentlyViewedRail extends StatelessWidget {
  const RecentlyViewedRail({
    super.key,
    required this.onTapProduct,
    this.excludeProductId,
  });

  final void Function(Product product) onTapProduct;

  /// Skip this id — used so the rail never repeats the product the
  /// cover story tile right above it is already showing.
  final String? excludeProductId;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final products = context.watch<RecentlyViewedState>().excluding(excludeProductId);

    // No history yet (first launch, or everything viewed so far is the
    // one product excluded above) — the section simply doesn't render,
    // rather than showing a placeholder or empty-state message. A
    // "welcome back" rail with nothing real to show would be the same
    // kind of fabrication as a fake viewer counter.
    if (products.isEmpty) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('WELCOME BACK', style: AppType.eyebrow),
              const SizedBox(height: 8),
              Text('Pick up where you left off', style: theme.textTheme.titleLarge?.copyWith(fontSize: 20)),
            ],
          ),
        ),
        SizedBox(
          height: 178,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 20),
            itemCount: products.length,
            separatorBuilder: (_, _) => const SizedBox(width: 12),
            itemBuilder: (context, i) => ProductRailTile(product: products[i], index: i, onTap: () => onTapProduct(products[i])),
          ),
        ),
      ],
    );
  }
}

/// One product in a horizontal rail (Recently viewed, Saved for later).
class ProductRailTile extends StatelessWidget {
  const ProductRailTile({
    super.key,
    required this.product,
    required this.index,
    required this.onTap,
    this.showHeart = false,
  });

  final Product product;
  final int index;
  final VoidCallback onTap;

  /// When true, overlays a compact HeartButton on the image corner —
  /// used by SavedRail so the buyer can unsave directly from the tile
  /// without opening the product.
  final bool showHeart;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final tint = productTint(product.category);

    return PressableScale(
      onTap: onTap,
      semanticLabel: '${product.name}, ${product.price.toStringAsFixed(0)} pesos',
      child: SizedBox(
        width: 124,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ClipRRect(
              borderRadius: editorialRadius(index, big: 22, small: 8),
              child: Stack(
                children: [
                  SizedBox(
                    width: 124,
                    height: 124,
                    child: ProductImage(productId: product.id, tint: tint, iconSize: 26),
                  ),
                  if (showHeart)
                    Positioned(
                      right: 0,
                      top: 0,
                      child: HeartButton(product: product, compact: true),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 8),
            Text(
              product.name,
              style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w600),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
            const SizedBox(height: 2),
            Text('\u20b1${product.price.toStringAsFixed(0)}', style: AppType.priceNumeral(16)),
          ],
        ),
      ),
    );
  }
}
