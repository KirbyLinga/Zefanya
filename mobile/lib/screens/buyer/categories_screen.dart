// lib/screens/buyer/categories_screen.dart
//
// Categories tab: a browsable index of the catalogue's categories (from
// `CatalogState`, so a live build lists the server's own), each showing how many
// products it holds.
// Tapping one pushes CategoryProductsScreen filtered to it.
//
// No page title here — the bottom nav already says "Categories".
// Instead the category with the most products gets a wide feature
// panel up top (an arch-clipped icon next to its name, the same
// silhouette Home's cover story uses for its lead product) and the
// rest sit below it as a smaller index — an asymmetric hierarchy
// instead of one uniform grid of equal tiles.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../theme/app_theme.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/pressable_scale.dart';
import 'category_products_screen.dart';
import 'widgets/product_card.dart';
import '../../state/catalog_state.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/cart_button.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/empty_state.dart';

/// The icon each category label renders behind its arch.
///
/// Two sets, deliberately. The fourteen are the **server's** curated labels, so a
/// live build has an icon for every row it can be handed; the three after them are
/// the mock catalogue's own names, kept so mock mode looks exactly as it always
/// has. Keyed on the label because the label *is* the contract — it is what the app
/// sends back as the catalogue filter — rather than on an id the app would have to
/// learn for the sake of one icon.
///
/// A label with no entry renders the neutral fallback (`category_outlined`) rather
/// than nothing, so a category added server-side is a plain tile rather than a
/// broken one. Coverage is asserted in `test/catalog_wiring_test.dart`, because the
/// fourteen arrived with the live catalogue and most of them had no icon at all.
const Map<String, IconData> categoryIcons = {
  'Books and Media': Icons.menu_book_outlined,
  'Health and Beauty': Icons.spa_outlined,
  'Sports and Outdoors': Icons.hiking_outlined,
  'Home and Garden': Icons.local_florist_outlined,
  'Kids and Baby': Icons.child_friendly_outlined,
  "Men's Apparel": Icons.checkroom_outlined,
  "Women's Apparel": Icons.dry_cleaning_outlined,
  'Electronics and Gadgets': Icons.smartphone_outlined,
  'Pet Supplies': Icons.pets_outlined,
  'Food and Gourmet': Icons.restaurant_outlined,
  'Automotive & Motorcycle': Icons.two_wheeler_outlined,
  'Furniture and Office Equipment': Icons.chair_outlined,
  'Jewelry and Watches': Icons.diamond_outlined,
  'Office and School Supplies': Icons.edit_outlined,
  // The mock catalogue's own three, so mock mode is unchanged.
  'Fashion': Icons.checkroom_outlined,
  'Home & Living': Icons.chair_outlined,
  'Beauty': Icons.spa_outlined,
};

/// Reuses the web catalogue's licensed category artwork. Server-added labels
/// without a matching image keep a neutral category icon.
class CategoryArtwork extends StatelessWidget {
  const CategoryArtwork({
    super.key,
    required this.category,
    this.iconSize = 22,
    this.alignment = Alignment.center,
    this.tone,
  });

  final String category;
  final double iconSize;
  final Alignment alignment;
  final Color? tone;

  static const _assets = {
    'Books and Media': 'books-and-media',
    'Health and Beauty': 'health-and-beauty',
    'Sports and Outdoors': 'sports-and-outdoors',
    'Home and Garden': 'home-and-garden',
    'Kids and Baby': 'kids-and-baby',
    "Men's Apparel": 'mens-apparel',
    "Women's Apparel": 'womens-apparel',
    'Electronics and Gadgets': 'electronics-and-gadgets',
    'Pet Supplies': 'pet-supplies',
    'Food and Gourmet': 'food-and-gourmet',
    'Automotive & Motorcycle': 'automotive-motorcycle',
    'Furniture and Office Equipment': 'furniture-and-office-equipment',
    'Jewelry and Watches': 'jewelry-and-watches',
    'Office and School Supplies': 'office-and-school-supplies',
    'Fashion': 'mens-apparel',
    'Home & Living': 'home-and-garden',
    'Beauty': 'health-and-beauty',
  };

