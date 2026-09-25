// lib/screens/buyer/category_products_screen.dart
//
// Generic "products in one category" grid — pushed from
// categories_screen.dart. Kept separate from the Home tab's filtering
// (rather than reusing Home) since this is a pushed detail screen, not
// a persistent IndexedStack tab, so its Hero tags get their own
// 'category-' prefix (see widgets/product_card.dart for why that matters).

import 'package:flutter/material.dart';
import '../../data/mock_data.dart';
import '../../theme/app_theme.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/staggered_reveal.dart';
import 'product_details_screen.dart';
import 'widgets/product_card.dart';

class CategoryProductsScreen extends StatelessWidget {
  const CategoryProductsScreen({super.key, required this.category});

  final String category;

  // Matches the tints in product_card.dart so the header color is
  // consistent with the product image tints on this screen.
  static Color _categoryTint(String category) => switch (category) {
        'Fashion' => AppColors.secondary,
        'Beauty' => AppColors.tertiary.withValues(alpha: 0.5),
        _ => AppColors.primary.withValues(alpha: 0.45),
      };

  @override
  Widget build(BuildContext context) {
    final products = mockProducts.where((p) => p.category == category).toList();
    final tint = _categoryTint(category);

    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: Column(
        children: [
          // Tinted band: entering Fashion vs Home & Living feels different.
          // The category color carries into the header rather than only
          // appearing on the product images below.
          Container(
            color: tint.withValues(alpha: 0.38),
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('SHOP THE CATEGORY', style: AppType.eyebrow),
                      const SizedBox(height: 8),
                      Text(
                        category,
                        style: AppType.sectionDisplay.copyWith(fontSize: 28),
                      ),
                    ],
                  ),
                ),
                // Product count as a display numeral — same reason
                // Search shows one: it's a real number about what
                // you're about to browse.
                if (products.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 4),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text(
                          '${products.length}',
                          style: AppType.priceNumeral(
                            38,
                            color: AppColors.primaryDark.withValues(alpha: 0.6),
                          ),
                        ),
                        Text('items', style: AppType.eyebrow),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          Expanded(
            child: products.isEmpty
                ? EmptyState(
                    icon: Icons.inventory_2_outlined,
                    title: 'No products in $category yet',
                  )
                : GridView.builder(
                    padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
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
                        child: ProductCard(
                          product: product,
                          index: i,
                          heroTag: 'category-${product.id}',
                          onTap: () => Navigator.of(context).push(
                            AppPageRoute(
                              builder: (_) => ProductDetailsScreen(
                                product: product,
                                heroTag: 'category-${product.id}',
                              ),
                            ),
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
