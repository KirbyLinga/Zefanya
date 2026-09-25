// lib/screens/buyer/widgets/product_card.dart
//
// Shared product grid tile — used by Home, Search, and Categories so
// the tint / bestseller-badge logic lives in exactly one place instead
// of being copy-pasted per screen.
//
// IMPORTANT: BuyerShell uses IndexedStack, which keeps Home, Search,
// Categories etc. all mounted simultaneously (just not painted) —
// so if the same product appeared as a Hero on two of those tabs at
// once with the same tag, Flutter throws "multiple heroes share the
// same tag" the moment you push ProductDetailsScreen. `heroTag` exists
// so each screen can namespace its tags (e.g. 'home-p1', 'search-p1')
// and never collide — pass the matching tag into ProductDetailsScreen
// so the flight still animates correctly from wherever the tap came from.

import 'package:flutter/material.dart';
import '../../../data/mock_data.dart';
import '../../../theme/app_theme.dart';
import '../../../widgets/editorial_shapes.dart';
import '../../../widgets/heart_button.dart';
import '../../../widgets/pressable_scale.dart';
import '../../../widgets/zefanya_mark.dart';

/// Soft per-category tint so grids aren't a wall of identical gray
/// tiles even before real product photos exist. Shared so Search and
/// Categories match Home's look exactly.
Color productTint(String category) => switch (category) {
      'Fashion' => AppColors.secondary,
      'Beauty' => AppColors.tertiary.withValues(alpha: 0.35),
      _ => AppColors.primary.withValues(alpha: 0.35),
    };

/// Lorem Picsum (picsum.photos) is a placeholder-image service built
/// specifically for this use case — random stock photography for
/// dev/demo UIs, not licensed for production use. Swap for real seller
/// photo uploads once that exists; this is scaffolding, not an asset
/// pipeline. Seeded by product id so the same product always shows the
/// same photo instead of a new random one every rebuild.
class ProductImage extends StatelessWidget {
  const ProductImage({super.key, required this.productId, required this.tint, this.iconSize = 40});

  final String productId;
  final Color tint;
  final double iconSize;

  @override
  Widget build(BuildContext context) {
    return Image.network(
      'https://picsum.photos/seed/$productId/400/400',
      fit: BoxFit.cover,
      width: double.infinity,
      height: double.infinity,
      loadingBuilder: (context, child, progress) {
        if (progress == null) return child;
        // Branded wait instead of Material's default spinner — this is
        // the most frequently-seen loading state in the whole app
        // (every tile, every grid, every time), so it's the highest
        // leverage place for the monogram to show up.
        return Container(
          color: tint,
          alignment: Alignment.center,
          child: ZefanyaLoader(
            size: iconSize * 0.8,
            color: AppColors.primaryDark.withValues(alpha: 0.55),
          ),
        );
      },
      // No internet, or picsum unreachable — fall back to the original
      // tint + icon placeholder rather than a broken-image glyph.
      errorBuilder: (context, error, stackTrace) => Container(
        color: tint,
        alignment: Alignment.center,
        child: Icon(Icons.image_outlined, size: iconSize, color: AppColors.neutral),
      ),
    );
  }
}

/// The cover story: the single biggest editorial moment on Home.
///
/// Three things here that the rest of the app doesn't do, all of them
/// deliberate and all from the same critique:
///   • the image is an ARCH, not a rounded rectangle (#3) — the one
///     silhouette in the app that can't be mistaken for a card;
///   • the price is a 46pt numeral (#2) — dramatically bigger than
///     anything around it, which is what "editorial" actually means
///     in practice;
///   • the arch's foot bleeds past the tile's own lower edge (#3),
///     into the reserved padding below — image and shape occupy space
///     a plain rectangle wouldn't reach.
///
/// That last point used to be "the arch crosses over the title", which
/// was a bug, not an effect: the text column had a fixed 190px width
/// regardless of screen size, so on narrower phones the image's left
/// edge landed *inside* that width and silently painted over the last
/// few letters — not an ellipsis, just glyphs hidden under an opaque
/// photo. An overlap only reads as intentional when it crosses blank
/// space; crossing text someone is meant to read is just a layout
/// bug wearing a design justification. Fixed by measuring the actual
/// available width (LayoutBuilder) and sizing the text column to stop
/// short of the image with a fixed gap, on every screen size — never
/// by tuning one fixed pixel value against one screenshot.
class CoverStoryTile extends StatelessWidget {
  const CoverStoryTile({
    super.key,
    required this.product,
    required this.heroTag,
    required this.onTap,
    this.eyebrow = 'THIS WEEK',
  });