  @override
  Widget build(BuildContext context) {
    final asset = _assets[category];
    final fallback = ColoredBox(
      color: productTint(category),
      child: Center(
        child: Icon(
          categoryIcons[category] ?? Icons.category_outlined,
          size: iconSize,
          color: AppColors.tintInk,
        ),
      ),
    );
    return ExcludeSemantics(
      child: asset == null
          ? fallback
          : Image.asset(
              'assets/categories/$asset-320.webp',
              fit: BoxFit.cover,
              alignment: alignment,
              color: tone,
              colorBlendMode: tone == null ? null : BlendMode.multiply,
              excludeFromSemantics: true,
              errorBuilder: (_, _, _) => fallback,
            ),
    );
  }
}

class CategoriesScreen extends StatelessWidget {
  const CategoriesScreen({super.key});

  List<(String, int)> _rankedCategories(CatalogState catalog) {
    final categories = catalog.categories.where((c) => c != 'All').toList();
    // Counted from the catalogue the app actually holds, so a live build ranks the
    // server's fourteen categories by how many of its products each one has —
    // rather than ranking a mock list and rendering labels that need not match it.
    final ranked = [
      for (final c in categories) (c, catalog.inCategory(c).length),
    ];
    ranked.sort((a, b) => b.$2.compareTo(a.$2));
    return ranked;
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final catalog = context.watch<CatalogState>();
    final ranked = _rankedCategories(catalog);
    if (ranked.isEmpty) {
      return SafeArea(
        child: catalog.isLoading
            ? const Center(child: CircularProgressIndicator())
            : SingleChildScrollView(
                child: EmptyState(
                  icon: Icons.category_outlined,
                  title: catalog.loadFailure == null
                      ? 'No categories yet'
                      : 'Could not load categories',
                  subtitle: 'Refresh to check for available categories.',
                  action: TextButton(
                    onPressed: () => catalog.refresh().ignore(),
                    child: const Text('Refresh categories'),
                  ),
                ),
              ),
      );
    }
    final feature = ranked.first;
    final rest = ranked.sublist(1);

    return Scaffold(
      backgroundColor: Colors
          .transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      body: SafeArea(
        child: RefreshIndicator(
          color: AppColors.primaryDark,
          onRefresh: () => context.read<CatalogState>().refresh(),
          child: CustomScrollView(
            slivers: [
              SliverToBoxAdapter(
                child: EditorialHeader(
                  eyebrow: 'CATALOGUE',
                  title: 'Categories',
                  trailing: const CartButton(),
                  padding: const EdgeInsets.fromLTRB(20, 16, 12, 12),
                ),
              ),
              SliverPadding(
                // 4px was close enough for the title's line box but not for
                // Playfair's descenders: the "g" in "Categories" painted into
                // the feature tile's rounded shoulder. The header's bottom
                // padding and this top gap now reserve real air between the
                // display line and the first content surface.
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 0),
                sliver: SliverToBoxAdapter(
                  child: _FeatureCategoryTile(
                    category: feature.$1,
                    count: feature.$2,
                    onTap: () => Navigator.of(context).push(
                      AppPageRoute(
                        builder: (_) =>
                            CategoryProductsScreen(category: feature.$1),
                      ),
                    ),
                  ),
                ),
              ),
              if (rest.isNotEmpty) ...[
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(20, 18, 20, 0),
                    child: Divider(height: 1, color: AppColors.divider),
                  ),
                ),
                SliverPadding(
                  padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
                  sliver: SliverLayoutBuilder(
                    builder: (context, sliverConstraints) {
                      // Tile width at 2-column grid with 16px gap and 40px padding.

                      // Arch icon (52px) + the two text lines + 32px padding, where the
                      // label wraps to *two* lines. A category the server owns can be
                      // "Furniture and Office Equipment", which no one-line tile can
                      // hold: the mock's three short names fit on one line and hid the
                      // whole problem. Measured from the theme's own metrics rather than
                      // a second hardcoded number, so a text-scale change moves this too.
                      final theme = Theme.of(context);
                      final titleStyle = theme.textTheme.titleSmall;
                      final bodyStyle = theme.textTheme.bodySmall;
                      final scaler = MediaQuery.textScalerOf(context);
                      final titleLine =
                          scaler.scale(titleStyle?.fontSize ?? 14) *
                          (titleStyle?.height ?? 1.4);
                      final bodyLine =
                          scaler.scale(bodyStyle?.fontSize ?? 12) *
                          (bodyStyle?.height ?? 1.4);
                      final contentH = 56 + (titleLine * 2) + bodyLine + 40;
                      return SliverGrid(
                        gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                          crossAxisCount: 2,
                          mainAxisSpacing: 16,
                          crossAxisSpacing: 16,
                          // The floor sits below the common case deliberately: a
                          // two-line label needs a taller tile, and clamping it back to a
                          // squarer ratio is exactly what would overflow again.
                          mainAxisExtent: contentH,
                        ),
                        delegate: SliverChildBuilderDelegate((context, i) {
                          final (category, count) = rest[i];
                          return _CategoryTile(
                            category: category,
                            count: count,
                            index: i,
                            onTap: () => Navigator.of(context).push(
                              AppPageRoute(
                                builder: (_) =>
                                    CategoryProductsScreen(category: category),
                              ),
                            ),
                          );
                        }, childCount: rest.length),
                      );
                    },
                  ),
                ),
              ] else
                const SliverToBoxAdapter(child: SizedBox(height: 24)),
            ],
          ),
        ),
      ),
    );
  }
}

