// lib/screens/buyer/category_products_screen.dart
//
// Generic "products in one category" grid — pushed from
// categories_screen.dart. Kept separate from the Home tab's filtering
// (rather than reusing Home) since this is a pushed detail screen, not
// a persistent IndexedStack tab, so its Hero tags get their own
// 'category-' prefix (see widgets/product_card.dart for why that matters).

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../state/catalog_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/staggered_reveal.dart';
import '../../utils/product_sort.dart';
import 'product_details_screen.dart';
import 'widgets/product_card.dart';
import 'widgets/sort_chips.dart';

class CategoryProductsScreen extends StatefulWidget {
  const CategoryProductsScreen({super.key, required this.category});

  final String category;

  @override
  State<CategoryProductsScreen> createState() => _CategoryProductsScreenState();
}

class _CategoryProductsScreenState extends State<CategoryProductsScreen> {
  ProductSort _sort = ProductSort.featured;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final products = sortProducts(
      context.watch<CatalogState>().inCategory(widget.category),
      _sort,
    );
    // The saturated version of the category's hue, for the band's rule.
    // (The grid below uses productTint — the same hue *leaned onto* the
    // card ground, which is the right treatment for a photo fill and the
    // wrong one for a 3px line.)
    final hue = productHue(widget.category);

    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: Column(
        children: [
          // Category band: entering Fashion vs Home & Living feels
          // different. The band itself is a surface tier — the same zone
          // treatment Home's "Style Edit" band uses — with the category
          // hue carried as the rule along its bottom edge rather than as
          // the band's fill.
          //
          // Two things forced that split. The old pastel wash
          // (`tint.withValues(alpha: 0.38)`) was a glowing panel on the
          // dark ground: the brightest thing on the screen, above the
          // products it was introducing. And no wash pale enough to be a
          // wash leaves the 10pt eyebrow legible — against a blush-leaning
          // band, textSecondary lands near 1.8:1, well under the 4.5:1
          // small text needs. The hue still announces the category; the
          // copy just isn't sitting on it any more.
          //
          // On paper the band is white and the rule has to darken to be
          // seen at all; on ink the rule is the pale hue itself. See
          // AppColors.ruleOnGround.
          Container(
            decoration: BoxDecoration(
              color: AppColors.surface,
              border: Border(bottom: BorderSide(color: AppColors.ruleOnGround(hue), width: 3)),
            ),
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
                        widget.category,
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
                            color: AppColors.accent.withValues(alpha: 0.8),
                          ),
                        ),
                        Text('items', style: AppType.eyebrow),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          if (products.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: ProductSortChips(
                sort: _sort,
                onChanged: (sort) => setState(() => _sort = sort),
              ),
            ),
          Expanded(
            child: products.isEmpty
                ? EmptyState(
                    icon: Icons.inventory_2_outlined,
                    title: 'No products in ${widget.category} yet',
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
