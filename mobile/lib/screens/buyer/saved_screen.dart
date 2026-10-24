// lib/screens/buyer/saved_screen.dart
//
// Full-page view of the buyer's saved (hearted) products. Reachable
// from the "See all" link on the Home SavedRail header — the rail itself
// stays on Home so the shortlist is immediately visible; this screen
// exists for when that rail outgrows a horizontal strip.
//
// Empty state is direct rather than decorative: if you came here it's
// because you're looking for saved items, so the message tells you
// exactly how to add some instead of just showing an illustration.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../data/mock_data.dart';
import '../../state/wishlist_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/microcopy.dart';
import '../../utils/money_format.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/heart_button.dart';
import '../../widgets/pressable_scale.dart';
import '../../widgets/staggered_reveal.dart';
import 'product_details_screen.dart';
import 'seller_screen.dart';
import 'widgets/product_card.dart';

class SavedScreen extends StatelessWidget {
  const SavedScreen({super.key});

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);

    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const EditorialHeader(
              eyebrow: 'YOUR SHORTLIST',
              title: 'Saved',
              padding: EdgeInsets.fromLTRB(20, 0, 20, 8),
            ),
            Expanded(
              child: Consumer<WishlistState>(
                builder: (context, wishlist, _) {
                  final products = wishlist.items;
                  final followed = wishlist.followedSellers;
                  if (products.isEmpty && followed.isEmpty) {
                    return _buildEmpty(context, theme);
                  }

                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      if (followed.isNotEmpty)
                        _FollowingStrip(sellers: followed),
                      Expanded(
                        child: products.isEmpty
                            ? _buildEmpty(context, theme)
                            : GridView.builder(
                                padding:
                                    const EdgeInsets.fromLTRB(20, 8, 20, 100),
                                gridDelegate:
                                    const SliverGridDelegateWithFixedCrossAxisCount(
                                  crossAxisCount: 2,
                                  mainAxisSpacing: 16,
                                  crossAxisSpacing: 16,
                                  childAspectRatio: 0.68,
                                ),
                                itemCount: products.length,
                                itemBuilder: (context, i) {
                                  final product = products[i];
                                  return StaggeredReveal(
                                    index: i,
                                    child: _SavedCard(
                                      product: product,
                                      index: i,
                                      onTap: () => Navigator.of(context).push(
                                        AppPageRoute(
                                          builder: (_) => ProductDetailsScreen(
                                            product: product,
                                          ),
                                        ),
                                      ),
                                    ),
                                  );
                                },
                              ),
                      ),
                    ],
                  );
                },
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildEmpty(BuildContext context, ThemeData theme) {
    return EmptyState(
      icon: Icons.favorite_border,
      title: Microcopy.savedEmptyTitle,
      subtitle: Microcopy.savedEmptySubtitle,
      action: OutlinedButton(
        onPressed: () => Navigator.of(context).maybePop(),
        child: const Text('Browse products'),
      ),
    );
  }
}

/// The shops this buyer follows, above the saved products.
///
/// It lives here rather than in a separate "Following" tab because following a
/// store and saving a piece are both shortlists; splitting them would mean the
/// buyer has to remember which pile a shop is in. The count is explicit so
/// "did the follow save?" does not depend on counting tiles.
class _FollowingStrip extends StatelessWidget {
  const _FollowingStrip({required this.sellers});

  final List<String> sellers;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'STORES YOU FOLLOW · ${sellers.length}',
            style: AppType.eyebrow,
          ),
          const SizedBox(height: 8),
          SizedBox(
            height: 58,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: sellers.length,
              separatorBuilder: (_, index) => const SizedBox(width: 8),
              itemBuilder: (context, i) {
                final seller = sellers[i];
                return InkWell(
                  borderRadius: editorialRadius(i, ShapeTier.accent),
                  onTap: () => Navigator.of(context).push(
                    AppPageRoute(
                      builder: (_) => SellerScreen(sellerName: seller),
                    ),
                  ),
                  child: Container(
                    width: 190,
                    padding: const EdgeInsets.symmetric(horizontal: 12),
                    decoration: BoxDecoration(
                      color: AppColors.surfaceMuted,
                      borderRadius: editorialRadius(i, ShapeTier.accent),
                      border: Border.all(color: AppColors.divider),
                    ),
                    child: Row(
                      children: [
                        Icon(
                          Icons.storefront_outlined,
                          size: 20,
                          color: AppColors.primaryDark,
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            seller,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.labelLarge,
                          ),
                        ),
                        const Icon(Icons.chevron_right, size: 18),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}


/// ProductCard wrapper that always shows the heart — on a Saved screen
/// every tile is hearted, so the heart is an affordance to unsave rather
/// than a discovery button, and it should be clearly visible.
class _SavedCard extends StatelessWidget {
  const _SavedCard({
    required this.product,
    required this.index,
    required this.onTap,
  });

  final Product product;
  final int index;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);

    return PressableScale(
      onTap: onTap,
      semanticLabel: '${product.name}, ${formatPeso(product.price)}',
      child: Card(
        clipBehavior: Clip.antiAlias,
        shape: editorialShape(index, ShapeTier.card),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Stack(
                children: [
                  Hero(
                    tag: 'saved-${product.id}',
                    child: ProductImage(product: product),
                  ),
                  // Heart always visible — tapping it unsaves and the
                  // item disappears from the grid (Consumer rebuilds).
                  Positioned(
                    right: 0,
                    top: 0,
                    child: HeartButton(product: product, compact: true),
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    product.name,
                    style: theme.textTheme.titleLarge?.copyWith(
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 4),
                  Text(formatPeso(product.price), style: AppType.priceNumeral(22)),
                  const SizedBox(height: 3),
                  Row(
                    children: [
                      Icon(Icons.star_rounded, size: 13, color: AppColors.sageIcon),
                      const SizedBox(width: 2),
                      Text(
                        product.rating.toStringAsFixed(1),
                        style: theme.textTheme.labelSmall?.copyWith(
                          fontWeight: FontWeight.w700,
                          color: AppColors.textSecondary,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
