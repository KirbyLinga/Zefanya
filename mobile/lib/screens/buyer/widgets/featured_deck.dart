// lib/screens/buyer/widgets/featured_deck.dart
//
// A plain PageView rail of featured products — Shopee/Lazada-style,
// not a swipe-to-dismiss stack. Paging goes both ways with platform
// physics, so a swiped-past product is one swipe back, never lost.
// The next card peeks at the edge (viewportFraction 0.85); a dot
// indicator names the position ("1 of 5"); the rail ends naturally
// on the last page.
//
// Each page is a vertical arch card: the photo lives in the arch
// crown, the title/price/rating sit in the rectangular lower body,
// away from the curve — the side-by-side layout pushed text into the
// crown and clipped it. One shared silhouette (soft_arch.dart) for
// card fill, photo and shadow.
//
// Tap a card to open its product. Hero only on the visible page's
// photo (one Hero per tag at a time — PageView keeps neighbours
// mounted).

import 'package:flutter/material.dart';

import '../../../data/mock_data.dart';
import '../../../theme/app_theme.dart';
import '../../../utils/motion.dart';
import '../../../widgets/app_page_route.dart';
import '../product_details_screen.dart';
import 'product_card.dart';
import 'soft_arch.dart';

/// Fraction of the viewport each page occupies — matched to the promo
/// carousel's 0.92 so both rails share one card width. Peek on the
/// right only (padEnds false): the first card starts at the content
/// gutter, same left edge as the promo banner and search field.
const double kFeaturedViewportFraction = 0.92;

/// Fixed rail height: photo crown + label body + the dots' own strip.
/// One height for every page, so paging never shifts surrounding
/// content. The cue shares the section header line above the box.
const double kFeaturedRailHeight = 396.0;

/// Photo crown height inside the card. The crown curve consumes the
/// top ~43% of the photo's width; 168px keeps the arch readable
/// while the label body below stays rectangular and unclipped.
const double kFeaturedPhotoHeight = 168.0;

/// The rail: featured products as a both-ways PageView.
class FeaturedDeck extends StatefulWidget {
  const FeaturedDeck({
    super.key,
    required this.products,
    required this.heroTagPrefix,
    required this.isActive,
  });

  /// Featured products, most-featured first. Home passes take(5).
  final List<Product> products;

  /// Namespaces hero tags per screen (see product_card.dart).
  final String heroTagPrefix;

  /// False while another tab is showing. Kept for call-site
  /// compatibility; the rail has no entrance animation to gate.
  final bool isActive;

  @override
  State<FeaturedDeck> createState() => _FeaturedDeckState();
}

class _FeaturedDeckState extends State<FeaturedDeck> {
  late final PageController _pages = PageController(
    viewportFraction: kFeaturedViewportFraction,
  );