  final Product product;
  final String heroTag;
  final VoidCallback onTap;
  final String eyebrow;

  static const double _imageWidth = 172;
  static const double _imageRightBleed = 6; // how far the arch pokes past the tile's own right edge
  static const double _textImageGap = 16; // enforced empty space between title text and image — never crossed
  static const double _bottomBleed = 24; // how far the arch's foot pokes below the tile's nominal bottom

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final tint = productTint(product.category);

    return PressableScale(
      onTap: onTap,
      semanticLabel: '${product.name}, ${product.price.toStringAsFixed(0)} pesos. $eyebrow.',
      child: Padding(
        // Reserves room for the foot's bottom bleed so Clip.none
        // doesn't spill into whatever sliver comes next.
        padding: const EdgeInsets.only(bottom: _bottomBleed),
        child: LayoutBuilder(
          builder: (context, constraints) {
            // Text never gets less than 110px (enough for a two-word
            // title before it starts looking cramped) — below that,
            // shrink the image instead of squeezing the text further,
            // so very narrow screens degrade the photo, not the copy.
            final rawTextWidth = constraints.maxWidth - _imageWidth - _textImageGap;
            final textWidth = rawTextWidth.clamp(110.0, 220.0);
            final imageWidth = rawTextWidth < 110
                ? (constraints.maxWidth - 110 - _textImageGap).clamp(90.0, _imageWidth)
                : _imageWidth;

            return SizedBox(
              height: 306,
              child: Stack(
                clipBehavior: Clip.none,
                children: [
                  // Type layer. Width is the measured-safe value above,
                  // so the image is guaranteed to start _textImageGap
                  // past its right edge — this text is never crossed.
                  Positioned(
                    left: 0,
                    bottom: _bottomBleed, // sits above the arch's foot-bleed zone, not inside it
                    child: SizedBox(
                      width: textWidth,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(eyebrow, style: AppType.eyebrow),
                          const SizedBox(height: 8),
                          Text(
                            product.name,
                            style: theme.textTheme.titleLarge?.copyWith(fontSize: 19, height: 1.15),
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                          const SizedBox(height: 10),
                          // The oversized numeral. Currency mark kept
                          // small on purpose so the digits carry the
                          // scale. Wrapped in FittedBox: at 46pt this
                          // is the widest single element on the tile,
                          // and textWidth can shrink to 110 on narrow
                          // screens — scale down rather than overflow.
                          FittedBox(
                            fit: BoxFit.scaleDown,
                            alignment: Alignment.centerLeft,
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Padding(
                                  padding: const EdgeInsets.only(top: 8),
                                  child: Text('\u20b1', style: AppType.priceNumeral(20)),
                                ),
                                const SizedBox(width: 2),
                                Text(
                                  product.price.toStringAsFixed(0),
                                  style: AppType.priceNumeral(46),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  // Arch image. Bleeds past the tile on two edges only
                  // — right (into the sliver's own horizontal padding)
                  // and bottom (into the padding this widget reserved
                  // above) — both blank space, never the text column.
                  Positioned(
                    right: -_imageRightBleed,
                    top: 0,
                    bottom: -_bottomBleed,
                    width: imageWidth,
                    child: Transform.rotate(
                      angle: 0.015,
                      child: Arch(
                        footRadius: 16,
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            boxShadow: [
                              BoxShadow(
                                color: AppColors.neutral.withValues(alpha: 0.28),
                                blurRadius: 24,
                                offset: const Offset(0, 10),
                              ),
                            ],
                          ),
                          child: Hero(
                            tag: heroTag,
                            child: ProductImage(productId: product.id, tint: tint),
                          ),
                        ),
                      ),
                    ),
                  ),
                  // Hand-placed tag, nested relative to the image's own
                  // box rather than the tile's width — stays correctly
                  // placed on the arch's shoulder regardless of how
                  // much the responsive imageWidth above scaled it.
                  Positioned(
                    right: -_imageRightBleed + imageWidth - 46,
                    top: 26,
                    child: Transform.rotate(
                      angle: -0.14,
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: AppColors.tertiary,
                          borderRadius: BorderRadius.circular(999),
                          boxShadow: [
                            BoxShadow(
                              color: Colors.black.withValues(alpha: 0.15),
                              blurRadius: 6,
                              offset: const Offset(0, 2),
                            ),
                          ],
                        ),
                        child: Text(
                          'Featured',
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: AppColors.primaryDark,
                            fontWeight: FontWeight.w700,
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
      ),
    );
  }
}

/// Full-width tile used to interrupt the grid partway down, so the
/// feed has a rhythm (2-up, 2-up, WIDE, 2-up) instead of one uniform
/// column of identical rows all the way to the bottom. Part of #6.
class WideProductTile extends StatelessWidget {
  const WideProductTile({
    super.key,
    required this.product,
    required this.heroTag,
    required this.onTap,
    this.kicker = 'ALSO WORTH A LOOK',
  });

  final Product product;
  final String heroTag;
  final VoidCallback onTap;
  final String kicker;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final tint = productTint(product.category);

    return Card(
      clipBehavior: Clip.antiAlias,
      // Leans the opposite way to the grid tile above it.
      shape: editorialShape(1, big: 40, small: 8),
      child: InkWell(
        onTap: onTap,
        child: SizedBox(
          height: 128,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 14, 10, 14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(kicker, style: AppType.eyebrow),
                      const SizedBox(height: 6),
                      Text(
                        product.name,
                        style: theme.textTheme.titleLarge?.copyWith(fontSize: 15, height: 1.15),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 6),
                      Text(
                        '\u20b1${product.price.toStringAsFixed(0)}',
                        style: AppType.priceNumeral(22),
                      ),
                    ],
                  ),
                ),
              ),
              SizedBox(
                width: 120,
                child: Hero(
                  tag: heroTag,
                  child: ProductImage(productId: product.id, tint: tint),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class ProductCard extends StatelessWidget {
  const ProductCard({
    super.key,
    required this.product,
    required this.heroTag,
    required this.onTap,
    this.index = 0,
  });

  final Product product;

  /// Position in its grid. Only used to alternate which diagonal the
  /// card's big corners sit on — adjacent tiles lean opposite ways, so
  /// the grid reads as a set of shapes rather than a wall of identical
  /// rounded boxes, without any one tile looking like a mistake.
  final int index;

  /// Must be unique across every simultaneously-mounted screen — see
  /// the file-level note above. Pass the same string into
  /// ProductDetailsScreen's `heroTag` so the flight connects correctly.
  final String heroTag;

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final tint = productTint(product.category);

    return Card(
      clipBehavior: Clip.antiAlias,
      shape: editorialShape(index),
      child: InkWell(
        onTap: onTap,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Stack(
                children: [
                  Hero(
                    tag: heroTag,
                    child: ProductImage(productId: product.id, tint: tint),
                  ),
                  if (product.soldCount >= 500)
                    Positioned(
                      left: 6,
                      top: 10,
                      // Slight rotation — a stamped/hand-placed tag rather
                      // than a dead-straight pill. Cheap, vector-only
                      // asymmetry that doesn't depend on photo quality,
                      // unlike a floating product cutout would.
                      child: Transform.rotate(
                        angle: -0.09,
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                            color: AppColors.primaryDark,
                            borderRadius: BorderRadius.circular(999),
                            boxShadow: [
                              BoxShadow(color: Colors.black.withValues(alpha: 0.15), blurRadius: 6, offset: const Offset(0, 2)),
                            ],
                          ),
                          child: Text(
                            'Bestseller',
                            style: theme.textTheme.labelSmall?.copyWith(
                              color: AppColors.textOnDark,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                      ),
                    ),
                  // Sits in the image's top-right corner, where a thumb
                  // lands; the bestseller tag is top-left, so they never
                  // collide.
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
                    style: theme.textTheme.titleLarge?.copyWith(fontSize: 14, fontWeight: FontWeight.w600),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 4),
                  // Price is now the loudest thing on the tile, not a
                  // slightly-bolder sibling of the product name.
                  Text(
                    '₱${product.price.toStringAsFixed(0)}',
                    style: AppType.priceNumeral(22),
                  ),
                  const SizedBox(height: 3),
                  // Trust signal row — rating and sold count were sitting
                  // unused on the Product model. One small line keeps the
                  // card from being just a name and a price.
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
                      if (product.soldCount > 0) ...[
                        Text(
                          '  ·  ',
                          style: theme.textTheme.labelSmall?.copyWith(color: AppColors.textSecondary),
                        ),
                        Text(
                          '${_formatSoldCount(product.soldCount)} sold',
                          style: theme.textTheme.labelSmall?.copyWith(color: AppColors.textSecondary),
                        ),
                      ],
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

/// '1200' -> '1.2k'; leaves smaller counts as-is so the row stays short
/// enough to fit next to the rating without wrapping.
String _formatSoldCount(int count) {
  if (count >= 1000) {
    return '${(count / 1000).toStringAsFixed(count % 1000 == 0 ? 0 : 1)}k';
  }
  return '$count';
}