/// The one big moment on this screen — the busiest category rendered
/// as a wide panel, its icon behind an arch clip (the same "shop
/// window" silhouette Home reserves for its lead product) so this
/// reads as a real hierarchy, not a grid with the first cell stretched.
class _FeatureCategoryTile extends StatelessWidget {
  const _FeatureCategoryTile({
    required this.category,
    required this.count,
    required this.onTap,
  });

  final String category;
  final int count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final largeText = MediaQuery.textScalerOf(context).scale(14) >= 22;

    return PressableScale(
      onTap: onTap,
      semanticLabel: '$category, $count item${count == 1 ? '' : 's'}. Browse.',
      child: ClipRRect(
        borderRadius: editorialRadius(0, ShapeTier.card),
        child: Container(
          height: largeText ? 216 : 138,
          color: AppColors.surfaceMuted,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(18, 16, 8, 16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(
                        category,
                        style: AppType.sectionDisplay.copyWith(fontSize: 24),
                        // Bounded for the same reason as the tile's: 28px of
                        // "Furniture and Office Equipment" is four lines inside a
                        // 168px panel, and the panel does not grow.
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 8),
                      Text(
                        '$count item${count == 1 ? '' : 's'}',
                        style: theme.textTheme.bodyMedium,
                      ),
                      const SizedBox(height: 10),
                      Row(
                        children: [
                          Text(
                            'Browse',
                            style: theme.textTheme.labelLarge?.copyWith(
                              color: AppColors.primaryDark,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          const SizedBox(width: 4),
                          Icon(
                            Icons.arrow_forward,
                            size: 15,
                            color: AppColors.primaryDark,
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
              SizedBox(
                width: largeText ? 92 : 126,
                child: Arch(
                  footRadius: 10,
                  child: SizedBox.expand(
                    child: CategoryArtwork(category: category, iconSize: 40),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CategoryTile extends StatelessWidget {
  const _CategoryTile({
    required this.category,
    required this.count,
    required this.index,
    required this.onTap,
  });

  final String category;
  final int count;
  final int index;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    return PressableScale(
      onTap: onTap,
      semanticLabel: '$category, $count item${count == 1 ? '' : 's'}',
      child: Card(
        margin: EdgeInsets.zero,
        elevation: 0,
        clipBehavior: Clip.antiAlias,
        shape: RoundedRectangleBorder(
          borderRadius: editorialRadius(index, ShapeTier.card),
          side: BorderSide(color: AppColors.divider),
        ),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Arch clip on the category icon — carries the "shop
              // window" shape language into the secondary tiles, not
              // just the feature tile at the top.
              Arch(
                footRadius: 6,
                child: SizedBox(
                  width: 52,
                  height: 56,
                  child: CategoryArtwork(category: category),
                ),
              ),
              const Spacer(),
              // Two lines, then ellipsis. The label belongs to the server, and the
              // tile's height is computed for exactly this many lines.
              Text(
                category,
                style: theme.textTheme.titleSmall,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 2),
              Text(
                '$count item${count == 1 ? '' : 's'}',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
