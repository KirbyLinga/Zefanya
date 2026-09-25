// lib/screens/buyer/widgets/saved_rail.dart
//
// "Saved for later" — Home's shortlist rail. Shows only what the buyer has
// actually hearted (see state/wishlist_state.dart); with nothing saved the
// whole section, including its spacing, is simply absent. Same rule as
// recently_viewed_rail.dart: every entry is something this person did, so
// nothing here is filler or a fabricated activity signal.
//
// The "See all" header link pushes SavedScreen for when the list grows
// past what a horizontal strip can comfortably show.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../data/mock_data.dart';
import '../../../state/wishlist_state.dart';
import '../../../theme/app_theme.dart';
import '../../../widgets/app_page_route.dart';
import '../saved_screen.dart';
import 'recently_viewed_rail.dart' show ProductRailTile;

class SavedRail extends StatelessWidget {
  const SavedRail({super.key, required this.onTapProduct});

  final void Function(Product product) onTapProduct;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final products = context.watch<WishlistState>().items;
    if (products.isEmpty) return const SizedBox.shrink();

    return Padding(
      // Spacing lives here, not at the call site, so an empty shortlist
      // leaves no gap in the scroll.
      padding: const EdgeInsets.only(top: 22),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 8, 12),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('YOUR SHORTLIST', style: AppType.eyebrow),
                      const SizedBox(height: 8),
                      Text('Saved for later', style: theme.textTheme.titleLarge?.copyWith(fontSize: 20)),
                    ],
                  ),
                ),
                // Only shown when there's more than the rail can
                // comfortably display — avoids a "See all → 2 items"
                // anti-pattern on a short list.
                if (products.length > 4)
                  TextButton(
                    onPressed: () => Navigator.of(context).push(
                      AppPageRoute(builder: (_) => const SavedScreen()),
                    ),
                    child: const Text('See all'),
                  ),
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
              itemBuilder: (context, i) => ProductRailTile(
                product: products[i],
                index: i,
                onTap: () => onTapProduct(products[i]),
                showHeart: true,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
