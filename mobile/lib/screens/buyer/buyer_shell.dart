// lib/screens/buyer/buyer_shell.dart
//
// Top-level navigation shell for the Buyer app: Home, Search,
// Categories, Orders, Profile — behind the floating rounded nav bar.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../data/mock_data.dart';
import '../../state/cart_state.dart';
import '../../state/courier_state.dart';
import '../../state/order_state.dart';
import '../../theme/app_theme.dart';
import '../../widgets/floating_nav_bar.dart';
import '../../widgets/nav_icons.dart';
import '../shared/profile_screen.dart';
import 'cart_screen.dart';
import 'categories_screen.dart';
import 'category_products_screen.dart';
import 'orders_status_screen.dart';
import 'product_details_screen.dart';
import 'search_screen.dart';
import 'vouchers_screen.dart';
import 'widgets/product_card.dart';
import 'widgets/promo_carousel.dart';
import 'widgets/product_stack_showcase.dart';
import 'widgets/seller_story_card.dart';
import 'widgets/recently_viewed_rail.dart';
import 'widgets/saved_rail.dart';
import '../../widgets/staggered_reveal.dart';
import '../../widgets/shimmer_box.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/cart_flight.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/zefanya_mark.dart';

class BuyerShell extends StatefulWidget {
  const BuyerShell({super.key});

  @override
  State<BuyerShell> createState() => _BuyerShellState();
}

class _BuyerShellState extends State<BuyerShell> {
  int _index = 0;

  /// Bumped to focus the Search tab's field — see SearchScreen.focusRequests.
  final _searchFocusRequests = ValueNotifier<int>(0);

  @override
  void dispose() {
    _searchFocusRequests.dispose();
    super.dispose();
  }

  /// Home's search bar is a doorway, not a second search UI: jump to the
  /// Search tab and put the cursor in its field.
  void _openSearch() {
    setState(() => _index = 1);
    _searchFocusRequests.value++;
  }

  @override
  Widget build(BuildContext context) {
    // Count orders that have moved to a new status since last viewed.
    // "In transit" and "out for delivery" are the two stages the buyer
    // actively wants to know about — toShip is expected, toRate is done.
    final orders = context.watch<OrderState>().resolvedOrders(context.watch<CourierState>());
    final activeOrders = orders.where((o) =>
        o.status == OrderStatus.inTransit ||
        o.status == OrderStatus.outForDelivery).length;

    final items = [
      const NavBarItem(glyph: NavGlyph.home, label: 'Home'),
      const NavBarItem(glyph: NavGlyph.search, label: 'Search'),
      const NavBarItem(glyph: NavGlyph.categories, label: 'Categories'),
      NavBarItem(glyph: NavGlyph.orders, label: 'Orders', badgeCount: activeOrders),
      const NavBarItem(glyph: NavGlyph.profile, label: 'Profile'),
    ];

    return Scaffold(
      extendBody: true,
      body: IndexedStack(
        index: _index,
        children: [
          // isActive: Home stays mounted under the other tabs (IndexedStack),
          // so it has to be told when it isn't the one on screen.
          _BuyerHomeScreen(isActive: _index == 0, onSearchTap: _openSearch),
          SearchScreen(focusRequests: _searchFocusRequests),
          const CategoriesScreen(),
          const OrdersStatusScreen(),
          const ProfileScreen(title: 'Buyer Profile'),
        ],
      ),
      bottomNavigationBar: FloatingNavBar(
        items: items,
        currentIndex: _index,
        onTap: (i) => setState(() => _index = i),
      ),
    );
  }
}

/// Home / browsing screen: search bar, hero carousel, full product grid.
/// Pulls from data/mock_data.dart so it stays in sync with what Cart,
/// Checkout, and Order Status show for the same products. Category
/// browsing lives only in the Categories tab now — Home used to also
/// have its own category chips, which was two different UIs doing the
/// same job.
class _BuyerHomeScreen extends StatefulWidget {
  const _BuyerHomeScreen({required this.isActive, required this.onSearchTap});

  /// False while another tab is showing — pauses the promo banner.
  final bool isActive;

  /// Tapping the search bar hands off to the Search tab.
  final VoidCallback onSearchTap;

  @override
  State<_BuyerHomeScreen> createState() => _BuyerHomeScreenState();
}

class _BuyerHomeScreenState extends State<_BuyerHomeScreen> {
  bool _isLoading = true;

  // Which product leads (featured tile) and which pair shows in the
  // Style Edit stack — picked once per app launch instead of always
  // being mockProducts[0]/[2]/[3]. With only 8 mock products and a
  // fixed selection, every screenshot/review during development ends
  // up showing the exact same "Linen Blend Wrap Dress" as the hero,
  // every single time, no matter how much the chrome around it
  // changes — that repetition reads as staleness on its own, separate
  // from anything actually wrong with the visual design.
  late final List<Product> _shuffled;