  /// Visible page, rounded. Drives the dot indicator and the Hero
  /// gate (only the current page's photo gets a Hero).
  int _page = 0;

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  void _openProduct(Product product) {
    Navigator.of(context).push(
      AppPageRoute(
        builder: (_) => ProductDetailsScreen(
          product: product,
          heroTag: '${widget.heroTagPrefix}-${product.id}',
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final total = widget.products.length;
    if (total == 0) return const SizedBox.shrink();
    return _buildRail(context, theme, total);
  }

  Widget _buildRail(BuildContext context, ThemeData theme, int total) {
    return Semantics(
      label: 'Featured, $total items',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Section header: same 20px left gutter as the grid and the
          // rail cards. The cue ends at the card's right edge
          // (Claude alignment #2): the full-bleed 0.92 page puts the
          // card's right edge at 0.92W − 20, so the header's right
          // pad is 0.08W + 20 — computed from the real width, not a
          // constant, so 360dp and 411dp both agree.
          LayoutBuilder(
            builder: (context, constraints) {
              final rightPad = 0.08 * constraints.maxWidth + 20;
              return Padding(
                padding: EdgeInsets.fromLTRB(20, 0, rightPad, 10),
                child: Row(
                  children: [
                    // Flexible eyebrow: at 1.5x text scale the tracked
                    // caps would otherwise overflow — ellipsis instead.
                    Expanded(
                      child: Text(
                        'FEATURED THIS WEEK',
                        style: AppType.eyebrow,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    const SizedBox(width: 12),
                    // 1-based position: the reader is ON card 1.
                    Text(
                      '${_page + 1} OF $total',
                      style: AppType.eyebrow.copyWith(
                        color: AppColors.textPrimary,
                      ),
                    ),
                  ],
                ),
              );
            },
          ),
          SizedBox(
            height: kFeaturedRailHeight,
            child: Column(
              children: [
                Expanded(
                  // Peek on the right only: first page starts at the
                  // viewport's left edge (the content gutter), not
                  // centered with leading pad.
                  child: PageView.builder(
                    padEnds: false,
                    controller: _pages,
                    itemCount: total,
                    onPageChanged: (i) => setState(() => _page = i),
                    itemBuilder: (context, i) {
                      final product = widget.products[i];
                      final heroTag = '${widget.heroTagPrefix}-${product.id}';
                      return _buildPage(context, i, product, heroTag);
                    },
                  ),
                ),
                // Dot strip in its own reserved row below the cards
                // (Claude alignment #3): never overlaps the price row,
                // even at 1.5x text scale. Quieter than the promo
                // carousel's — 5px dots in textSecondary at 60%,
                // active in primaryDark. 44px tap areas via padding;
                // Semantics names each dot.
                _buildDots(context, total),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// One page with the layered treatment: slight scale + tilt on the
  /// side pages, end state at rest. Transforms only, one
  /// RepaintBoundary per page; reduced motion skips the tilt.
  Widget _buildPage(
    BuildContext context,
    int i,
    Product product,
    String heroTag,
  ) {
    return AnimatedBuilder(
      animation: _pages,
      builder: (context, child) {
        var scale = 1.0;
        var tilt = 0.0;
        if (!reduceMotion(context)) {
          try {
            final delta = ((_pages.page ?? _page.toDouble()) - i).clamp(
              -1.0,
              1.0,
            );
            scale = 1 - 0.06 * delta.abs();
            tilt = -0.02 * delta;
          } catch (_) {
            // Controller not attached yet (first build): end state.
          }
        }
        return Transform.rotate(
          angle: tilt,
          child: Transform.scale(scale: scale, child: child),
        );
      },
      child: RepaintBoundary(
        // Page inset: 20px gutter on the outer edge only. With
        // padEnds false the page's left edge IS the gutter, so the
        // card needs its own 20px left pad; the right pad matches
        // (peek gap doubles as the inter-card gap). Net: first card
        // spans 20..W-20, same as the promo card (full-bleed 0.92
        // page + 4px insets + 16px sliver pad ≈ 20).
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 0),
          child: _RailCard(
            product: product,
            theme: Theme.of(context),
            heroTag: heroTag,
            withHero: i == _page,
            onOpen: () => _openProduct(product),
          ),
        ),
      ),
    );
  }

  /// Dot strip: quieter than the promo carousel's (Claude polish #3).
  /// 5px dots, inactive in textSecondary at 60%, active pill in
  /// primaryDark. 44px tap areas via padding; Semantics names each dot.
  /// Non-interactive under reduced motion? No — dots are a jump, not
  /// an animation; the jump branch in onTap already skips motion.
  Widget _buildDots(BuildContext context, int total) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        for (var i = 0; i < total; i++)
          Semantics(
            button: true,
            label: 'Go to featured ${i + 1} of $total',
            excludeSemantics: true,
            child: GestureDetector(
              // Reduced motion: jump, never animate — animateToPage
              // asserts on Duration.zero, so branch instead.
              onTap: () => reduceMotion(context)
                  ? _pages.jumpToPage(i)
                  : _pages.animateToPage(
                      i,
                      duration: const Duration(milliseconds: 300),
                      curve: Curves.easeOutCubic,
                    ),
              behavior: HitTestBehavior.opaque,
              child: Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 14,
                ),
                child: AnimatedContainer(
                  duration: reduceMotion(context)
                      ? Duration.zero
                      : const Duration(milliseconds: 200),
                  width: i == _page ? 16 : 5,
                  height: 5,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(3),
                    color: i == _page
                        ? AppColors.primaryDark
                        : AppColors.textSecondary.withValues(alpha: 0.6),
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

/// One rail card: arch photo crown on top, labels in the rectangular
/// lower body.
class _RailCard extends StatelessWidget {
  const _RailCard({
    required this.product,
    required this.theme,
    required this.heroTag,
    required this.withHero,
    required this.onOpen,
  });

  final Product product;
  final ThemeData theme;
  final String heroTag;

  /// Only the visible page wraps its photo in a Hero — PageView keeps
  /// neighbours mounted, and two Heroes on one tag throw.
  final bool withHero;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final photo = ProductImage(
      product: product,
      alignment: Alignment.topCenter,
    );
    return GestureDetector(
      onTap: onOpen,
      behavior: HitTestBehavior.opaque,
      child: Material(
        color: AppColors.surface,
        elevation: 6,
        shadowColor: AppColors.cardShadow,
        shape: const SoftArchBorder(),
        child: ClipPath(
          clipper: const SoftArchClipper(),
          child: ColoredBox(
            color: AppColors.surface,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // Photo crown: clipped to the same arch as the fill.
                SizedBox(
                  height: kFeaturedPhotoHeight,
                  width: double.infinity,
                  child: ClipPath(
                    clipper: const SoftArchClipper(),
                    child: withHero ? Hero(tag: heroTag, child: photo) : photo,
                  ),
                ),
                // Label body: rectangular, below the curve. Fills its
                // height with category tag + name + one-line
                // description + price, so no dead white space
                // (Claude polish #1). Fixed card height keeps every
                // page identical — see the height test.
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 10, 16, 28),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          product.category.toUpperCase(),
                          style: AppType.eyebrow.copyWith(
                            color: AppColors.textSecondary,
                          ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          product.name,
                          style: theme.textTheme.titleMedium?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        if (product.description.isNotEmpty) ...[
                          const SizedBox(height: 2),
                          Text(
                            product.description,
                            style: theme.textTheme.bodySmall?.copyWith(
                              color: AppColors.textSecondary,
                            ),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ],
                        const Spacer(),
                        PriceLabel(
                          amount: product.price,
                          numeralSize: 24,
                          semanticLabel:
                              'Price: ${product.price.toStringAsFixed(0)} pesos',
                        ),
                        const SizedBox(height: 2),
                        Row(
                          children: [
                            Icon(
                              Icons.star_rounded,
                              size: 13,
                              color: AppColors.sageIcon,
                            ),
                            const SizedBox(width: 2),
                            Text(
                              product.rating.toStringAsFixed(1),
                              style: theme.textTheme.labelSmall?.copyWith(
                                fontWeight: FontWeight.w700,
                                color: AppColors.textSecondary,
                              ),
                            ),
                            // Flexible sold count: at 1.5x text scale the
                            // row would otherwise overflow the card —
                            // ellipsis instead of a stripe.
                            if (product.soldCount > 0)
                              Expanded(
                                child: Text(
                                  '  ·  ${product.soldCount} sold',
                                  style: theme.textTheme.labelSmall?.copyWith(
                                    color: AppColors.textSecondary,
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
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
