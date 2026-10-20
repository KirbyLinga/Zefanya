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
import '../../../state/order_state.dart';
import '../../../state/recently_viewed_state.dart';
import '../../../theme/app_theme.dart';
import '../../../utils/money_format.dart';
import '../../../widgets/editorial_shapes.dart';
import '../../../widgets/heart_button.dart';
import '../../../widgets/pressable_scale.dart';
import 'product_card.dart' show ProductImage;

/// Viewed items minus anything already ordered.
///
/// The rail's promise is "pick up where you left off" — so if you finished
/// picking it up, it should stop suggesting it. Showing someone the thing
/// they just bought is the fastest way to make a "we remember you" rail read
/// as a rail that isn't paying attention.
///
/// A plain function rather than inline layout code so the rule is testable
/// without a widget tree; the widget around it is only layout.
///
/// Note the deliberate contrast with Profile's "recently looked at" strip,
/// which does NOT apply this filter: that one is a personal *record*, and a
/// record that quietly edits out what you actually bought would be lying.
/// This is a merchandising surface; that one is history.
List<Product> unpurchased(List<Product> viewed, Set<String> purchased) =>
    [for (final p in viewed) if (!purchased.contains(p.id)) p];

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
    AppColors.watch(context);
    final theme = Theme.of(context);
    final purchased = context.watch<OrderState>().purchasedProductIds;
    final products = unpurchased(
      context.watch<RecentlyViewedState>().excluding(excludeProductId),
      purchased,
    );

    // No history yet (first launch, or everything viewed so far is either the
    // product excluded above or something already bought) — the section
    // simply doesn't render, rather than showing a placeholder or empty-state
    // message. A "welcome back" rail with nothing real to show would be the
    // same kind of fabrication as a fake viewer counter.
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
    this.inCart = false,
  });

  final Product product;
  final int index;
  final VoidCallback onTap;

  /// When true, overlays a compact HeartButton on the image corner —
  /// used by SavedRail so the buyer can unsave directly from the tile
  /// without opening the product.
  final bool showHeart;

  /// When true, tags the tile as already in the cart. Used by SavedRail:
  /// the shortlist is where you decide what to buy, so it's the one place
  /// where "you already have one of these" changes what you do next.
  final bool inCart;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);

    // "On sale", not "price drop": a drop is relative to when you saved it,
    // and the wishlist stores ids, not the price at save time. compareAtPrice
    // tells us it's discounted *now*, which is what this tag says.
    final onSale = product.compareAtPrice != null && product.compareAtPrice! > product.price;

    return PressableScale(
      onTap: onTap,
      semanticLabel: [
        '${product.name}, ${formatPeso(product.price)}',
        if (onSale) 'on sale',
        if (inCart) 'already in your cart',
      ].join(', '),
      child: SizedBox(
        width: 124,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ClipRRect(
              borderRadius: editorialRadius(index, ShapeTier.card),
              child: Stack(
                children: [
                  SizedBox(
                    width: 124,
                    height: 124,
                    child: ProductImage(product: product, iconSize: 26),
                  ),
                  if (onSale)
                    Positioned(
                      left: 6,
                      top: 6,
                      child: _RailTag(label: 'ON SALE'),
                    ),
                  if (inCart)
                    Positioned(
                      left: 6,
                      bottom: 6,
                      child: _RailTag(label: 'IN CART'),
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
            Text(formatPeso(product.price), style: AppType.priceNumeral(16)),
          ],
        ),
      ),
    );
  }
}

/// A tiny label on a rail tile — "ON SALE", "IN CART".
///
/// Same tag treatment as the product grid's badges (rose fill, light ink,
/// pill), so a label means the same kind of thing everywhere in the app.
/// One fill for both labels on purpose: the words distinguish them, and a
/// second tag colour is a second thing to keep contrast-correct in two
/// modes. (Accent was the obvious candidate and fails as a tag ground —
/// light ink on it is 2.7:1 in dark mode.)
class _RailTag extends StatelessWidget {
  const _RailTag({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
      decoration: BoxDecoration(
        color: AppColors.roseGround,
        borderRadius: radiusOf(ShapeTier.pill),
      ),
      child: Text(
        label,
        style: AppType.eyebrow.copyWith(
          fontSize: 8,
          letterSpacing: 1.1,
          color: AppColors.textOnDark,
        ),
      ),
    );
  }
}