  /// The seller featured in the "From the maker" card. Chosen from a
  /// different shop than the cover story's, so the same seller (and its
  /// product) doesn't headline two adjacent sections of one scroll.
  late final Product? _storyProduct;

  @override
  void initState() {
    super.initState();
    _shuffled = List<Product>.from(mockProducts)..shuffle();
    _storyProduct = _shuffled.isEmpty
        ? null
        : _shuffled.firstWhere(
            (p) => p.sellerName != _shuffled.first.sellerName,
            orElse: () => _shuffled.first,
          );
    // Brief simulated load so the grid doesn't just snap into place —
    // swap for a real product-fetch call once the API is wired up.
    Future.delayed(const Duration(milliseconds: 450), () {
      if (mounted) setState(() => _isLoading = false);
    });
  }

  /// The band color for the "Style Edit" section. A section with its
  /// own ground — entered and left through a curve — is what stops the
  /// page reading as one continuous scroll of tiles.
  static final Color _bandColor = AppColors.surface.withValues(alpha: 0.62);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final cartCount = context.watch<CartState>().itemCount;

    // Grid excludes whatever's already shown above it on Home: index 0
    // (the cover story) and, when there are enough products, 2/3 (the
    // Style Edit stack showcase) — so nothing repeats twice on one screen.
    final excludedFromGrid = _shuffled.length > 3 ? const {0, 2, 3} : const {0};
    final gridProducts = [
      for (var j = 0; j < _shuffled.length; j++)
        if (!excludedFromGrid.contains(j)) _shuffled[j],
    ];

    // The feed is split so full-width tiles interrupt it periodically:
    // 2-up, 2-up, WIDE, 2-up, 2-up, 2-up, 2-up, WIDE, ... A single
    // one-time break only fixed the rhythm near the top — with a real
    // catalog behind this instead of 8 mock products, everything past
    // the first break was still one long uninterrupted grid. Now it
    // repeats every _shelfBreakInterval items for as long as the feed
    // does, so it doesn't degrade with more products.

