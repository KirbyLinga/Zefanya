// lib/screens/buyer/buyer_shell.dart
//
// Top-level navigation shell for the Buyer app: Home, Search,
// Categories, Orders, Profile — behind the floating rounded nav bar.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/dev_flags.dart' show FeatureFlags, kUseLiveApi;
import '../../data/mock_data.dart';
import '../../state/auth_state.dart';
import '../../state/cart_state.dart';
import '../../state/catalog_state.dart';
import '../../state/courier_state.dart';
import '../../state/order_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/microcopy.dart';
import '../../utils/money_format.dart';
import '../../utils/scroll_to_top.dart';
import '../../widgets/cart_button.dart';
import '../../widgets/chat_inbox_button.dart';
import '../../widgets/notification_bell.dart';
import '../../widgets/floating_nav_bar.dart';
import '../../widgets/motion/ease_in_frame.dart';
import '../../widgets/motion/fade_through.dart';
import '../../widgets/nav_icons.dart';
import '../shared/profile_screen.dart';
import 'categories_screen.dart';
import 'category_products_screen.dart';
import 'orders_status_screen.dart';
import 'product_details_screen.dart';
import 'search_screen.dart';
import 'vouchers_screen.dart';
import 'widgets/product_card.dart';
import 'widgets/featured_deck.dart';
import 'widgets/promo_carousel.dart';
import 'widgets/product_stack_showcase.dart';
import 'widgets/seller_story_card.dart';
import 'widgets/recently_viewed_rail.dart';
import 'widgets/saved_rail.dart';
import '../../widgets/shimmer_box.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/zefanya_mark.dart';

class BuyerShell extends StatefulWidget {
  const BuyerShell({super.key, this.now});

  /// Pins the greeting's time of day for deterministic screenshots.
  ///
  /// The greeting is real clock copy in production; a golden that renders
  /// "GOOD MORNING" before noon and "GOOD EVENING" after 6 PM is a test
  /// that passes or fails with the wall clock. Tests pass a fixed moment;
  /// the app leaves this null.
  final DateTime? now;

  @override
  State<BuyerShell> createState() => _BuyerShellState();
}

class _BuyerShellState extends State<BuyerShell> {
  int _index = 0;

  /// Bumped to focus the Search tab's field — see SearchScreen.focusRequests.
  final _searchFocusRequests = ValueNotifier<int>(0);

  /// One key per tab so a re-tap can find that tab's own subtree and
  /// scroll its live positions to the top — see scroll_to_top.dart.
  /// A shared controller per tab would multi-attach on the Orders tab,
  /// whose TabBarView keeps two lists mounted mid-swipe.
  final _tabKeys = List.generate(5, (_) => GlobalKey());

