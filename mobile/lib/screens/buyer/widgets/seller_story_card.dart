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
// ## The note, and why the portrait is a mark
//
// The copy now comes from the data — `makerNoteFor(sellerName)` in
// mock_data.dart, keyed by shop. It used to be four hardcoded lines picked
// by `sellerName.hashCode`, which meant a shop's voice was decided by a
// string hash and a shop that had written nothing got words anyway. A shop
// with no note now renders no section: inventing a voice for someone is
// worse than leaving a gap.
//
// The photo half of that same problem is handled here too, not deferred.
// This slot is a *person*, so it can't be a random stock photograph the way
// a product tile can: a real, specific, human quote sitting next to a
// stranger's face is a worse mismatch than placeholder-next-to-placeholder,
// because the inconsistency becomes the thing you notice. Until a seller
// uploads their own portrait (image_picker is already wired up for profile
// and registration), the slot shows the maker mark — the same monogram the
// app uses for loading and empty states — which claims nothing about who
// the seller is.

import 'package:flutter/material.dart';

import '../../../data/mock_data.dart';
import '../../../theme/app_theme.dart';
import '../../../widgets/editorial_shapes.dart';
import '../../../widgets/pressable_scale.dart';
import '../../../widgets/zefanya_mark.dart';
import 'product_card.dart';

class SellerStoryCard extends StatelessWidget {
  const SellerStoryCard({
    super.key,
    required this.product,
    required this.onTap,
  });

  final Product product;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // No note, no section: see the file header.
    final note = makerNoteFor(product.sellerName);
    if (note == null) return const SizedBox.shrink();

    return PressableScale(
      onTap: onTap,
      semanticLabel: 'From the maker, ${product.sellerName}: $note Opens ${product.name}.',
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
                    // On the dark ground, secondary (light blush) reads as a
                    // bright window rather than a warm band. surfaceMuted keeps
                    // the card in the same ink family with a subtle lift.
                    color: AppColors.surfaceMuted,
                    borderRadius: editorialRadius(0, ShapeTier.hero),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('FROM THE MAKER', style: AppType.eyebrow),
                      const SizedBox(height: 14),
                      Padding(
                        padding: EdgeInsets.only(right: quotePadding),
                        child: Text(note, style: AppType.pullQuote),
                      ),
                      const SizedBox(height: 18),
                      Row(
                        children: [
                          Container(
                            width: 26,
                            height: 1.5,
                            color: AppColors.accent.withValues(alpha: 0.6),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              product.sellerName,
                              style: theme.textTheme.labelLarge?.copyWith(
                                fontWeight: FontWeight.w700,
                                color: AppColors.accent,
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
                        // The maker mark, not a stock photo — see the file
                        // header. Tinted by category so different shops'
                        // marks don't all look identical, and inked with
                        // tintInk so it reads in both modes.
                        child: ColoredBox(
                          color: productTint(product.category),
                          child: Center(
                            child: ZefanyaMark(
                              size: portraitW * 0.42,
                              color: AppColors.tintInk.withValues(alpha: 0.75),
                            ),
                          ),
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