    return Scaffold(
      backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      body: SafeArea(
        child: CustomScrollView(
          slivers: [
            // ---- Masthead ----------------------------------------
            // Was a 20pt headlineSmall wordmark sitting at the same
            // visual weight as everything under it. Nothing on the
            // screen was dramatically bigger than anything else, so
            // the eye had nowhere to land first. Login and Landing use
            // this same masthead treatment for their own wordmark — on
            // purpose, see AppType.masthead's doc comment — but this is
            // the only place it appears *inside* the app rather than in
            // the pre-auth flow.
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 18, 12, 4),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('GOOD THINGS, MADE NEARBY', style: AppType.eyebrow),
                          const SizedBox(height: 10),
                          Row(
                            crossAxisAlignment: CrossAxisAlignment.center,
                            children: [
                              Text('Zefanya', style: AppType.masthead),
                              const SizedBox(width: 10),
                              Padding(
                                padding: const EdgeInsets.only(top: 6),
                                child: ZefanyaMark(
                                  size: 20,
                                  color: AppColors.primaryDark.withValues(alpha: 0.75),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                    // CartAnchor makes this the landing point for the
                    // add-to-cart flight (see widgets/cart_flight.dart)
                    // and plays the catch pop when an item arrives.
                    CartAnchor(
                      child: Stack(
                        clipBehavior: Clip.none,
                        children: [
                          IconButton(
                            onPressed: () => Navigator.of(context).push(
                              AppPageRoute(builder: (_) => const CartScreen()),
                            ),
                            icon: const Icon(Icons.shopping_bag_outlined),
                            color: AppColors.textPrimary,
                          ),
                          Positioned(
                            right: 2,
                            top: 2,
                            child: AnimatedSwitcher(
                              duration: const Duration(milliseconds: 200),
                              transitionBuilder: (child, animation) => ScaleTransition(
                                scale: animation,
                                child: FadeTransition(opacity: animation, child: child),
                              ),
                              child: cartCount > 0
                                  ? Container(
                                      key: ValueKey(cartCount),
                                      padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                                      decoration: BoxDecoration(
                                        color: AppColors.primaryDark,
                                        borderRadius: BorderRadius.circular(999),
                                      ),
                                      child: Text(
                                        cartCount > 9 ? '9+' : '$cartCount',
                                        style: theme.textTheme.labelSmall?.copyWith(
                                          color: AppColors.textOnDark,
                                          fontWeight: FontWeight.w700,
                                        ),
                                      ),
                                    )
                                  : const SizedBox.shrink(key: ValueKey('no-badge')),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
            // ---- Search ------------------------------------------
            // Deliberately demoted: it used to be the first thing on
            // the page at full width, which is the layout every
            // shopping app opens with. It still works, it just isn't
            // the headline any more.
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 14, 20, 12),
                child: TextField(
                  // Read-only: this is a doorway to the Search tab, so
                  // tapping must not raise the keyboard on Home first.
                  readOnly: true,
                  onTap: widget.onSearchTap,
                  decoration: InputDecoration(
                    isDense: true,
                    hintText: 'Search products, brands...',
                    prefixIcon: const Icon(Icons.search, size: 20),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(ext.pillRadius),
                      borderSide: BorderSide.none,
                    ),
                  ),
                ),
              ),
            ),
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(0, 0, 0, 12),
                child: PromoCarousel(
                  autoAdvance: widget.isActive,
                  onTapSlide: _onPromoTap,
                ),
              ),
            ),
            if (_isLoading)
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 100),
                sliver: SliverGrid(
                  gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 2,
                    mainAxisSpacing: 16,
                    crossAxisSpacing: 16,
                    childAspectRatio: 0.68,
                  ),
                  delegate: SliverChildBuilderDelegate(
                    (context, i) => ShimmerBox(
                      child: Container(
                        decoration: BoxDecoration(
                          color: AppColors.surfaceMuted,
                          // Skeletons match the real tiles' asymmetric
                          // corners, so the shape doesn't visibly
                          // change when content arrives.
                          borderRadius: editorialRadius(i),
                        ),
                      ),
                    ),
                    childCount: 4,
                  ),
                ),
              )
            else if (_shuffled.isEmpty)
              SliverToBoxAdapter(child: _buildEmptyState(theme))
            else ...[
              // ---- Cover story -----------------------------------
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
                sliver: SliverToBoxAdapter(
                  child: StaggeredReveal(
                    index: 0,
                    child: CoverStoryTile(
                      product: _shuffled.first,
                      heroTag: 'home-${_shuffled.first.id}',
                      onTap: () => Navigator.of(context).push(
                        AppPageRoute(
                          builder: (_) => ProductDetailsScreen(
                            product: _shuffled.first,
                            heroTag: 'home-${_shuffled.first.id}',
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              // ---- Welcome back --------------------------------------
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.only(top: 22),
                  child: RecentlyViewedRail(
                    excludeProductId: _shuffled.first.id,
                    onTapProduct: (product) => Navigator.of(context).push(
                      AppPageRoute(builder: (_) => ProductDetailsScreen(product: product)),
                    ),
                  ),
                ),
              ),
              // ---- Saved for later -----------------------------------
              // Renders nothing (and no spacing) until something is hearted.
              SliverToBoxAdapter(
                child: SavedRail(
                  onTapProduct: (product) => Navigator.of(context).push(
                    AppPageRoute(builder: (_) => ProductDetailsScreen(product: product)),
                  ),
                ),
              ),
              // ---- Style Edit, on its own ground -----------------
              SliverToBoxAdapter(
                child: CurvedSectionBreak(color: _bandColor, height: 54),
              ),
              SliverToBoxAdapter(
                child: Container(
                  color: _bandColor,
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('PAIRED THIS WEEK', style: AppType.eyebrow),
                      const SizedBox(height: 10),
                      // Not AppType.sectionDisplay: Home already spends
                      // that scale on "The Shelf" below, and AppType's
                      // own doc comment is explicit that these display
                      // styles are one-per-screen — two competing 34pt
                      // headings in the same scroll undoes the hierarchy
                      // they exist to create. titleLarge is still Playfair
                      // (see _buildTextTheme) so the face doesn't change,
                      // just the scale.
                      Text('Style Edit', style: theme.textTheme.titleLarge),
                      const SizedBox(height: 8),
                      Text(
                        'Two pieces that keep ending up in the same basket.',
                        style: theme.textTheme.bodyMedium?.copyWith(color: AppColors.textSecondary),
                      ),
                      const SizedBox(height: 16),
                      if (_shuffled.length > 3)
                        ProductStackShowcase(
                          back: _shuffled[2],
                          front: _shuffled[3],
                          heroTagPrefix: 'home-stack',
                        ),
                    ],
                  ),
                ),
              ),
              SliverToBoxAdapter(
                child: CurvedSectionBreak(color: _bandColor, height: 46, fillTop: true),
              ),
              // ---- Seller voice ----------------------------------
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
                sliver: SliverToBoxAdapter(
                  child: SellerStoryCard(
                    product: _storyProduct ?? _shuffled.first,
                    onTap: () => Navigator.of(context).push(
                      AppPageRoute(
                        builder: (_) => ProductDetailsScreen(product: _storyProduct ?? _shuffled.first),
                      ),
                    ),
                  ),
                ),
              ),
              // ---- The feed --------------------------------------
              // LinenDivider adds a material-texture moment between the
              // editorial content above and the product grid below —
              // a subtle woven band rather than a flat tinted diagonal.
              SliverToBoxAdapter(
                child: LinenDivider(height: 28),
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 4, 20, 14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('EVERYTHING ELSE', style: AppType.eyebrow),
                      const SizedBox(height: 10),
                      Text('The Shelf', style: AppType.sectionDisplay),
                    ],
                  ),
                ),
              ),
              ..._buildShelfSlivers(gridProducts),
              const SliverToBoxAdapter(child: SizedBox(height: 100)),
            ],
          ],
        ),
      ),
    );
  }

  /// Where each promo slide leads. Order matches `_slides` in
  /// promo_carousel.dart: new arrivals, the Home & Living sale, shipping.
  void _onPromoTap(int index) {
    final navigator = Navigator.of(context);
    if (index == 0) {
      navigator.push(AppPageRoute(builder: (_) => const CategoryProductsScreen(category: 'Fashion')));
    } else if (index == 1) {
      navigator.push(AppPageRoute(builder: (_) => const CategoryProductsScreen(category: 'Home & Living')));
    } else {
      navigator.push(AppPageRoute(builder: (_) => const VouchersScreen()));
    }
  }

  /// First break happens a little sooner than the repeats after it, so
  /// the very first interruption still arrives around the same spot it
  /// always did (after 4 grid tiles) regardless of how long the feed
  /// ends up being.
  static const _firstShelfBreak = 4;
  static const _shelfBreakInterval = 8;

  /// Builds the shelf as alternating grid chunks and wide "interrupt"
  /// tiles: chunk, wide tile, chunk, wide tile, ... for as long as
  /// [products] lasts, instead of stopping after a single break. Corner
  /// alternation on the grid tiles (via `_gridTile`'s index) stays
  /// continuous across every break so two adjacent tiles never lean the
  /// same way just because a wide tile sits between them.
  List<Widget> _buildShelfSlivers(List<Product> products) {
    final slivers = <Widget>[];
    var start = 0;
    var nextBreak = _firstShelfBreak;
    var gridIndex = 0;
    var isFirstChunk = true;

    while (start < products.length) {
      final chunkEnd = nextBreak.clamp(start, products.length);
      final chunk = products.sublist(start, chunkEnd);

      if (chunk.isNotEmpty) {
        final chunkStart = gridIndex;
        slivers.add(
          SliverPadding(
            padding: EdgeInsets.fromLTRB(20, isFirstChunk ? 0 : 16, 20, 0),
            sliver: SliverGrid(
              gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 2,
                mainAxisSpacing: 16,
                crossAxisSpacing: 16,
                childAspectRatio: 0.68,
              ),
              delegate: SliverChildBuilderDelegate(
                (context, i) => _gridTile(chunk[i], chunkStart + i),
                childCount: chunk.length,
              ),
            ),
          ),
        );
        gridIndex += chunk.length;
        isFirstChunk = false;
      }

      start = chunkEnd;
      if (start >= products.length) break;

      final interrupt = products[start];
      slivers.add(
        SliverPadding(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 0),
          sliver: SliverToBoxAdapter(
            child: StaggeredReveal(
              index: gridIndex + 1,
              child: WideProductTile(
                product: interrupt,
                heroTag: 'home-${interrupt.id}',
                onTap: () => Navigator.of(context).push(
                  AppPageRoute(
                    builder: (_) => ProductDetailsScreen(
                      product: interrupt,
                      heroTag: 'home-${interrupt.id}',
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      );
      start += 1;
      gridIndex += 1;
      nextBreak = start + _shelfBreakInterval;
    }

    return slivers;
  }

  Widget _gridTile(Product product, int index) {
    return StaggeredReveal(
      index: index + 1,
      child: ProductCard(
        product: product,
        index: index,
        heroTag: 'home-${product.id}',
        onTap: () => Navigator.of(context).push(
          AppPageRoute(
            builder: (_) => ProductDetailsScreen(product: product, heroTag: 'home-${product.id}'),
          ),
        ),
      ),
    );
  }

  Widget _buildEmptyState(ThemeData theme) {
    return const EmptyState(
      icon: Icons.inventory_2_outlined,
      title: 'No products yet',
      subtitle: 'Check back soon',
    );
  }
}
