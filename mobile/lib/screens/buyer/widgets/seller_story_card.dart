// lib/screens/buyer/widgets/seller_story_card.dart
//
// Seller voice, woven into the feed (critique item #6).
//
// Home used to be: search, banner, grid. Every shopping app is that,
// in that order, and no amount of decorating the grid changes it. The
// structural fix is a section that isn't a product listing at all —
// here, a seller talking — sitting *between* the product sections
// rather than being segregated onto a "Sellers" tab nobody opens.
//
// Shape-wise this is the clearest case of the image bleeding past its
// own container (#3): the portrait hangs below the tinted panel and
// overlaps the type underneath, so the two bands interlock instead of
// stacking.
//
// The quotes are placeholder voice, not real seller copy — see the
// note in _storyFor. Swapping them for real seller-written text is
// the #5 item, and it's a content job, not a code one.

import 'package:flutter/material.dart';

import '../../../data/mock_data.dart';
import '../../../theme/app_theme.dart';
import '../../../widgets/editorial_shapes.dart';
import '../../../widgets/pressable_scale.dart';
import 'product_card.dart';

class SellerStoryCard extends StatelessWidget {
  const SellerStoryCard({
    super.key,
    required this.product,
    required this.onTap,
  });

  final Product product;
  final VoidCallback onTap;

  /// Stand-in seller voice. Deliberately specific and a bit plain —
  /// the point of the section is that it sounds like a person wrote
  /// it, which "Fresh arrivals" never will. Keyed off the seller name
  /// so the same shop always says the same thing.
  String _storyFor(String seller) {
    const lines = [
      'We only make what we can finish by hand in a week. That is the whole business plan.',
      'Everything here starts on the same worktable, and most of it takes three tries.',
      'I started sewing these for my sister. She still gets the first one of every batch.',
      'If a piece does not survive a week in my own house, it does not get listed.',
    ];
    return lines[seller.hashCode.abs() % lines.length];
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return PressableScale(
      onTap: onTap,
      semanticLabel: 'From the maker, ${product.sellerName}: ${_storyFor(product.sellerName)} Opens ${product.name}.',
      child: LayoutBuilder(
        builder: (context, constraints) {
          // Portrait dimensions scale with available width so the
          // composition holds on narrow phones (< 340px) and at the
          // 560px content-cap max without the portrait drifting out of
          // the blush panel or overlapping the pull-quote.
          final w = constraints.maxWidth;
          final portraitW = (w * 0.27).clamp(88.0, 120.0);
          final portraitH = portraitW * 1.28;
          final portraitRight = (w * 0.04).clamp(10.0, 18.0);
          // Quote padding keeps text clear of the portrait at all widths.
          final quotePadding = portraitW + 8;

          return Padding(
            // Room at the bottom for the portrait's overhang.
            padding: EdgeInsets.only(bottom: portraitH * 0.13 + 30),
            child: Stack(
              clipBehavior: Clip.none,
              children: [
                Container(
                  padding: const EdgeInsets.fromLTRB(22, 24, 22, 26),
                  decoration: BoxDecoration(
                    color: AppColors.secondary.withValues(alpha: 0.55),
                    borderRadius: editorialRadius(0, big: 44, small: 8),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('FROM THE MAKER', style: AppType.eyebrow),
                      const SizedBox(height: 14),
                      Padding(
                        padding: EdgeInsets.only(right: quotePadding),
                        child: Text(_storyFor(product.sellerName), style: AppType.pullQuote),
                      ),
                      const SizedBox(height: 18),
                      Row(
                        children: [
                          Container(
                            width: 26,
                            height: 1.5,
                            color: AppColors.primaryDark.withValues(alpha: 0.5),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              product.sellerName,
                              style: theme.textTheme.labelLarge?.copyWith(
                                fontWeight: FontWeight.w700,
                                color: AppColors.primaryDark,
                              ),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                // Portrait, hanging off the bottom-right of the panel.
                Positioned(
                  right: portraitRight,
                  bottom: -(portraitH * 0.13),
                  width: portraitW,
                  height: portraitH,
                  child: Transform.rotate(
                    angle: -0.035,
                    child: Arch(
                      footRadius: 10,
                      child: DecoratedBox(
                        decoration: BoxDecoration(
                          boxShadow: [
                            BoxShadow(
                              color: AppColors.neutral.withValues(alpha: 0.3),
                              blurRadius: 18,
                              offset: const Offset(0, 8),
                            ),
                          ],
                        ),
                        child: ProductImage(
                          productId: '${product.sellerName}-maker',
                          tint: productTint(product.category),
                          iconSize: 28,
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}
