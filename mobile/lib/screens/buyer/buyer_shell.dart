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
import '../../state/notification_state.dart';
import '../../state/chat_state.dart';
import '../../state/review_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/microcopy.dart';
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
import 'widgets/editorial_hero.dart';
import 'widgets/featured_deck.dart';
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
  late int _lastOrdersTabRequest;

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
    _lastOrdersTabRequest = context.read<OrderState>().ordersTabRequests;
    // Startup can fetch orders before a bearer token exists. Entering the
    // authenticated shell must replace that cached failure with fresh history.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      context.read<OrderState>().load();
      context.read<ReviewState>().load().catchError((Object error) {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Your reviews could not be loaded. Please sign in again to retry.',
            ),
          ),
        );
      });
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
    final orderState = context.watch<OrderState>();
    if (_lastOrdersTabRequest != orderState.ordersTabRequests) {
      _lastOrdersTabRequest = orderState.ordersTabRequests;
      _index = 3;
    }
    final orders = orderState.resolvedOrders(context.watch<CourierState>());
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
      const NavBarItem(
        glyph: NavGlyph.categories,
        label: 'Categories',
        shortLabel: 'Browse',
      ),
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
            child: SearchScreen(
              focusRequests: _searchFocusRequests,
              onBrowseCategories: () => _onTabTap(2),
            ),
          ),
          KeyedSubtree(key: _tabKeys[2], child: const CategoriesScreen()),
          KeyedSubtree(
            key: _tabKeys[3],
            child: OrdersStatusScreen(onBrowseProducts: () => _onTabTap(0)),
          ),
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

  static List<Product> _sortDeterministic(List<Product> products) {
    final list = List<Product>.from(products);
    list.sort((a, b) {
      final cmp = b.rating.compareTo(a.rating);
      if (cmp != 0) return cmp;
      return a.id.compareTo(b.id);
    });
    return list;
  }

  /// The band color for the "Style Edit" section. Uses surface at full
  /// opacity so it reads as a distinct zone against the background — at
  /// partial alpha it was blending into the ground invisibly.
  ///
  /// A getter rather than a `static final`: the palette resolves at
  /// runtime now, and a cached field would freeze whichever mode was
  /// active on the first build.
  Color get _bandColor => AppColors.surface;

  Widget _buildCategoryQuickLinks(BuildContext context, ThemeData theme) {
    final catalog = context.watch<CatalogState>();
    final categories = catalog.categories.where((c) => c != 'All').toList();
    if (categories.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.s8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.s20),
            child: Wrap(
              alignment: WrapAlignment.spaceBetween,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text('CATEGORIES', style: AppType.eyebrow),
                TextButton(
                  style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
                  onPressed: widget.onBrowseCategories,
                  child: const Text('See all categories'),
                ),
              ],
            ),
          ),
          SizedBox(
            height: 52 + MediaQuery.textScalerOf(context).scale(12) * 3.8,
            child: ListView.separated(
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.s20),
              scrollDirection: Axis.horizontal,
              itemCount: categories.length,
              separatorBuilder: (_, index) =>
                  const SizedBox(width: AppSpacing.s12),
              itemBuilder: (context, i) {
                final cat = categories[i];
                return InkWell(
                  borderRadius: BorderRadius.circular(8),
                  onTap: () => Navigator.of(context).push(
                    AppPageRoute(
                      builder: (_) => CategoryProductsScreen(category: cat),
                    ),
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      SizedBox(
                        width: 78,
                        height: 46,
                        child: ClipRRect(
                          borderRadius: const BorderRadius.only(
                            topLeft: Radius.circular(17),
                            topRight: Radius.circular(5),
                            bottomRight: Radius.circular(8),
                          ),
                          child: CategoryArtwork(category: cat),
                        ),
                      ),
                      const SizedBox(height: 5),
                      SizedBox(
                        width: 82,
                        child: Text(
                          cat,
                          textAlign: TextAlign.center,
                          maxLines: 3,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: AppColors.textPrimary,
                            fontWeight: FontWeight.w500,
                            fontSize: 12,
                            height: 1.15,
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
    final auth = context.watch<AuthState>();
    final greeting = Microcopy.greeting(
      widget.now ?? DateTime.now(),
      name: auth.firstName ?? auth.name,
    ).toUpperCase();

    final catalog = context.watch<CatalogState>();
    final catalogProducts = _sortDeterministic(catalog.products);
    final features = EditorialHero.selectCategories(catalog.categories);
    final storyProduct = catalogProducts.isEmpty
        ? null
        : catalogProducts.firstWhere(
            (p) => p.sellerName != catalogProducts.first.sellerName,
            orElse: () => catalogProducts.first,
          );

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
                                        style: AppType.masthead.copyWith(
                                          fontSize: 42,
                                        ),
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
                        if (!kUseLiveApi ||
                            context.read<NotificationState?>()?.isLive == true)
                          const NotificationBell(),
                        if (FeatureFlags.chatLive ||
                            context.read<ChatState?>()?.liveRepository !=
                                null) ...[
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
                          color: AppColors.surfaceMuted,
                          border: Border.all(color: AppColors.divider),
                          borderRadius: BorderRadius.circular(10),
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
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 0),
                  child: EditorialHero(
                    key: ValueKey(features.join('\u0000')),
                    categories: features,
                    onBrowse: widget.onBrowseCategories,
                    onCategory: (category) => Navigator.of(context).push(
                      AppPageRoute(
                        builder: (_) =>
                            CategoryProductsScreen(category: category),
                      ),
                    ),
                  ),
                ),
              ),
              // ---- Category quick links ----------------------------
              SliverToBoxAdapter(
                child: EaseInFrame(
                  index: 3,
                  child: _buildCategoryQuickLinks(context, theme),
                ),
              ),
              // ---- Flash sale --------------------------------------
              SliverToBoxAdapter(
                child: EaseInFrame(index: 4, child: _FlashSaleSection()),
              ),
              const SliverToBoxAdapter(child: _VoucherStrip()),
              if (catalog.isLoading && catalogProducts.isEmpty)
                SliverPadding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 100),
                  sliver: SliverGrid(
                    gridDelegate: ProductCard.gridDelegate(context),
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
                ..._buildShelfSlivers(catalogProducts),
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
                      heroTagPrefix: 'home-featured',
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
                          'Explore two pieces from the catalogue.',
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
              gridDelegate: ProductCard.gridDelegate(context),
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

/// A shelf for catalogue products with a confirmed lower selling price.
class _FlashSaleSection extends StatelessWidget {
  const _FlashSaleSection();

  @override
  Widget build(BuildContext context) {
    final products = context
        .watch<CatalogState>()
        .products
        .where((product) => (product.compareAtPrice ?? 0) > product.price)
        .take(4)
        .toList();
    if (products.isEmpty) return const SizedBox.shrink();
    return Column(
      key: const ValueKey('buyer-sale-shelf'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Divider(height: 1, color: AppColors.divider),
              const SizedBox(height: 9),
              Text('THE SALE EDIT', style: AppType.eyebrow),
              const SizedBox(height: 4),
              Text(
                'On sale',
                style: AppType.sectionDisplay.copyWith(fontSize: 29),
              ),
            ],
          ),
        ),
        SizedBox(
          height: ProductCard.cardExtent(context),
          child: ListView.separated(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            scrollDirection: Axis.horizontal,
            itemCount: products.length,
            separatorBuilder: (_, _) => const SizedBox(width: 12),
            itemBuilder: (context, index) {
              final product = products[index];
              final tag = 'flash-${product.id}';
              return SizedBox(
                width: index == 0 ? 202 : 162,
                child: ProductCard(
                  promotional: true,
                  index: index,
                  product: product,
                  heroTag: tag,
                  onTap: () => Navigator.of(context).push(
                    AppPageRoute(
                      builder: (_) =>
                          ProductDetailsScreen(product: product, heroTag: tag),
                    ),
                  ),
                ),
              );
            },
          ),
        ),
      ],
    );
  }
}

/// A compact route to the existing voucher list.
class _VoucherStrip extends StatelessWidget {
  const _VoucherStrip();

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final vouchers = context.watch<CartState>().vouchers;

    if (vouchers.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
      child: ListTile(
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(8),
          side: BorderSide(color: AppColors.divider),
        ),
        leading: const Icon(Icons.local_offer_outlined),
        title: const Text('Browse vouchers'),
        trailing: const Icon(Icons.arrow_forward, size: 18),
        onTap: () => Navigator.of(
          context,
        ).push(AppPageRoute(builder: (_) => const VouchersScreen())),
      ),
    );
  }
}
