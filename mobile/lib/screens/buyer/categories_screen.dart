// lib/screens/buyer/categories_screen.dart
//
// Categories tab: a browsable index of the catalog's categories
// (data/mock_data.dart), each showing how many products it holds.
// Tapping one pushes CategoryProductsScreen filtered to it.
//
// No page title here — the bottom nav already says "Categories".
// Instead the category with the most products gets a wide feature
// panel up top (an arch-clipped icon next to its name, the same
// silhouette Home's cover story uses for its lead product) and the
// rest sit below it as a smaller index — an asymmetric hierarchy
// instead of one uniform grid of equal tiles.

import 'package:flutter/material.dart';
import '../../data/mock_data.dart';
import '../../theme/app_theme.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/pressable_scale.dart';
import 'category_products_screen.dart';
import 'widgets/product_card.dart';
import '../../widgets/app_page_route.dart';

class CategoriesScreen extends StatelessWidget {
  const CategoriesScreen({super.key});

  static const _icons = {
    'Fashion': Icons.checkroom_outlined,
    'Home & Living': Icons.chair_outlined,
    'Beauty': Icons.spa_outlined,
  };

  List<(String, int)> _rankedCategories() {
    final categories = mockCategories.where((c) => c != 'All').toList();
    final ranked = [
      for (final c in categories) (c, mockProducts.where((p) => p.category == c).length),
    ];
    ranked.sort((a, b) => b.$2.compareTo(a.$2));
    return ranked;
  }

  @override
  Widget build(BuildContext context) {
    final ranked = _rankedCategories();
    if (ranked.isEmpty) return const SizedBox.shrink();
    final feature = ranked.first;
    final rest = ranked.sublist(1);

    return Scaffold(
      backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      body: SafeArea(
        child: CustomScrollView(
          slivers: [
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(20, 18, 20, 0),
              sliver: SliverToBoxAdapter(
                child: _FeatureCategoryTile(
                  category: feature.$1,
                  count: feature.$2,
                  icon: _icons[feature.$1] ?? Icons.category_outlined,
                  onTap: () => Navigator.of(context).push(
                    AppPageRoute(builder: (_) => CategoryProductsScreen(category: feature.$1)),
                  ),
                ),
              ),
            ),
            if (rest.isNotEmpty) ...[
              SliverToBoxAdapter(
                child: DiagonalSectionBreak(
                  color: AppColors.tertiary.withValues(alpha: 0.16),
                  height: 28,
                ),
              ),
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
                sliver: SliverLayoutBuilder(
                  builder: (context, sliverConstraints) {
                    // Tile width at 2-column grid with 16px gap and 40px padding.
                    final tileW = (sliverConstraints.crossAxisExtent - 16) / 2;
                    // Arch icon (52px) + spacer + two text lines (~44px) + 32px padding.
                    final contentH = 52 + 44 + 32;
                    final aspectRatio = tileW / contentH.toDouble();
                    return SliverGrid(
                      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: 2,
                        mainAxisSpacing: 16,
                        crossAxisSpacing: 16,
                        childAspectRatio: aspectRatio.clamp(0.9, 1.3),
                      ),
                      delegate: SliverChildBuilderDelegate(
                        (context, i) {
                          final (category, count) = rest[i];
                          return _CategoryTile(
                            category: category,
                            count: count,
                            icon: _icons[category] ?? Icons.category_outlined,
                            index: i,
                            onTap: () => Navigator.of(context).push(
                              AppPageRoute(builder: (_) => CategoryProductsScreen(category: category)),
                            ),
                          );
                        },
                        childCount: rest.length,
                      ),
                    );
                  },
                ),
              ),
            ] else
              const SliverToBoxAdapter(child: SizedBox(height: 24)),
          ],
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
    required this.icon,
    required this.onTap,
  });

  final String category;
  final int count;
  final IconData icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final tint = productTint(category);

    return PressableScale(
      onTap: onTap,
      semanticLabel: '$category, $count item${count == 1 ? '' : 's'}. Browse.',
      child: ClipRRect(
        borderRadius: editorialRadius(0, big: 32, small: 10),
        child: Container(
          height: 168,
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
                      Text(category, style: AppType.sectionDisplay.copyWith(fontSize: 28)),
                      const SizedBox(height: 8),
                      Text('$count item${count == 1 ? '' : 's'}', style: theme.textTheme.bodyMedium),
                      const SizedBox(height: 10),
                      Row(
                        children: [
                          Text('Browse', style: theme.textTheme.labelLarge?.copyWith(color: AppColors.primaryDark, fontWeight: FontWeight.w700)),
                          const SizedBox(width: 4),
                          const Icon(Icons.arrow_forward, size: 15, color: AppColors.primaryDark),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
              SizedBox(
                width: 128,
                child: Arch(
                  footRadius: 10,
                  child: Container(
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                        colors: [tint, Color.lerp(tint, AppColors.primaryDark, 0.45)!],
                      ),
                    ),
                    alignment: Alignment.center,
                    child: Icon(icon, size: 44, color: AppColors.textOnDark),
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
    required this.icon,
    required this.index,
    required this.onTap,
  });

  final String category;
  final int count;
  final IconData icon;
  final int index;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return PressableScale(
      onTap: onTap,
      semanticLabel: '$category, $count item${count == 1 ? '' : 's'}',
      child: Card(
        clipBehavior: Clip.antiAlias,
        shape: editorialShape(index),
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
                child: Container(
                  width: 40,
                  height: 52,
                  color: productTint(category).withValues(alpha: 0.6),
                  alignment: Alignment.center,
                  child: Icon(icon, color: AppColors.primaryDark, size: 20),
                ),
              ),
              const Spacer(),
              Text(category, style: theme.textTheme.titleSmall),
              const SizedBox(height: 2),
              Text('$count item${count == 1 ? '' : 's'}', style: theme.textTheme.bodySmall),
            ],
          ),
        ),
      ),
    );
  }
}
