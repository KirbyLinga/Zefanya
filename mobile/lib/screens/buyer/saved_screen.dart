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
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/heart_button.dart';
import '../../widgets/pressable_scale.dart';
import '../../widgets/staggered_reveal.dart';
import 'product_details_screen.dart';
import 'widgets/product_card.dart';

class SavedScreen extends StatelessWidget {
  const SavedScreen({super.key});

  @override
  Widget build(BuildContext context) {
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
                  if (products.isEmpty) return _buildEmpty(theme);

                  return GridView.builder(
                    padding: const EdgeInsets.fromLTRB(20, 8, 20, 100),
                    gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
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
                              builder: (_) => ProductDetailsScreen(product: product),
                            ),
                          ),
                        ),
                      );
                    },
                  );
                },
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildEmpty(ThemeData theme) {
    return const EmptyState(
      icon: Icons.favorite_border,
      title: 'Nothing saved yet',
      subtitle: 'Tap the heart on any product to add it here.',
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
    final theme = Theme.of(context);
    final tint = productTint(product.category);

    return PressableScale(
      onTap: onTap,
      semanticLabel: '${product.name}, ₱${product.price.toStringAsFixed(0)}',
      child: Card(
        clipBehavior: Clip.antiAlias,
        shape: editorialShape(index),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Stack(
                children: [
                  Hero(
                    tag: 'saved-${product.id}',
                    child: ProductImage(productId: product.id, tint: tint),
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
                  Text('₱${product.price.toStringAsFixed(0)}', style: AppType.priceNumeral(22)),
                  const SizedBox(height: 3),
                  Row(
                    children: [
                      const Icon(Icons.star_rounded, size: 13, color: AppColors.sageIcon),
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