  @override
  void initState() {
    super.initState();
    // Startup can fetch orders before a bearer token exists. Entering the
    // authenticated shell must replace that cached failure with fresh history.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) context.read<OrderState>().load();
    });
  }

  @override
  void dispose() {
    _searchFocusRequests.dispose();
    super.dispose();
  }

  void _onTabTap(int i) {
    // Re-tap the active tab: scroll that tab's own live positions to
    // the top. A tab switch only changes the index — offsets preserved.
    if (i == _index) {
      final context = _tabKeys[i].currentContext;
      if (context != null) scrollTabToTop(context);
      return;
    }
    setState(() => _index = i);
    // Deliberate tap on the Search tab: raise the keyboard, Shopee-style.
    // Returns from pushed routes (e.g. back from a product page) don't
    // pass through here — IndexedStack just reveals the mounted tab —
    // so this can't re-raise the keyboard over a half-typed query.
    if (i == 1) _searchFocusRequests.value++;
  }

  /// Home's search bar is a doorway, not a second search UI: jump to the
  /// Search tab and put the cursor in its field.
  void _openSearch() {
    setState(() => _index = 1);
    _searchFocusRequests.value++;
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    // Count orders that have moved to a new status since last viewed.
    // "In transit" and "out for delivery" are the two stages the buyer
    // actively wants to know about — toShip is expected, toRate is done.
    final orders = context.watch<OrderState>().resolvedOrders(
      context.watch<CourierState>(),
    );
    final activeOrders = orders
        .where(
          (o) =>
              o.status == OrderStatus.inTransit ||
              o.status == OrderStatus.outForDelivery,
        )
        .length;

    final items = [
      const NavBarItem(glyph: NavGlyph.home, label: 'Home'),
      const NavBarItem(glyph: NavGlyph.search, label: 'Search'),
      const NavBarItem(glyph: NavGlyph.categories, label: 'Categories'),
      NavBarItem(
        glyph: NavGlyph.orders,
        label: 'Orders',
        badgeCount: activeOrders,
      ),
      const NavBarItem(glyph: NavGlyph.profile, label: 'Profile'),
    ];

    return Scaffold(
      extendBody: true,
      body: FadeThroughIndexedStack(
        index: _index,
        children: [
          // isActive: Home stays mounted under the other tabs (IndexedStack),
          // so it has to be told when it isn't the one on screen.
          // Each tab carries a key so a re-tap can scroll that tab's own
          // live positions — see scroll_to_top.dart.
          KeyedSubtree(
            key: _tabKeys[0],
            child: _BuyerHomeScreen(
              isActive: _index == 0,
              onSearchTap: _openSearch,
              onBrowseCategories: () => _onTabTap(2),
              now: widget.now,
            ),
          ),
          KeyedSubtree(
            key: _tabKeys[1],
            child: SearchScreen(focusRequests: _searchFocusRequests),
          ),
          KeyedSubtree(key: _tabKeys[2], child: const CategoriesScreen()),
          KeyedSubtree(key: _tabKeys[3], child: const OrdersStatusScreen()),
          KeyedSubtree(
            key: _tabKeys[4],
            child: const ProfileScreen(title: 'Buyer Profile'),
          ),
        ],
      ),
      bottomNavigationBar: FloatingNavBar(
        items: items,
        currentIndex: _index,
        onTap: _onTabTap,
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
  const _BuyerHomeScreen({
    required this.isActive,
    required this.onSearchTap,
    required this.onBrowseCategories,
    this.now,
  });

  /// False while another tab is showing — pauses the promo banner.
  final bool isActive;

  /// Tapping the search bar hands off to the Search tab.
  final VoidCallback onSearchTap;

  /// The deck end card's "Browse categories" — jumps to Categories.
  final VoidCallback onBrowseCategories;

  /// Fixed moment for the greeting in golden tests; null uses the clock.
  final DateTime? now;

  @override
  State<_BuyerHomeScreen> createState() => _BuyerHomeScreenState();
}

class _BuyerHomeScreenState extends State<_BuyerHomeScreen> {
  bool _isLoading = true;

  // Which product leads (featured tile) and which pair shows in the
  // Style Edit stack — picked once per app launch instead of always leading with
  // the same product. A fixed selection made every screenshot and every scroll
  // during development show the identical hero no matter what else changed, which
  // reads as staleness on its own, separate from anything actually wrong with the
  // design.
  //
  // Read from `CatalogState`, not `mockProducts`: this is the home grid, and in a
  // live build the catalogue is the server's. `read` rather than `watch` because
  // the pick is deliberately frozen for the session — a re-shuffle mid-scroll
  // would move the hero under the person's thumb.
  late final List<Product> _shuffled;

  /// Drives "Shop the shelf" from the deck's end card: scrolls Home
  /// down to The Shelf. Owned here (not in the deck) so the deck stays
  /// a pure presentational widget — it reports taps, Home scrolls.
  late final ScrollController _scrollController = ScrollController();
  final _shelfKey = GlobalKey();

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  /// The seller featured in the "From the maker" card. Chosen from a
  /// different shop than the cover story's, so the same seller (and its
  /// product) doesn't headline two adjacent sections of one scroll.
  late final Product? _storyProduct;

  static List<Product> _sortDeterministic(List<Product> products) {
    final list = List<Product>.from(products);
    list.sort((a, b) {
      final cmp = b.rating.compareTo(a.rating);
      if (cmp != 0) return cmp;
      return a.id.compareTo(b.id);
    });
    return list;
  }

  @override
  void initState() {
    super.initState();
    _shuffled = _sortDeterministic(context.read<CatalogState>().products);
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

  /// The band color for the "Style Edit" section. Uses surface at full
  /// opacity so it reads as a distinct zone against the background — at
  /// partial alpha it was blending into the ground invisibly.
  ///
  /// A getter rather than a `static final`: the palette resolves at
  /// runtime now, and a cached field would freeze whichever mode was
  /// active on the first build.
  Color get _bandColor => AppColors.surface;

  Widget _buildCategoryQuickLinks(
    BuildContext context,
    ThemeData theme,
    AppThemeExtension ext,
  ) {
    final catalog = context.watch<CatalogState>();
    final categories = catalog.categories.where((c) => c != 'All').toList();
    if (categories.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.fromLTRB(0, AppSpacing.s4, 0, AppSpacing.s16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.s20),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text('CATEGORIES', style: AppType.eyebrow),
                ConstrainedBox(
                  constraints: const BoxConstraints(minHeight: AppSpacing.s48),
                  child: Container(
                    alignment: Alignment.centerRight,
                    child: GestureDetector(
                      behavior: HitTestBehavior.opaque,
                      onTap: widget.onBrowseCategories,
                      child: Text(
                        'See all categories',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: AppColors.primaryDark,
                          fontWeight: FontWeight.w600,
                          fontSize: 12,
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.s12),
          SizedBox(
            height: 124,
            child: ListView.separated(
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.s20),
              scrollDirection: Axis.horizontal,
              itemCount: categories.length,
              separatorBuilder: (_, index) =>
                  const SizedBox(width: AppSpacing.s12),
              itemBuilder: (context, i) {
                final cat = categories[i];
                final icon = categoryIcons[cat] ?? Icons.category_outlined;
                return GestureDetector(
                  behavior: HitTestBehavior.opaque,
                  onTap: () => Navigator.of(context).push(
                    AppPageRoute(
                      builder: (_) => CategoryProductsScreen(category: cat),
                    ),
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Container(
                        width: 56,
                        height: 56,
                        decoration: BoxDecoration(
                          color: AppColors.surface,
                          borderRadius: BorderRadius.circular(ext.cardRadius),
                          border: Border.all(color: AppColors.divider),
                        ),
                        child: Icon(
                          icon,
                          size: 24,
                          color: AppColors.primaryDark,
                        ),
                      ),
                      const SizedBox(height: AppSpacing.s8),
                      SizedBox(
                        width: 64,
                        child: Text(
                          cat,
                          textAlign: TextAlign.center,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: AppColors.textPrimary,
                            fontWeight: FontWeight.w500,
                            fontSize: 12,
                          ),
                        ),
                      ),
                    ],
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final auth = context.watch<AuthState>();
    final greeting = Microcopy.greeting(
      widget.now ?? DateTime.now(),
      name: auth.firstName ?? auth.name,
    ).toUpperCase();

    final catalogProducts = _shuffled.isNotEmpty
        ? _shuffled
        : _sortDeterministic(context.watch<CatalogState>().products);
    final gridProducts = catalogProducts;
    final storyProduct =
        _storyProduct ??
        (catalogProducts.isEmpty
            ? null
            : catalogProducts.firstWhere(
                (p) => p.sellerName != catalogProducts.first.sellerName,
                orElse: () => catalogProducts.first,
              ));

    return Scaffold(
      backgroundColor: Colors
          .transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      body: SafeArea(
        child: RefreshIndicator(
          color: AppColors.primaryDark,
          onRefresh: () => context.read<CatalogState>().refresh(),
          child: CustomScrollView(
            controller: _scrollController,
            slivers: [
              // ---- Masthead ----------------------------------------
              SliverToBoxAdapter(
                child: EaseInFrame(
                  index: 0,
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(
                      AppSpacing.s20,
                      AppSpacing.s16,
                      AppSpacing.s12,
                      AppSpacing.s4,
                    ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(greeting, style: AppType.eyebrow),
                              const SizedBox(height: AppSpacing.s8),
                              Row(
                                crossAxisAlignment: CrossAxisAlignment.center,
                                children: [
                                  Flexible(
                                    child: FittedBox(
                                      fit: BoxFit.scaleDown,
                                      alignment: Alignment.centerLeft,
                                      child: Text(
                                        'Zefanya',
                                        style: AppType.masthead,
                                      ),
                                    ),
                                  ),
                                  const SizedBox(width: AppSpacing.s8),
                                  Padding(
                                    padding: const EdgeInsets.only(
                                      top: AppSpacing.s8,
                                    ),
                                    child: ZefanyaMark(
                                      size: 20,
                                      color: AppColors.primaryDark.withValues(
                                        alpha: 0.6,
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ),
                        // The bell has no backend yet; showing it in a live
                        // build would promise notifications the server cannot
                        // send. It returns with S6.9.
                        if (!kUseLiveApi) const NotificationBell(),
                        if (FeatureFlags.chatLive) ...[
                          const SizedBox(width: AppSpacing.s4),
                          const ChatInboxButton(),
                        ],
                        const SizedBox(width: AppSpacing.s4),
                        const CartButton(),
                      ],
                    ),
                  ),
                ),
              ),
              // ---- Search (under masthead, visible filled ground, 48dp min height) ----
              SliverToBoxAdapter(
                child: EaseInFrame(
                  index: 1,
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(
                      AppSpacing.s20,
                      AppSpacing.s16,
                      AppSpacing.s20,
                      AppSpacing.s12,
                    ),
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(
                        minHeight: AppSpacing.s48,
                      ),
                      child: Container(
                        constraints: const BoxConstraints(
                          minHeight: AppSpacing.s48,
                        ),
                        decoration: BoxDecoration(
                          color: AppColors.primary.withValues(alpha: 0.18),
                          borderRadius: BorderRadius.circular(ext.pillRadius),
                        ),
                        child: TextField(
                          readOnly: true,
                          onTap: widget.onSearchTap,
                          style: theme.textTheme.bodyMedium,
                          decoration: InputDecoration(
                            filled: false,
                            isDense: true,
                            contentPadding: const EdgeInsets.symmetric(
                              horizontal: 16,
                              vertical: 14,
                            ),
                            hintText: 'Search products, brands...',
                            hintStyle: theme.textTheme.bodyMedium?.copyWith(
                              // textSecondary sat at ~3:1 on the dark-mode
                              // rose wash. The hint is the only label the
                              // field has, so it uses the primary ink.
                              color: AppColors.textPrimary,
                            ),
                            prefixIcon: Icon(
                              Icons.search,
                              size: 20,
                              color: AppColors.primaryDark,
                            ),
                            border: InputBorder.none,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
              // ---- Promo carousel ----------------------------------
              SliverToBoxAdapter(
                child: EaseInFrame(
                  index: 2,
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(0, 0, 0, AppSpacing.s16),
                    child: PromoCarousel(
                      autoAdvance: widget.isActive,
                      onTapSlide: _onPromoTap,
                    ),
                  ),
                ),
              ),
              // ---- Voucher strip -----------------------------------
              SliverToBoxAdapter(
                child: EaseInFrame(
                  index: 3,
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(
                      AppSpacing.s20,
                      0,
                      AppSpacing.s20,
                      AppSpacing.s16,
                    ),
                    child: Row(children: [Expanded(child: _VoucherStrip())]),
                  ),
                ),
              ),
              // ---- Category quick links ----------------------------
              SliverToBoxAdapter(
                child: EaseInFrame(
                  index: 3,
                  child: _buildCategoryQuickLinks(context, theme, ext),
                ),
              ),
              // ---- Flash sale --------------------------------------
              SliverToBoxAdapter(
                child: EaseInFrame(index: 4, child: _FlashSaleSection()),
              ),
              if (_isLoading)
                SliverPadding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 100),
                  sliver: SliverGrid(
                    gridDelegate:
                        const SliverGridDelegateWithFixedCrossAxisCount(
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
                            borderRadius: editorialRadius(i, ShapeTier.card),
                          ),
                        ),
                      ),
                      childCount: 4,
                    ),
                  ),
                )
              else if (catalogProducts.isEmpty)
                SliverToBoxAdapter(child: _buildEmptyState(context, theme))
              else ...[
                // ---- "The Shelf" product grid (renamed "Just for you") ----
                SliverToBoxAdapter(
                  child: Padding(
                    key: _shelfKey,
                    padding: const EdgeInsets.fromLTRB(
                      AppSpacing.s20,
                      AppSpacing.s8,
                      AppSpacing.s20,
                      AppSpacing.s16,
                    ),
                    child: Text('Just for you', style: AppType.sectionDisplay),
                  ),
                ),
                ..._buildShelfSlivers(gridProducts),
                // ---- Recently viewed ---------------------------------
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.only(top: 22),
                    child: RecentlyViewedRail(
                      excludeProductId: catalogProducts.first.id,
                      onTapProduct: (product) => Navigator.of(context).push(
                        AppPageRoute(
                          builder: (_) =>
                              ProductDetailsScreen(product: product),
                        ),
                      ),
                    ),
                  ),
                ),
                // ---- Saved for later ---------------------------------
                SliverToBoxAdapter(
                  child: SavedRail(
                    onTapProduct: (product) => Navigator.of(context).push(
                      AppPageRoute(
                        builder: (_) => ProductDetailsScreen(product: product),
                      ),
                    ),
                  ),
                ),
                // ---- Featured rail / deck ----------------------------
                SliverPadding(
                  padding: const EdgeInsets.only(top: 16),
                  sliver: SliverToBoxAdapter(
                    child: FeaturedDeck(
                      products: catalogProducts.take(5).toList(),
                      heroTagPrefix: 'home',
                      isActive: widget.isActive,
                    ),
                  ),
                ),
                // ---- Style Edit, on its own ground -------------------
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
                        Text('Style Edit', style: theme.textTheme.titleLarge),
                        const SizedBox(height: 8),
                        Text(
                          'Two pieces that keep ending up in the same basket.',
                          style: theme.textTheme.bodyMedium?.copyWith(
                            color: AppColors.textSecondary,
                          ),
                        ),
                        const SizedBox(height: 16),
                        if (catalogProducts.length > 3)
                          ProductStackShowcase(
                            back: catalogProducts[2],
                            front: catalogProducts[3],
                            heroTagPrefix: 'home-stack',
                          ),
                      ],
                    ),
                  ),
                ),
                SliverToBoxAdapter(
                  child: CurvedSectionBreak(
                    color: _bandColor,
                    height: 46,
                    fillTop: true,
                  ),
                ),
                // ---- Seller story ------------------------------------
                SliverPadding(
                  padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
                  sliver: SliverToBoxAdapter(
                    child: SellerStoryCard(
                      product: storyProduct ?? catalogProducts.first,
                      onTap: () => Navigator.of(context).push(
                        AppPageRoute(
                          builder: (_) => ProductDetailsScreen(
                            product: storyProduct ?? catalogProducts.first,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
                const SliverToBoxAdapter(child: SizedBox(height: 100)),
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// Where each promo slide leads. Keyed to the order of
  /// `mockPromoSlides` in data/mock_data.dart: new arrivals, the Home &
  /// Living sale, shipping.
  ///
  /// Still an index rather than a field on the slide, on purpose:
  /// navigation targets are app structure, not campaign content, and
  /// handing the data layer a route would mean a marketer could aim a
  /// banner at any screen in the app. If slides ever reorder, this and the
  /// list move together — which is why both sides say so.
  void _onPromoTap(int index) {
    final navigator = Navigator.of(context);
    if (index == 0) {
      navigator.push(
        AppPageRoute(
          builder: (_) => const CategoryProductsScreen(category: 'Fashion'),
        ),
      );
    } else if (index == 1) {
      navigator.push(
        AppPageRoute(
          builder: (_) =>
              const CategoryProductsScreen(category: 'Home & Living'),
        ),
      );
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
            child: EaseInFrame(
              index: (gridIndex + 1).clamp(0, 5),
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
    return EaseInFrame(
      index: (index + 1).clamp(0, 5),
      child: ProductCard(
        product: product,
        index: index,
        heroTag: 'home-${product.id}',
        onTap: () => Navigator.of(context).push(
          AppPageRoute(
            builder: (_) => ProductDetailsScreen(
              product: product,
              heroTag: 'home-${product.id}',
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildEmptyState(BuildContext context, ThemeData theme) {
    final catalog = context.watch<CatalogState>();
    if (catalog.loadFailure != null) {
      return EmptyState(
        icon: Icons.wifi_off_outlined,
        title: 'Could not load products',
        subtitle: 'Please check your connection and try again.',
        action: OutlinedButton(
          onPressed: () => context.read<CatalogState>().refresh(),
          child: const Text('Try again'),
        ),
      );
    }
    return EmptyState(
      icon: Icons.inventory_2_outlined,
      title: 'No products yet',
      subtitle: 'Check back soon for curated local crafts.',
      action: OutlinedButton(
        onPressed: () => context.read<CatalogState>().refresh(),
        child: const Text('Refresh'),
      ),
    );
  }
}

/// Flash-sale banner: a time-boxed section with a countdown and sold-count
/// progress bar. Products are selected by having a [compareAtPrice] (on sale).
class _FlashSaleSection extends StatefulWidget {
  const _FlashSaleSection();

  @override
  State<_FlashSaleSection> createState() => _FlashSaleSectionState();
}

class _FlashSaleSectionState extends State<_FlashSaleSection> {
  /// Mock sale ends 48 hours from now. In live mode this comes from the API.
  final _saleEnds = DateTime.now().add(const Duration(hours: 48));

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);

    final catalog = context.watch<CatalogState>();
    final saleItems = catalog.products
        .where((p) => p.compareAtPrice != null && p.compareAtPrice! > p.price)
        .take(4)
        .toList();

    if (saleItems.isEmpty) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.s20,
            AppSpacing.s16,
            AppSpacing.s20,
            AppSpacing.s12,
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'FLASH SALE',
                    style: AppType.eyebrow.copyWith(color: AppColors.danger),
                  ),
                  const SizedBox(height: AppSpacing.s4),
                  Text(
                    'Ends soon',
                    style: theme.textTheme.titleMedium?.copyWith(
                      color: AppColors.textPrimary,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
              ConstrainedBox(
                constraints: const BoxConstraints(minHeight: AppSpacing.s48),
                child: Container(
                  alignment: Alignment.centerRight,
                  child: TextButton(
                    onPressed: () {},
                    child: Text(
                      'See all deals',
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: AppColors.primaryDark,
                        fontSize: 12,
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
        SizedBox(
          height: 268,
          child: _FlashSaleProductList(
            saleItems: saleItems,
            saleEnds: _saleEnds,
          ),
        ),
      ],
    );
  }
}

/// Flash-sale product cards with countdown timer and sold progress bar.
class _FlashSaleProductList extends StatelessWidget {
  const _FlashSaleProductList({
    required this.saleItems,
    required this.saleEnds,
  });

  final List<Product> saleItems;
  final DateTime saleEnds;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;

    return ListView.separated(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.s20),
      scrollDirection: Axis.horizontal,
      itemCount: saleItems.length,
      separatorBuilder: (_, i) => const SizedBox(width: AppSpacing.s12),
      itemBuilder: (context, i) {
        final product = saleItems[i];
        final originalPrice = product.compareAtPrice ?? product.price;
        final discountPct = originalPrice > 0
            ? ((1 - product.price / originalPrice) * 100).round()
            : 0;
        final soldProgress = (product.soldCount / 1000).clamp(0.0, 1.0);
        final timeLeft = saleEnds.difference(DateTime.now());
        final hours = timeLeft.inHours.remainder(24).toString().padLeft(2, '0');
        final minutes = timeLeft.inMinutes
            .remainder(60)
            .toString()
            .padLeft(2, '0');
        final seconds = timeLeft.inSeconds
            .remainder(60)
            .toString()
            .padLeft(2, '0');

        return GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTap: () => Navigator.of(context).push(
            AppPageRoute(
              builder: (_) => ProductDetailsScreen(
                product: product,
                heroTag: 'flash-${product.id}',
              ),
            ),
          ),
          child: Container(
            width: 180,
            decoration: BoxDecoration(
              color: AppColors.surfaceMuted,
              borderRadius: BorderRadius.circular(ext.cardRadius),
              border: Border.all(color: AppColors.divider),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Countdown timer
                Padding(
                  padding: const EdgeInsets.fromLTRB(
                    AppSpacing.s12,
                    AppSpacing.s8,
                    AppSpacing.s12,
                    AppSpacing.s4,
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.timer, size: 14, color: AppColors.danger),
                      const SizedBox(width: AppSpacing.s4),
                      Text(
                        '$hours:$minutes:$seconds',
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: AppColors.danger,
                          fontWeight: FontWeight.w700,
                          fontSize: 12,
                        ),
                      ),
                    ],
                  ),
                ),
                // Discount badge
                Padding(
                  padding: const EdgeInsets.fromLTRB(
                    AppSpacing.s12,
                    0,
                    AppSpacing.s12,
                    AppSpacing.s8,
                  ),
                  child: Container(
                    padding: const EdgeInsets.fromLTRB(
                      AppSpacing.s8,
                      AppSpacing.s4,
                      AppSpacing.s8,
                      AppSpacing.s4,
                    ),
                    decoration: BoxDecoration(
                      color: AppColors.danger.withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(AppSpacing.s8),
                    ),
                    child: Text(
                      '-$discountPct%',
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: AppColors.danger,
                        fontWeight: FontWeight.w700,
                        fontSize: 12,
                      ),
                    ),
                  ),
                ),
                // Product image
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(
                      AppSpacing.s12,
                      0,
                      AppSpacing.s12,
                      AppSpacing.s8,
                    ),
                    child: ProductImage(product: product),
                  ),
                ),
                // Product name
                Padding(
                  padding: const EdgeInsets.fromLTRB(
                    AppSpacing.s12,
                    0,
                    AppSpacing.s12,
                    AppSpacing.s4,
                  ),
                  child: Text(
                    product.name,
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: AppColors.textPrimary,
                      fontSize: 12,
                    ),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                // Price row
                Padding(
                  padding: const EdgeInsets.fromLTRB(
                    AppSpacing.s12,
                    0,
                    AppSpacing.s12,
                    AppSpacing.s4,
                  ),
                  child: Row(
                    children: [
                      Text(
                        formatPeso(product.price),
                        style: theme.textTheme.labelMedium?.copyWith(
                          color: AppColors.textPrimary,
                          fontWeight: FontWeight.w700,
                          fontSize: 13,
                        ),
                      ),
                      const SizedBox(width: AppSpacing.s8),
                      Text(
                        formatPeso(originalPrice),
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: AppColors.textSecondary,
                          decoration: TextDecoration.lineThrough,
                          fontSize: 12,
                        ),
                      ),
                    ],
                  ),
                ),
                // Sold progress bar
                Padding(
                  padding: const EdgeInsets.fromLTRB(
                    AppSpacing.s12,
                    0,
                    AppSpacing.s12,
                    AppSpacing.s12,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            '${product.soldCount} sold',
                            style: theme.textTheme.labelSmall?.copyWith(
                              color: AppColors.textSecondary,
                              fontSize: 12,
                            ),
                          ),
                          Text(
                            '${(soldProgress * 100).round()}%',
                            style: theme.textTheme.labelSmall?.copyWith(
                              color: AppColors.textSecondary,
                              fontSize: 12,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: AppSpacing.s4),
                      ClipRRect(
                        borderRadius: BorderRadius.circular(AppSpacing.s4),
                        child: LinearProgressIndicator(
                          value: soldProgress,
                          minHeight: AppSpacing.s8,
                          backgroundColor: AppColors.divider,
                          valueColor: AlwaysStoppedAnimation(
                            AppColors.tertiary,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}

/// A horizontally scrollable strip of active vouchers displayed on Home.
class _VoucherStrip extends StatelessWidget {
  const _VoucherStrip();

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final vouchers = context.watch<CartState>().vouchers;

    if (vouchers.isEmpty) return const SizedBox.shrink();

    return SizedBox(
      height: 128,
      child: ListView.separated(
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.s20),
        scrollDirection: Axis.horizontal,
        itemCount: vouchers.length,
        separatorBuilder: (_, i) => const SizedBox(width: AppSpacing.s12),
        itemBuilder: (context, i) {
          final voucher = vouchers[i];
          return Container(
            width: 220,
            padding: const EdgeInsets.all(AppSpacing.s16),
            decoration: BoxDecoration(
              color: AppColors.surfaceMuted,
              borderRadius: BorderRadius.circular(ext.cardRadius),
              border: Border.all(color: AppColors.divider),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Text(
                        voucher.title.isNotEmpty ? voucher.title : voucher.code,
                        style: theme.textTheme.labelMedium?.copyWith(
                          color: AppColors.textPrimary,
                          fontWeight: FontWeight.w700,
                          fontSize: 13,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    const SizedBox(width: AppSpacing.s8),
                    Container(
                      padding: const EdgeInsets.fromLTRB(
                        AppSpacing.s8,
                        AppSpacing.s4,
                        AppSpacing.s8,
                        AppSpacing.s4,
                      ),
                      decoration: BoxDecoration(
                        color: AppColors.tertiary.withValues(alpha: 0.15),
                        borderRadius: BorderRadius.circular(AppSpacing.s8),
                      ),
                      child: Text(
                        voucher.discountLabel,
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: AppColors.tertiary,
                          fontWeight: FontWeight.w700,
                          fontSize: 12,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.s8),
                Text(
                  'Min spend ${formatPeso(voucher.minSpend)}',
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: AppColors.textSecondary,
                    fontSize: 12,
                  ),
                ),
                const SizedBox(height: AppSpacing.s4),
                Text(
                  voucher.expiryLabel,
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: AppColors.textSecondary,
                    fontSize: 12,
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
