// lib/screens/buyer/seller_screen.dart
//
// Browse all products from a single seller. Pushed from the seller row
// on Product Details — previously tapping the seller name only opened
// Chat, with no way to see what else they sell.
//
// All data comes from mockProducts filtered by sellerName. When real
// seller profiles exist (avatar, bio, location), slot them in here —
// the structure is already waiting.

import 'package:flutter/material.dart';

import '../../data/mock_data.dart';
import '../../theme/app_theme.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/staggered_reveal.dart';
import '../shared/chat_screen.dart';
import 'product_details_screen.dart';
import 'widgets/product_card.dart';

class SellerScreen extends StatelessWidget {
  const SellerScreen({super.key, required this.sellerName});

  final String sellerName;

  @override
  Widget build(BuildContext context) {
    final products = mockProducts
        .where((p) => p.sellerName == sellerName)
        .toList();

    // Average rating across all of this seller's products.
    final avgRating = products.isEmpty
        ? 0.0
        : products.fold(0.0, (sum, p) => sum + p.rating) / products.length;

    final totalSold = products.fold(0, (sum, p) => sum + p.soldCount);

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: SafeArea(
        child: CustomScrollView(
          slivers: [
            // ---- Seller hero card --------------------------------
            SliverToBoxAdapter(
              child: _SellerHero(
                sellerName: sellerName,
                productCount: products.length,
                avgRating: avgRating,
                totalSold: totalSold,
                onChat: () => Navigator.of(context).push(
                  AppPageRoute(
                    builder: (_) => ChatScreen(
                      contactName: sellerName,
                      contactRole: 'Seller',
                    ),
                  ),
                ),
              ),
            ),

            // ---- Products grid -----------------------------------
            if (products.isEmpty)
              const SliverFillRemaining(
                child: EmptyState(
                  icon: Icons.storefront_outlined,
                  title: 'No products listed yet',
                  subtitle: 'This seller hasn\'t listed anything yet.',
                ),
              )
            else ...[
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 4),
                sliver: SliverToBoxAdapter(
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('THE COLLECTION', style: AppType.eyebrow),
                            const SizedBox(height: 6),
                            Text(
                              '${products.length}',
                              style: AppType.priceNumeral(34),
                            ),
                          ],
                        ),
                      ),
                      Padding(
                        padding: const EdgeInsets.only(bottom: 4),
                        child: Text(
                          products.length == 1 ? 'PIECE' : 'PIECES',
                          style: AppType.eyebrow,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 100),
                sliver: SliverGrid(
                  gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 2,
                    mainAxisSpacing: 16,
                    crossAxisSpacing: 16,
                    childAspectRatio: 0.68,
                  ),
                  delegate: SliverChildBuilderDelegate(
                    (context, i) {
                      final product = products[i];
                      return StaggeredReveal(
                        index: i,
                        child: ProductCard(
                          product: product,
                          index: i,
                          heroTag: 'seller-${product.id}',
                          onTap: () => Navigator.of(context).push(
                            AppPageRoute(
                              builder: (_) => ProductDetailsScreen(
                                product: product,
                                heroTag: 'seller-${product.id}',
                              ),
                            ),
                          ),
                        ),
                      );
                    },
                    childCount: products.length,
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _SellerHero extends StatelessWidget {
  const _SellerHero({
    required this.sellerName,
    required this.productCount,
    required this.avgRating,
    required this.totalSold,
    required this.onChat,
  });

  final String sellerName;
  final int productCount;
  final double avgRating;
  final int totalSold;
  final VoidCallback onChat;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Stack(
      children: [
        // Gradient hero banner
        ClipRRect(
          borderRadius: editorialRadius(0, tier: EditorialTier.hero),
          child: Container(
            margin: const EdgeInsets.fromLTRB(20, 8, 20, 0),
            padding: const EdgeInsets.fromLTRB(20, 20, 20, 22),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  AppColors.primaryDark,
                  Color.lerp(AppColors.primaryDark, Colors.black, 0.2)!,
                ],
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Back button
                IconButton(
                  icon: const Icon(Icons.arrow_back, color: AppColors.textOnDark),
                  onPressed: () => Navigator.of(context).maybePop(),
                  padding: EdgeInsets.zero,
                  constraints: const BoxConstraints(),
                ),
                const SizedBox(height: 12),
                Text(
                  'FROM THE MAKER',
                  style: AppType.eyebrow.copyWith(
                    color: AppColors.secondary,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  sellerName,
                  style: theme.textTheme.titleLarge?.copyWith(
                    color: AppColors.textOnDark,
                    height: 1.15,
                    fontSize: 22,
                  ),
                ),
                const SizedBox(height: 20),
                // Stat strip
                Row(
                  children: [
                    _stat(context, '$productCount', 'PIECES'),
                    const SizedBox(width: 1, height: 40,
                        child: ColoredBox(color: Color(0x30F9FAFB))),
                    _stat(context, avgRating.toStringAsFixed(1), 'AVG RATING'),
                    const SizedBox(width: 1, height: 40,
                        child: ColoredBox(color: Color(0x30F9FAFB))),
                    _stat(context, _formatSold(totalSold), 'TOTAL SOLD'),
                  ],
                ),
                const SizedBox(height: 18),
                OutlinedButton.icon(
                  onPressed: onChat,
                  icon: const Icon(Icons.chat_bubble_outline, size: 16,
                      color: AppColors.textOnDark),
                  label: const Text('Chat with Seller',
                      style: TextStyle(color: AppColors.textOnDark)),
                  style: OutlinedButton.styleFrom(
                    side: BorderSide(
                      color: AppColors.textOnDark.withValues(alpha: 0.45),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _stat(BuildContext context, String value, String label) {
    return Expanded(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              value,
              style: AppType.priceNumeral(22, color: AppColors.textOnDark),
            ),
            const SizedBox(height: 2),
            Text(
              label,
              style: AppType.eyebrow.copyWith(
                color: AppColors.textOnDark.withValues(alpha: 0.65),
                letterSpacing: 1.8,
              ),
            ),
          ],
        ),
      ),
    );
  }

  String _formatSold(int count) {
    if (count >= 1000) return '${(count / 1000).toStringAsFixed(1)}k';
    return '$count';
  }
}
