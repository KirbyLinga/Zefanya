// lib/screens/buyer/product_details_screen.dart
//
// Buyer-facing Product Details screen, fully styled with the
// "Zefanya" theme tokens (see theme/app_theme.dart). Renders
// whichever Product is passed in (see data/mock_data.dart) rather
// than a single hardcoded item, and Add to Cart / Buy Now write to
// the shared CartState so Cart/Checkout reflect the same data.

import 'dart:io';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';
import '../../config/dev_flags.dart' show FeatureFlags;
import '../../config/pricing.dart';
import '../../data/mock_data.dart';
import '../../state/cart_state.dart';
import '../../state/courier_state.dart';
import '../../state/order_state.dart';
import '../../state/recently_viewed_state.dart';
import '../../state/review_state.dart';
import '../../theme/app_theme.dart';
import '../shared/chat_screen.dart';
import '../../state/chat_state.dart';
import '../../data/api/api_exception.dart';
import '../../state/catalog_state.dart';
import '../../widgets/inline_error_notice.dart';
import 'cart_screen.dart';
import 'checkout_screen.dart';
import 'review_screen.dart';
import 'seller_screen.dart';
import 'widgets/product_card.dart';
import 'vouchers_screen.dart';
import '../../utils/microcopy.dart';
import '../../utils/money_format.dart';
import '../../utils/motion.dart';
import '../../utils/product_link.dart';
import '../../utils/timed_snackbar.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/cart_flight.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/heart_button.dart';
import '../../widgets/motion/ease_in_frame.dart';

class ProductDetailsScreen extends StatefulWidget {
  const ProductDetailsScreen({super.key, required this.product, this.heroTag});

  final Product product;

  /// Must match the `heroTag` used on the ProductCard that navigated
  /// here (see widgets/product_card.dart for why). Null means "no
  /// shared-element flight" — used when there wasn't a tappable card
  /// with an image to fly from (e.g. a future deep link).
  final String? heroTag;

  @override
  State<ProductDetailsScreen> createState() => _ProductDetailsScreenState();
}

class _ProductDetailsScreenState extends State<ProductDetailsScreen> {
  int _quantity = 1;

  /// Chosen value per [VariationType.name]. Rich products start empty (both
  /// controls must be answered); legacy one-dimensional products preselect
  /// their first value to keep the old chip/colour behaviour.
  final Map<String, String> _selectedOptions = {};

  /// Origin of the add-to-cart flight — the gallery image is what the
  /// user is looking at when they tap Add to Cart, so that's the thing
  /// that should visibly travel.
  final GlobalKey _galleryKey = GlobalKey();

  /// Which photo the gallery is showing. Driven by the pager and read by
  /// the dot indicator — nothing else, so it's state rather than anything
  /// derived.
  int _photoIndex = 0;

  final PageController _galleryController = PageController();

  /// True for the length of the cart-flight animation right after a tap.
  /// Without this, a second tap while the item is still flying added the
  /// quantity a second time (and, for Buy Now, pushed Checkout twice).
  bool _addCooldown = false;

  /// Seller descriptions can run long. Three lines is enough to judge the
  /// thing; the rest is a deliberate choice rather than something that pushes
  /// the price and actions off the screen.
  bool _descriptionExpanded = false;

  Product? _liveProduct;
  bool _loadingDetails = false;
  bool _usesLiveDetails = false;
  String? _detailsError;
  Product get _product => _liveProduct ?? widget.product;

  List<VariationType> get _groups => _product.optionGroups;

  bool get _hasAllSelections =>
      _groups.every((group) => _selectedOptions[group.name] != null);

  /// The chosen SKU, once every variation type has a value and the product
  /// actually carries per-combination data.
  ProductVariant? get _selectedSku {
    if (!_product.hasVariationMatrix || !_hasAllSelections) return null;
    return _product.variantForSelections(_selectedOptions);
  }

  /// The cart line's human-readable variant label. Empty for products with no
  /// variation controls; otherwise "Black / M" in the product's own order.
  String get _selectedVariant {
    if (_groups.isEmpty) return '';
    return [
      for (final group in _groups)
        if (_selectedOptions[group.name] != null) _selectedOptions[group.name]!,
    ].join(' / ');
  }

  double get _currentPrice => _selectedSku?.price ?? _product.price;

  int get _currentStock {
    if (_product.hasVariationMatrix && _hasAllSelections) {
      return _selectedSku?.stock ?? 0;
    }
    return _product.stock;
  }

  /// A rich product with no selection yet is not out of stock — the buyer has
  /// simply not told us which combination they want. Keeping the actions
  /// enabled is what lets the missing-selection message explain that.
  bool get _isOutOfStock {
    if (_product.hasVariationMatrix && !_hasAllSelections) return false;
    return _currentStock <= 0;
  }

  int get _purchasedQuantity =>
      context.read<OrderState>().purchasedQuantity(_product.id);

  int get _inCartQuantity =>
      context.read<CartState>().quantityInCart(_product.id);

  /// Units this buyer may still add to the cart.
  ///
  /// The limit is per product across all orders, so both order history and the
  /// current cart count. Buy Now only consults history — the cart line is not
  /// part of that purchase and is not cleared by it.
  int? _remainingForAdd() {
    final limit = _product.purchaseLimit;
    if (limit == null) return null;
    return math.max(0, limit - _purchasedQuantity - _inCartQuantity);
  }

  int? _remainingForBuyNow() {
    final limit = _product.purchaseLimit;
    if (limit == null) return null;
    return math.max(0, limit - _purchasedQuantity);
  }

  bool _checkPurchaseLimit({required bool forBuyNow}) {
    final limit = _product.purchaseLimit;
    if (limit == null) return true;
    final remaining = forBuyNow ? _remainingForBuyNow()! : _remainingForAdd()!;
    if (remaining <= 0) {
      showTimedSnackBar(
        context,
        SnackBar(
          content: Text(
            'You can buy at most $limit of this item per buyer. '
            'You have reached that limit.',
          ),
        ),
      );
      return false;
    }
    if (_quantity > remaining) {
      showTimedSnackBar(
        context,
        SnackBar(
          content: Text(
            'You can buy at most $limit of this item per buyer. '
            'Only $remaining more can be added.',
          ),
        ),
      );
      return false;
    }
    return true;
  }

  /// The selected SKU's colour image leads the gallery; the product's own
  /// photos follow, de-duplicated so a variant image that is also a catalogue
  /// photo does not appear twice.
  List<String> get _galleryPhotos {
    final variantImage = _selectedSku?.image;
    if (variantImage == null || variantImage.isEmpty) return _product.photos;
    return [
      variantImage,
      for (final photo in _product.photos)
        if (photo != variantImage) photo,
    ];
  }

  @override
  void initState() {
    super.initState();
    final groups = _groups;
    if (!_product.hasVariationMatrix &&
        groups.length == 1 &&
        groups.first.values.isNotEmpty) {
      _selectedOptions[groups.first.name] = groups.first.values.first;
    }
    _quantity = _currentStock == 0 ? 0 : 1;
    _loadDetails();
    // Deferred to post-frame: this notifies RecentlyViewedState, and
    // calling that during this screen's own initial build (rather than
    // after it) would trigger a rebuild of whatever's listening — Home,
    // in this case — while this screen is still mid-build.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) context.read<RecentlyViewedState>().recordView(_product.id);
    });
  }

  Future<void> _loadDetails() async {
    if (_loadingDetails) return;
    final request = context.read<CatalogState?>()?.fetchLiveProduct(
      widget.product.id,
    );
    if (request == null) return;
    setState(() {
      _usesLiveDetails = true;
      _loadingDetails = true;
      _detailsError = null;
    });
    try {
      final product = await request;
      if (!mounted) return;
      setState(() {
        _liveProduct = product;
        _detailsError = product == null
            ? 'This product is no longer available.'
            : null;
        _selectedOptions.clear();
        _quantity = _currentStock == 0 ? 0 : 1;
      });
    } on ApiException catch (error) {
      if (mounted) setState(() => _detailsError = error.message);
    } finally {
      if (mounted) setState(() => _loadingDetails = false);
    }
  }

  @override
  void dispose() {
    _galleryController.dispose();
    super.dispose();
  }

  void _incrementQty() {
    final stock = _currentStock;
    if (stock <= 0) return;
    final remaining = _remainingForAdd();
    if (remaining != null && remaining <= 0) {
      _checkPurchaseLimit(forBuyNow: false);
      return;
    }
    final cap = math.min(
      CartState.maxQuantityPerLine,
      math.min(stock, remaining ?? CartState.maxQuantityPerLine),
    );
    if (_quantity >= cap) {
      showTimedSnackBar(
        context,
        SnackBar(content: Text('Only $cap available per order.')),
      );
      return;
    }
    setState(() => _quantity++);
  }

  void _decrementQty() {
    if (_currentStock <= 0) return;
    if (_quantity > 1) setState(() => _quantity--);
  }

  /// Whether choosing [value] in [groupName] can still lead to an in-stock
  /// combination, given the controls *before* it. This is what makes Black/M
  /// disabled while Black/S, Black/L and White/M all stay available.
  ///
  /// Earlier groups are not constrained by later ones: with M already chosen,
  /// Black remains selectable and [_selectOption] clears M because Black/M is
  /// gone. That is the usual "pick colour, then size" flow and avoids trapping
  /// the buyer on a later control.
  bool _isOptionAvailable(String groupName, String value) {
    if (!_product.hasVariationMatrix) return true;
    final groupIndex = _groups.indexWhere((group) => group.name == groupName);
    for (final variant in _product.variantSkus) {
      if (variant.stock <= 0) continue;
      if (variant.selections[groupName] != value) continue;
      var matches = true;
      for (var i = 0; i < groupIndex; i++) {
        final group = _groups[i];
        final selected = _selectedOptions[group.name];
        if (selected == null) continue;
        if (variant.selections[group.name] != selected) {
          matches = false;
          break;
        }
      }
      if (matches) return true;
    }
    return false;
  }

  ProductVariant? _skuForSelections(Map<String, String> selections) {
    if (!_product.hasVariationMatrix) return null;
    if (!_groups.every((group) => selections[group.name] != null)) return null;
    return _product.variantForSelections(selections);
  }

  void _selectOption(String groupName, String value) {
    if (!_isOptionAvailable(groupName, value)) return;

    final next = Map<String, String>.of(_selectedOptions)..[groupName] = value;

    // Choosing one value can make another already-chosen value impossible
    // (e.g. M was selected, then the buyer chooses Black, which has no M in
    // stock). Drop the impossible side rather than leaving the screen on a
    // combination the buyer cannot add.
    if (_product.hasVariationMatrix) {
      for (final group in _groups) {
        if (group.name == groupName) continue;
        final other = next[group.name];
        if (other == null) continue;
        final canKeep = _product.variantSkus.any(
          (sku) =>
              sku.stock > 0 &&
              sku.selections[groupName] == value &&
              sku.selections[group.name] == other,
        );
        if (!canKeep) next.remove(group.name);
      }
    }

    final complete = _groups.every((group) => next[group.name] != null);
    final sku = _skuForSelections(next);
    final stock = _product.hasVariationMatrix
        ? (complete ? (sku?.stock ?? 0) : _product.stock)
        : _product.stock;

    setState(() {
      _selectedOptions
        ..clear()
        ..addAll(next);
      _photoIndex = 0;
      if (stock <= 0) {
        _quantity = 0;
      } else if (_quantity <= 0) {
        _quantity = 1;
      } else if (_quantity > stock) {
        _quantity = stock;
      }
    });

    // The selected colour's image is page one of the gallery; put the buyer
    // back on it instead of leaving them on a product photo for the old colour.
    if (_galleryController.hasClients) {
      _galleryController.jumpToPage(0);
    }
  }

  /// Shows the missing-variation message and returns false when the buyer has
  /// not answered every control. Called before either bottom action, so the
  /// requirement is identical for Add to Cart and Buy Now.
  bool _validateSelection() {
    if (_groups.isEmpty) return true;
    if (!_hasAllSelections) {
      showTimedSnackBar(
        context,
        const SnackBar(content: Text('Please select a variation')),
      );
      return false;
    }
    if (_product.hasVariationMatrix && _selectedSku == null) {
      showTimedSnackBar(
        context,
        const SnackBar(content: Text('This variation is unavailable')),
      );
      return false;
    }
    return true;
  }

  /// Returns false (and does nothing) if a previous tap is still inside
  /// its cooldown, so callers can skip whatever they were going to do
  /// next (e.g. Buy Now's navigation to Checkout).
  bool _addToCart({required bool showSnackBar}) {
    if (!_validateSelection()) return false;
    if (_product.sellerOnVacation) {
      showTimedSnackBar(
        context,
        const SnackBar(
          content: Text('This store is on vacation and is not taking orders.'),
        ),
      );
      return false;
    }
    if (_isOutOfStock) return false;
    if (!_checkPurchaseLimit(forBuyNow: false)) return false;
    if (_addCooldown) return false;
    setState(() => _addCooldown = true);
    Future.delayed(const Duration(milliseconds: 700), () {
      if (mounted) setState(() => _addCooldown = false);
    });

    final sku = _selectedSku;
    final outcome = context.read<CartState>().addItem(
      _product,
      variant: _selectedVariant,
      quantity: _quantity,
      unitPrice: sku?.price,
      variantStock: sku?.stock,
      variantImage: sku?.image,
    );

    if (outcome == CartAddOutcome.rejected) return false;

    // The product was already in the cart, so nothing new arrived. Flying a
    // second copy into the badge would say otherwise, and bumping the badge
    // would animate a number that did not change.
    if (outcome == CartAddOutcome.quantityUpdated) {
      if (showSnackBar) {
        showTimedSnackBar(
          context,
          SnackBar(
            content: const Text(Microcopy.quantityUpdated),
            persist: false,
          ),
        );
      }
      return true;
    }

    // Light impact on add — the same confirmation weight as copy-ID
    // and the nav tap, fired with the flight rather than the snackbar.
    HapticFeedback.lightImpact();

    // The signature moment: the product physically flies into the cart
    // icon (see widgets/cart_flight.dart). Fired before the SnackBar so
    // the two don't compete for the same beat.
    CartFlight.send(
      context,
      originKey: _galleryKey,
      thumbnail: ClipRRect(
        borderRadius: radiusOf(ShapeTier.card),
        child: ProductImage(
          product: _product,
          imageUrl: sku?.image,
          iconSize: 28,
        ),
      ),
    );

    if (!showSnackBar) return true;
    // showTimedSnackBar replaces any SnackBar already up and removes this
    // one itself after 3s. Flutter's built-in timeout is unreliable for
    // SnackBars with an action (persist defaults to true since 3.29), so
    // we don't depend on it.
    showTimedSnackBar(
      context,
      SnackBar(
        content: Text(
          Microcopy.addToCart(productName: _product.name, seed: _quantity),
        ),
        persist: false,
        action: SnackBarAction(
          label: 'View Cart',
          onPressed: () => Navigator.of(
            context,
          ).push(AppPageRoute(builder: (_) => const CartScreen())),
        ),
      ),
    );
    return true;
  }

  /// Checks out this line only. It does not add to the cart or change any
  /// existing cart selection; the checkout screen receives a detached line.
  void _buyNow() {
    if (!_validateSelection()) return;
    if (_product.sellerOnVacation) {
      showTimedSnackBar(
        context,
        const SnackBar(
          content: Text('This store is on vacation and is not taking orders.'),
        ),
      );
      return;
    }
    if (_isOutOfStock || _addCooldown) return;
    if (!_checkPurchaseLimit(forBuyNow: true)) return;
    setState(() => _addCooldown = true);
    Future.delayed(const Duration(milliseconds: 700), () {
      if (mounted) setState(() => _addCooldown = false);
    });

    final sku = _selectedSku;
    Navigator.of(context).push(
      AppPageRoute(
        builder: (_) => CheckoutScreen(
          checkoutItems: [
            CartItem(
              product: _product,
              variant: _selectedVariant,
              quantity: _quantity,
              unitPrice: sku?.price,
              variantStock: sku?.stock,
              variantImage: sku?.image,
            ),
          ],
        ),
      ),
    );
  }

  void _shareProduct() {
    Share.share(
      productShareMessage(_product, price: formatPeso(_currentPrice)),
    );
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    // Both are read by the purchase-limit note and the quantity cap; watching
    // them here means placing an order elsewhere updates this screen instead of
    // leaving a stale "1 left" label.
    context.watch<CartState>();
    context.watch<OrderState>();
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    return Scaffold(
      backgroundColor: Colors
          .transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      // The action bar lives in bottomNavigationBar (not as the last child
      // of body's Column) so Scaffold knows about it and lifts floating
      // SnackBars ABOVE it instead of letting them cover the buttons.
      bottomNavigationBar: EaseInFrame(
        index: 1,
        child: _buildBottomActionBar(context, theme),
      ),
      body: SafeArea(
        bottom: false, // the bottom bar handles its own bottom inset
        child: Column(
          children: [
            _buildAppBar(context),
            Expanded(
              child: SingleChildScrollView(
                padding: EdgeInsets.only(
                  // Reserve space for the bottom action bar plus the device's
                  // safe-area inset so the quantity stepper is always reachable.
                  bottom:
                      MediaQuery.of(context).padding.bottom +
                      80 +
                      AppSpacing.s24,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _buildGallery(ext),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(
                        AppSpacing.s20,
                        AppSpacing.s20,
                        AppSpacing.s20,
                        0,
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          EaseInFrame(
                            index: 0,
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                _buildTitleAndPrice(theme),
                                const SizedBox(height: AppSpacing.s8),
                                _buildRatingRow(theme),
                                const SizedBox(height: AppSpacing.s20),
                                _buildShippingAndVoucher(theme),
                              ],
                            ),
                          ),
                          const SizedBox(height: AppSpacing.s20),
                          EaseInFrame(
                            index: 1,
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                _buildSellerRow(theme, ext),
                                const SizedBox(height: AppSpacing.s24),
                                if (_product.optionGroups.isNotEmpty) ...[
                                  _buildVariantPicker(theme, ext),
                                  const SizedBox(height: AppSpacing.s24),
                                ],
                                _buildQuantityStepper(theme, ext),
                              ],
                            ),
                          ),
                          const SizedBox(height: AppSpacing.s24),
                          EaseInFrame(
                            index: 2,
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                _buildDescription(theme),
                                const SizedBox(height: AppSpacing.s24),
                                _buildReviewsSection(theme),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------
  // Sections
  // ---------------------------------------------------------------------

  Widget _buildAppBar(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.s12,
        AppSpacing.s8,
        AppSpacing.s12,
        0,
      ),
      child: Row(
        children: [
          _circleIconButton(
            Icons.arrow_back,
            () => Navigator.of(context).maybePop(),
            tooltip: 'Go back',
          ),
          const Spacer(),
          HeartButton(product: _product),
          const SizedBox(width: AppSpacing.s8),
          _circleIconButton(
            Icons.share_outlined,
            _shareProduct,
            tooltip: 'Share product',
          ),
          const SizedBox(width: AppSpacing.s8),
          // Gives the flight somewhere to land on this route. Home's
          // anchor is still mounted underneath, but an item arcing off
          // to an icon the user can't currently see would read as a
          // bug rather than a flourish.
          CartAnchor(
            child: _circleIconButton(
              Icons.shopping_bag_outlined,
              () => Navigator.of(
                context,
              ).push(AppPageRoute(builder: (_) => const CartScreen())),
              tooltip: 'Open cart',
            ),
          ),
        ],
      ),
    );
  }

  Widget _circleIconButton(
    IconData icon,
    VoidCallback onTap, {
    String? tooltip,
  }) {
    Widget button = Material(
      color: AppColors.surface,
      shape: const CircleBorder(),
      elevation: 1,
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: SizedBox(
          width: 48,
          height: 48,
          child: Icon(icon, size: 20, color: AppColors.textPrimary),
        ),
      ),
    );
    if (tooltip != null) {
      button = Tooltip(message: tooltip, child: button);
    }
    return button;
  }

  Widget _buildGallery(AppThemeExtension ext) {
    final photos = _galleryPhotos;

    Widget photo(int index) {
      // Same ProductImage as ProductCard, so the Hero flight between grid
      // and details shows the same photo the whole time instead of
      // swapping mid-flight.
      final image = ProductImage(
        product: _product,
        photoIndex: index,
        // A product with no uploaded photo (and no variant image) has an
        // empty gallery. Passing null lets ProductImage paint the neutral
        // placeholder instead of indexing past the end of the list.
        imageUrl: photos.isEmpty ? null : photos[index],
        iconSize: 64,
      );
      // The Hero wraps the cover *only*. Two pages carrying the same tag
      // at once is a duplicate-tag error, and the shared-element flight
      // always starts from the cover, so the other pages don't need one.
      return index == 0 && widget.heroTag != null && !reduceMotion(context)
          ? Hero(tag: widget.heroTag!, child: image)
          : image;
    }

    // Wraps a single page/photo in InteractiveViewer for pinch/double-tap
    // zoom. Each page gets its own viewer so horizontal swipes (page change)
    // and zoom are independent — putting InteractiveViewer outside the
    // PageView would absorb the swipe gesture and break paging.
    Widget zoomable(Widget child) => InteractiveViewer(
      minScale: 1.0,
      maxScale: 4.0,
      // Allow the zoomed content to extend slightly past the viewport
      // boundary so the user can pan to see edges.
      clipBehavior: Clip.none,
      child: child,
    );

    return Padding(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.s20,
        AppSpacing.s12,
        AppSpacing.s20,
        0,
      ),
      child: ClipRRect(
        // Asymmetric corners rather than a uniform cardRadius — same
        // shape language as the grid tiles this screen is entered from.
        borderRadius: editorialRadius(0, ShapeTier.hero),
        child: AspectRatio(
          aspectRatio: 1.05,
          child: KeyedSubtree(
            key: _galleryKey,
            // A product with one photo gets no pager at all: a swipeable
            // page that cannot be swiped, and a single dot, are both
            // worse than nothing (see Product.hasGallery).
            child: photos.length > 1
                ? Stack(
                    fit: StackFit.expand,
                    children: [
                      PageView.builder(
                        controller: _galleryController,
                        itemCount: photos.length,
                        onPageChanged: (i) => setState(() => _photoIndex = i),
                        itemBuilder: (context, i) => zoomable(photo(i)),
                      ),
                      Positioned(
                        left: 0,
                        right: 0,
                        bottom: 10,
                        child: Center(child: _buildGalleryDots(photos.length)),
                      ),
                    ],
                  )
                : zoomable(photo(0)),
          ),
        ),
      ),
    );
  }

  /// Page dots for the gallery.
  ///
  /// Same shape, timing and colours as the promo carousel's indicator, because
  /// this is the same idea in a second place. One difference, forced by sitting
  /// on a photo rather than on the page ground: the row carries its own scrim,
  /// because [AppColors.divider] is defined relative to a surface and an
  /// arbitrary seller photo is not one.
  ///
  /// The active dot is accent, measured rather than assumed — 4.99:1 on the
  /// scrim in light mode and 5.12:1 in dark, both clear of the 3:1 graphics
  /// floor. (primaryDark was the first choice here and was worse: it wins on
  /// paper at 9.07:1 but drops to 3.58:1 on ink, so it would have made the
  /// indicator weakest in the mode with the darkest ground. Asserted in
  /// theme_contrast_test.dart.)
  ///
  /// It is an *indicator*, not a control: a 6px dot is not a tap target, so
  /// the swipe is the interaction and the [Semantics] label is how the
  /// position gets announced. The inactive dots are deliberately faint — they
  /// are not information, and the active dot plus that label carry the state.
  Widget _buildGalleryDots(int count) {
    return Semantics(
      label: 'Photo ${_photoIndex + 1} of $count',
      child: Container(
        // Keyed so a test can count the dots through it. Without this there is
        // no way to assert "one dot per photo" from outside the widget, since
        // the dots are private and AnimatedContainer is used elsewhere.
        key: const ValueKey('gallery-dots'),
        padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.s12,
          vertical: AppSpacing.s4,
        ),
        decoration: BoxDecoration(
          // 85% opaque, so the scrim stays a predictable near-surface
          // colour over a photo of any brightness — which is the premise
          // the active dot's contrast depends on.
          color: AppColors.surface.withValues(alpha: 0.85),
          borderRadius: radiusOf(ShapeTier.pill),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: List.generate(count, (i) {
            final active = i == _photoIndex;
            return AnimatedContainer(
              duration: reduceMotion(context)
                  ? Duration.zero
                  : const Duration(milliseconds: 250),
              margin: const EdgeInsets.symmetric(horizontal: AppSpacing.s4),
              width: active ? 18 : 6,
              height: 6,
              decoration: BoxDecoration(
                color: active ? AppColors.accent : AppColors.divider,
                borderRadius: radiusOf(ShapeTier.pill),
              ),
            );
          }),
        ),
      ),
    );
  }

  Widget _buildTitleAndPrice(ThemeData theme) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          _product.name,
          style: AppType.sectionDisplay.copyWith(fontSize: 29, height: 1.12),
        ),
        const SizedBox(height: AppSpacing.s8),
        Wrap(
          spacing: AppSpacing.s12,
          runSpacing: AppSpacing.s4,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.bottomLeft,
              child: Text(
                formatPeso(_currentPrice),
                style: AppType.priceNumeral(40),
              ),
            ),
            if ((_product.compareAtPrice ?? 0) > _currentPrice)
              FittedBox(
                fit: BoxFit.scaleDown,
                child: Text(
                  formatPeso(_product.compareAtPrice!),
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: AppColors.textSecondary,
                    decoration: TextDecoration.lineThrough,
                  ),
                ),
              ),
          ],
        ),
      ],
    );
  }

  Widget _buildShippingAndVoucher(ThemeData theme) {
    if (_usesLiveDetails) {
      return Text(
        'Shipping is calculated at checkout. Browse shop vouchers in Cart or Checkout.',
        style: theme.textTheme.bodySmall?.copyWith(
          color: AppColors.textSecondary,
        ),
      );
    }
    final shipping = shippingFeeFor(_currentPrice);
    final isFree = shipping == 0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(
              isFree ? Icons.local_shipping_outlined : Icons.attach_money,
              size: 14,
              color: isFree ? AppColors.successText : AppColors.textSecondary,
            ),
            const SizedBox(width: AppSpacing.s4),
            Expanded(
              child: Text(
                isFree
                    ? 'Free shipping on orders over \u20b11,000'
                    : 'Shipping \u20b160',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: isFree
                      ? AppColors.successText
                      : AppColors.textSecondary,
                  fontWeight: isFree ? FontWeight.w600 : FontWeight.w500,
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.s12),
        Wrap(
          spacing: AppSpacing.s8,
          runSpacing: AppSpacing.s4,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Container(
              padding: const EdgeInsets.symmetric(
                horizontal: AppSpacing.s12,
                vertical: AppSpacing.s4,
              ),
              decoration: BoxDecoration(
                color: AppColors.primaryDark.withValues(alpha: 0.1),
                borderRadius: BorderRadius.circular(AppSpacing.s20),
                border: Border.all(
                  color: AppColors.primaryDark.withValues(alpha: 0.3),
                ),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(
                    Icons.local_offer,
                    size: 14,
                    color: AppColors.primaryDark,
                  ),
                  const SizedBox(width: AppSpacing.s4),
                  Text(
                    'Apply voucher',
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: AppColors.primaryDark,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),
            ),
            OutlinedButton(
              onPressed: () => Navigator.of(
                context,
              ).push(AppPageRoute(builder: (_) => VouchersScreen())),
              style: OutlinedButton.styleFrom(
                padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.s12,
                  vertical: AppSpacing.s4,
                ),
                minimumSize: Size.zero,
                side: BorderSide(color: AppColors.divider),
              ),
              child: Text(
                'Add code',
                style: theme.textTheme.labelSmall?.copyWith(
                  color: AppColors.textPrimary,
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildRatingRow(ThemeData theme) {
    // A rating is an average over ratings, and a sold count is a count of sales:
    // with none of either there is no number to show. A live product starts
    // exactly there — the server has no reviews table and no sales counter — so
    // the row is dropped rather than printed as "0.0 ★ (0 ratings) • 0 sold",
    // which are numbers about nothing. Every mock product carries both, so this
    // changes nothing in mock mode.
    final hasRating = _product.ratingCount > 0;
    final hasSales = _product.soldCount > 0;
    if (!hasRating && !hasSales) return const SizedBox.shrink();

    final sold = _product.soldCount >= 1000
        ? '${(_product.soldCount / 1000).toStringAsFixed(1)}k sold'
        : '${_product.soldCount} sold';
    // Wrap (grouped so a bullet never dangles alone): on narrow screens or
    // large text the "sold" count drops to a second line instead of overflowing.
    return Wrap(
      spacing: AppSpacing.s12,
      runSpacing: AppSpacing.s4,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        if (hasRating)
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.star_rounded, size: 18, color: AppColors.ratingStar),
              const SizedBox(width: AppSpacing.s4),
              Text(
                _product.rating.toStringAsFixed(1),
                style: theme.textTheme.bodyMedium,
              ),
              const SizedBox(width: AppSpacing.s4),
              Text(
                '(${_product.ratingCount} ratings)',
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        if (hasSales)
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              // The bullet only ever separates two things, so it is not drawn
              // when it would be leading one.
              if (hasRating) ...[
                Text('•', style: theme.textTheme.bodySmall),
                const SizedBox(width: AppSpacing.s12),
              ],
              Text(sold, style: theme.textTheme.bodySmall),
            ],
          ),
      ],
    );
  }

  Widget _buildSellerRow(ThemeData theme, AppThemeExtension ext) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.s16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: BorderRadius.circular(ext.cardRadius),
      ),
      child: Row(
        children: [
          CircleAvatar(
            radius: 20,
            backgroundColor: AppColors.tertiary,
            child: Icon(
              Icons.storefront_outlined,
              color: AppColors.textOnLight,
              size: 20,
            ),
          ),
          const SizedBox(width: AppSpacing.s12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Tappable — pushes SellerScreen to browse all their products.
                GestureDetector(
                  onTap: () => Navigator.of(context).push(
                    AppPageRoute(
                      builder: (_) =>
                          SellerScreen(sellerName: _product.sellerName),
                    ),
                  ),
                  child: Text(
                    _product.sellerName,
                    style: theme.textTheme.titleSmall?.copyWith(
                      color: AppColors.primaryDark,
                      decoration: TextDecoration.underline,
                      decorationColor: AppColors.primaryDark.withValues(
                        alpha: 0.4,
                      ),
                    ),
                  ),
                ),
                Text(
                  _product.sellerOnVacation
                      ? 'On vacation · not accepting orders'
                      : FeatureFlags.chatLive
                      ? 'Usually replies within an hour'
                      : 'Browse the rest of their collection',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: _product.sellerOnVacation ? AppColors.danger : null,
                    fontWeight: _product.sellerOnVacation
                        ? FontWeight.w600
                        : null,
                  ),
                ),
              ],
            ),
          ),
          // Chat lives in the bottom action bar. A second Chat button here
          // was the same action twice on one screen, which made the seller
          // row look like the primary way to message them.
        ],
      ),
    );
  }

  // Maps common color-variant names to an actual color for the swatch
  // row. Deliberately small and keyed to the palette this app already
  // uses (Blush/Sage map straight to AppColors.secondary/tertiary) with
  // a few common extras so new color variants a seller adds later still
  // render as swatches rather than silently falling back to chips.
  // Rebuilt per call rather than held as a `static const` map: blush, sage
  // and the two rose names resolve to palette tokens, and primaryDark has
  // a different value per mode — a const map would pin whichever mode
  // happened to load first.
  static Map<String, Color> get _colorNames => {
    'blush': AppColors.secondary,
    'sage': AppColors.tertiary,
    'neutral': Color(0xFFD8CFC9),
    'cream': Color(0xFFEFE6DC),
    'ivory': Color(0xFFF3EEE4),
    'charcoal': Color(0xFF3A3536),
    'black': Colors.black,
    'white': Colors.white,
    'rust': Color(0xFFB5643C),
    'terracotta': Color(0xFFC96F4A),
    'maroon': AppColors.primaryDark,
    'wine': AppColors.primaryDark,
    'rose': Color(0xFFD98FA0),
    'pink': Color(0xFFE2B4BD),
  };

  bool _isColorGroup(VariationType group) =>
      group.values.isNotEmpty &&
      group.values.every(
        (value) => _colorNames.containsKey(value.toLowerCase()),
      );

  Widget _buildVariantPicker(ThemeData theme, AppThemeExtension ext) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (var i = 0; i < _groups.length; i++) ...[
          _buildVariationGroup(theme, _groups[i]),
          if (i < _groups.length - 1) const SizedBox(height: AppSpacing.s16),
        ],
      ],
    );
  }

  Widget _buildVariationGroup(ThemeData theme, VariationType group) {
    final isColor = _isColorGroup(group);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(isColor ? 'Color' : group.name, style: theme.textTheme.titleSmall),
        const SizedBox(height: AppSpacing.s8),
        Wrap(
          spacing: isColor ? 14 : 10,
          runSpacing: 10,
          children: [
            for (final value in group.values)
              isColor
                  ? _buildColorSwatch(group.name, value)
                  : _buildOptionChip(theme, group.name, value),
          ],
        ),
      ],
    );
  }

  Widget _buildColorSwatch(String groupName, String name) {
    final color = _colorNames[name.toLowerCase()] ?? AppColors.divider;
    final selected = _selectedOptions[groupName] == name;
    final available = _isOptionAvailable(groupName, name);
    return Semantics(
      button: true,
      enabled: available,
      selected: selected,
      label: available
          ? (selected ? '$name color, selected' : '$name color')
          : '$name color, out of stock',
      child: GestureDetector(
        key: ValueKey('variant-$groupName-$name'),
        onTap: available ? () => _selectOption(groupName, name) : null,
        child: Tooltip(
          message: available ? name : '$name — out of stock',
          child: ConstrainedBox(
            constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
            child: Center(
              child: Opacity(
                opacity: available ? 1 : 0.35,
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 180),
                  curve: Curves.easeOut,
                  width: selected ? 34 : 28,
                  height: selected ? 34 : 28,
                  decoration: BoxDecoration(
                    color: color,
                    shape: BoxShape.circle,
                    border: Border.all(
                      color: selected
                          ? AppColors.primaryDark
                          : AppColors.divider,
                      width: selected ? 2.5 : 1,
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withValues(
                          alpha: selected ? 0.15 : 0.0,
                        ),
                        blurRadius: 6,
                        offset: const Offset(0, 2),
                      ),
                    ],
                  ),
                  child: selected
                      ? Icon(
                          Icons.check,
                          size: 16,
                          color:
                              ThemeData.estimateBrightnessForColor(color) ==
                                  Brightness.dark
                              ? Colors.white
                              : Colors.black87,
                        )
                      : null,
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildOptionChip(ThemeData theme, String groupName, String value) {
    final selected = _selectedOptions[groupName] == value;
    final available = _isOptionAvailable(groupName, value);
    return Tooltip(
      message: available ? value : '$value — out of stock',
      child: ChoiceChip(
        key: ValueKey('variant-$groupName-$value'),
        label: Text(value),
        selected: selected,
        onSelected: available ? (_) => _selectOption(groupName, value) : null,
        shape: StadiumBorder(
          side: BorderSide(
            color: selected ? AppColors.primaryDark : AppColors.divider,
          ),
        ),
      ),
    );
  }

  Widget _buildQuantityStepper(ThemeData theme, AppThemeExtension ext) {
    final stock = _currentStock;
    final remaining = _remainingForAdd();
    final cap = math.min(
      CartState.maxQuantityPerLine,
      math.min(stock, remaining ?? CartState.maxQuantityPerLine),
    );
    final limit = _product.purchaseLimit;
    final String stockLabel;
    final Color? stockColor;
    if (stock == 0) {
      stockLabel = 'Out of stock';
      stockColor = AppColors.danger;
    } else if (stock <= 5) {
      stockLabel = 'Only $stock left';
      stockColor = AppColors.danger;
    } else {
      stockLabel = '$stock available';
      stockColor = AppColors.textSecondary;
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text('Quantity', style: theme.textTheme.titleSmall),
            Flexible(
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Flexible(
                    child: Text(
                      stockLabel,
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: stockColor,
                        fontWeight: stock <= 5 ? FontWeight.w600 : null,
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  const SizedBox(width: AppSpacing.s12),
                  Container(
                    decoration: BoxDecoration(
                      border: Border.all(color: AppColors.divider),
                      borderRadius: BorderRadius.circular(ext.pillRadius),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        _stepperButton(
                          Icons.remove,
                          (stock > 0 && _quantity > 1) ? _decrementQty : null,
                        ),
                        SizedBox(
                          width: 32,
                          child: Text(
                            '$_quantity',
                            textAlign: TextAlign.center,
                            style: theme.textTheme.bodyMedium?.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                        _stepperButton(
                          Icons.add,
                          (stock > 0 && _quantity < cap) ? _incrementQty : null,
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
        if (limit != null) ...[
          const SizedBox(height: AppSpacing.s8),
          Row(
            children: [
              Icon(
                Icons.shopping_basket_outlined,
                size: 15,
                color: remaining == 0
                    ? AppColors.danger
                    : AppColors.textSecondary,
              ),
              const SizedBox(width: AppSpacing.s4),
              Expanded(
                child: Text(
                  remaining == 0
                      ? 'Purchase limit reached · $limit per buyer'
                      : 'Purchase limit: $limit per buyer · '
                            '${remaining ?? 0} more available to you',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: remaining == 0
                        ? AppColors.danger
                        : AppColors.textSecondary,
                    fontWeight: remaining == 0 ? FontWeight.w600 : null,
                  ),
                ),
              ),
            ],
          ),
        ],
      ],
    );
  }

  Widget _stepperButton(IconData icon, VoidCallback? onTap) {
    return ConstrainedBox(
      constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
      child: InkWell(
        onTap: onTap,
        borderRadius: radiusOf(ShapeTier.pill),
        child: Icon(
          icon,
          size: 16,
          color: onTap != null
              ? AppColors.textPrimary
              : AppColors.textSecondary.withValues(alpha: 0.35),
        ),
      ),
    );
  }

  Widget _buildDescription(ThemeData theme) {
    final description = _product.description.isEmpty
        ? 'No description provided yet.'
        : _product.description;
    final style = theme.textTheme.bodyMedium?.copyWith(height: 1.5);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Description', style: theme.textTheme.titleSmall),
        const SizedBox(height: AppSpacing.s8),
        LayoutBuilder(
          builder: (context, constraints) {
            // Only offer the toggle when the copy actually runs past three
            // lines at this width and text scale. A two-line description with
            // a "Read more" that reveals nothing is worse than no control.
            final painter = TextPainter(
              text: TextSpan(text: description, style: style),
              maxLines: 3,
              textDirection: Directionality.of(context),
              textScaler: MediaQuery.textScalerOf(context),
              locale: Localizations.maybeLocaleOf(context),
            )..layout(maxWidth: constraints.maxWidth);
            final hasMore = painter.didExceedMaxLines;
            painter.dispose();

            return Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  description,
                  key: const ValueKey('product-description'),
                  style: style,
                  maxLines: _descriptionExpanded ? null : 3,
                  overflow: _descriptionExpanded
                      ? TextOverflow.visible
                      : TextOverflow.ellipsis,
                ),
                if (hasMore)
                  TextButton(
                    key: const ValueKey('description-toggle'),
                    onPressed: () => setState(
                      () => _descriptionExpanded = !_descriptionExpanded,
                    ),
                    style: TextButton.styleFrom(
                      padding: EdgeInsets.zero,
                      minimumSize: const Size(48, 40),
                      alignment: Alignment.centerLeft,
                    ),
                    child: Text(
                      _descriptionExpanded ? 'Show less' : 'Read more',
                    ),
                  ),
              ],
            );
          },
        ),
      ],
    );
  }

  /// Reviews, honestly scoped.
  ///
  /// Two different things live here and are deliberately not blended:
  ///
  ///  * The storefront's aggregate (`Product.rating` / `.ratingCount`) is
  ///    catalogue data — one number the shop owns. It's rendered as a
  ///    self-contained summary block, because it's complete on its own and
  ///    doesn't need a list under it to make sense.
  ///  * The reviews below it are the ones *this device* wrote, badged YOUR
  ///    REVIEW so their provenance is unambiguous. See review_state.dart.
  ///
  /// What used to be here was two hardcoded tiles and a "See all" sheet
  /// naming five invented buyers ('Marielle S.', 'Jonah P.', …) with invented
  /// comments. That is a claim about human specificity with nothing behind it,
  /// and it went the moment there was a real review to show instead. Other
  /// buyers' written reviews need the reviews API; until then the honest
  /// version is a real aggregate plus your own words.
  Widget _buildReviewsSection(ThemeData theme) {
    final myReviews = context.watch<ReviewState>().forProduct(_product.id);
    final purchased = context.watch<OrderState>().purchasedProductIds.contains(
      _product.id,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('WHAT BUYERS SAY', style: AppType.eyebrow),
        const SizedBox(height: AppSpacing.s8),
        // The aggregate claims "how everyone rated this". With no ratings it
        // would read "0 / REVIEWS / ★ 0.0 average" — three claims resting on an
        // empty set — so it is not drawn at all, and the "No reviews yet" line
        // below says what is actually true instead.
        if (_product.ratingCount > 0)
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(AppSpacing.s16),
            decoration: BoxDecoration(
              color: AppColors.surfaceMuted,
              borderRadius: editorialRadius(0, ShapeTier.accent),
            ),
            child: Row(
              children: [
                // Display numeral, so the section reads as a genuine feature
                // rather than a plain form label.
                Text(
                  '${_product.ratingCount}',
                  style: AppType.priceNumeral(34),
                ),
                const SizedBox(width: AppSpacing.s12),
                Flexible(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _product.ratingCount == 1 ? 'REVIEW' : 'REVIEWS',
                        style: AppType.eyebrow,
                      ),
                      const SizedBox(height: AppSpacing.s4),
                      Row(
                        children: [
                          Icon(
                            Icons.star_rounded,
                            size: 16,
                            color: AppColors.ratingStar,
                          ),
                          const SizedBox(width: AppSpacing.s4),
                          Flexible(
                            child: Text(
                              '${_product.rating.toStringAsFixed(1)} average',
                              style: theme.textTheme.bodySmall,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        if (_product.ratingDistribution.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: AppSpacing.s12),
            child: _RatingDistributionBars(
              distribution: _product.ratingDistribution,
              total: _product.ratingCount,
            ),
          ),
        const SizedBox(height: AppSpacing.s16),
        for (final review in myReviews) _ownReviewTile(theme, review),
        if (myReviews.isEmpty && _product.ratingCount == 0)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: AppSpacing.s4),
            child: Text(
              'No reviews yet — be the first to leave one.',
              style: theme.textTheme.bodySmall,
            ),
          ),
        // Only offered when there's something to review: you can't rate a
        // product you never received, and the button would have no order to
        // attach the review to.
        if (purchased && myReviews.isEmpty) ...[
          const SizedBox(height: AppSpacing.s4),
          SizedBox(
            width: double.infinity,
            child: OutlinedButton.icon(
              onPressed: () => _openReview(context),
              icon: const Icon(Icons.star_border, size: 16),
              label: const Text('Write a Review'),
            ),
          ),
        ],
      ],
    );
  }

  /// Opens the review form for this product against the order it was bought
  /// on — the first order that contains it, which is the purchase the buyer is
  /// most likely thinking about.
  ///
  /// Silent when no such order exists rather than falling back to some other
  /// order: a review is attached to a real purchase, and inventing one would
  /// put words in someone's mouth about a parcel they never got.
  void _openReview(BuildContext context) {
    final orders = context.read<OrderState>().resolvedOrders(
      context.read<CourierState>(),
    );
    for (final order in orders) {
      if (order.productIds.contains(_product.id)) {
        _pushReview(context, order);
        return;
      }
    }
  }

  /// One review this device wrote, with its provenance stated rather than
  /// implied — the badge is the whole point of this tile.
  Widget _ownReviewTile(ThemeData theme, ProductReview review) {
    final written = review.createdAt;
    return Container(
      margin: const EdgeInsets.only(bottom: AppSpacing.s8),
      padding: const EdgeInsets.all(AppSpacing.s16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: editorialRadius(0, ShapeTier.accent),
        border: Border.all(color: AppColors.divider),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: AppColors.tertiary.withValues(alpha: 0.22),
                  borderRadius: radiusOf(ShapeTier.pill),
                ),
                child: Text(
                  'YOUR REVIEW',
                  style: theme.textTheme.labelSmall?.copyWith(
                    // Light ink on the pale sage fill, same rule as the
                    // Featured tag — see AppPalette.textOnLight.
                    color: AppColors.textOnLight,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              const Spacer(),
              Row(
                children: List.generate(
                  5,
                  (star) => Icon(
                    star < review.stars
                        ? Icons.star_rounded
                        : Icons.star_border_rounded,
                    size: 14,
                    color: star < review.stars
                        ? AppColors.ratingStar
                        : AppColors.textSecondary,
                  ),
                ),
              ),
            ],
          ),
          if (review.comment.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.s8),
            Text(
              review.comment,
              style: theme.textTheme.bodySmall?.copyWith(height: 1.4),
            ),
          ],
          if (review.photos.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.s8),
            Wrap(
              spacing: AppSpacing.s8,
              runSpacing: AppSpacing.s8,
              children: [
                for (final photo in review.photos)
                  GestureDetector(
                    onTap: () => Navigator.of(context).push(
                      AppPageRoute(
                        builder: (_) => _ReviewPhotoViewer(
                          path: photo,
                          heroTag: 'review-${_product.id}-$photo',
                        ),
                      ),
                    ),
                    child: ClipRRect(
                      borderRadius: editorialRadius(0, ShapeTier.accent),
                      child: Image.file(
                        File(photo),
                        width: 88,
                        height: 88,
                        fit: BoxFit.cover,
                        // The file is gone (cleared storage) — a label, not a
                        // broken glyph.
                        errorBuilder: (context, error, stack) => SizedBox(
                          width: 88,
                          height: 88,
                          child: Icon(
                            Icons.image_not_supported_outlined,
                            color: AppColors.textSecondary,
                          ),
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ],
          const SizedBox(height: AppSpacing.s4),
          Row(
            children: [
              Text(
                '${written.day}/${written.month}/${written.year}',
                style: theme.textTheme.labelSmall?.copyWith(
                  color: AppColors.textSecondary,
                ),
              ),
              const Spacer(),
              TextButton(
                onPressed: () => _editReview(context, review),
                child: const Text('Edit'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Re-opens the form for a review already written, against the order it came
  /// from. ReviewScreen pre-fills from the existing review, so this is an edit
  /// rather than a second opinion on the same purchase.
  void _editReview(BuildContext context, ProductReview review) {
    for (final order in context.read<OrderState>().orders) {
      if (order.id == review.orderId) {
        _pushReview(context, order);
        return;
      }
    }
  }

  void _pushReview(BuildContext context, Order order) {
    Navigator.of(context).push(
      AppPageRoute(
        builder: (_) => ReviewScreen(
          order: order,
          // Just this product: the rest of that order's items are either
          // already reviewed or not what the buyer is looking at.
          products: [_product],
        ),
      ),
    );
  }

  Widget _buildBottomActionBar(BuildContext context, ThemeData theme) {
    final isOutOfStock = _isOutOfStock;
    final onVacation = _product.sellerOnVacation;
    final actionsDisabled =
        isOutOfStock || onVacation || _loadingDetails || _detailsError != null;
    final productId = int.tryParse(_product.id);
    final sellerId = _product.sellerId;
    final canChatLive =
        productId != null &&
        sellerId != null &&
        context.read<ChatState?>()?.liveRepository != null;

    return Container(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.s20,
        AppSpacing.s16,
        AppSpacing.s20,
        AppSpacing.s16,
      ),
      decoration: BoxDecoration(
        color: AppColors.surface,
        // index 1 — the mirrored lean, so this bar and the cart's summary
        // bar (index 0) lean opposite ways instead of both being typed by
        // hand with one number swapped.
        borderRadius: editorialRadius(1, ShapeTier.card),
        boxShadow: [
          BoxShadow(
            color: AppColors.neutral.withValues(alpha: 0.12),
            blurRadius: 16,
            offset: const Offset(0, -4),
          ),
        ],
      ),
      child: SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_selectedVariant.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.s8),
                child: Text(
                  'Selected: $_selectedVariant',
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            if (onVacation || isOutOfStock)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.s8),
                child: Container(
                  padding: const EdgeInsets.symmetric(
                    vertical: AppSpacing.s4,
                    horizontal: AppSpacing.s12,
                  ),
                  decoration: BoxDecoration(
                    color: AppColors.danger.withValues(alpha: 0.12),
                    borderRadius: radiusOf(ShapeTier.control),
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(
                        Icons.info_outline,
                        size: 16,
                        color: AppColors.danger,
                      ),
                      const SizedBox(width: AppSpacing.s4),
                      Text(
                        onVacation ? 'Store on vacation' : 'Out of stock',
                        style: theme.textTheme.labelMedium?.copyWith(
                          color: AppColors.danger,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            if (_loadingDetails)
              const LinearProgressIndicator(
                semanticsLabel: 'Loading product details',
              ),
            if (_detailsError case final error?) ...[
              InlineErrorNotice(error),
              TextButton(onPressed: _loadDetails, child: const Text('Retry')),
            ],
            Row(
              children: [
                if (FeatureFlags.chatLive || canChatLive) ...[
                  _circleIconButton(
                    Icons.chat_bubble_outline,
                    () => Navigator.of(context).push(
                      AppPageRoute(
                        builder: (_) => ChatScreen(
                          contactName: _product.sellerName,
                          contactRole: 'Seller',
                          sellerId: canChatLive ? sellerId : null,
                          productId: canChatLive ? productId : null,
                        ),
                      ),
                    ),
                    tooltip: 'Chat with seller',
                  ),
                  const SizedBox(width: AppSpacing.s8),
                ],
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: actionsDisabled
                        ? null
                        : () => _addToCart(showSnackBar: true),
                    icon: const Icon(
                      Icons.add_shopping_cart_outlined,
                      size: 18,
                    ),
                    label: const Text('Add to Cart'),
                  ),
                ),
                const SizedBox(width: AppSpacing.s8),
                Expanded(
                  child: ElevatedButton(
                    onPressed: actionsDisabled ? null : _buyNow,
                    child: Text(
                      onVacation
                          ? 'On Vacation'
                          : isOutOfStock
                          ? 'Out of Stock'
                          : 'Buy Now',
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// A horizontal bar per star (5 at top, 1 at bottom) whose length is the
/// share of that star within [total]. Tappable bars do nothing — they are a
/// read-only honest histogram until the server owns the distribution.
class _RatingDistributionBars extends StatelessWidget {
  const _RatingDistributionBars({
    required this.distribution,
    required this.total,
  });

  final Map<int, int> distribution;
  final int total;

  double get _peak => distribution.values.isEmpty
      ? 0
      : distribution.values.reduce(math.max).toDouble();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final star in [5, 4, 3, 2, 1]) ...[
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.s4),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                SizedBox(
                  width: 24,
                  child: Text('$star', style: theme.textTheme.labelSmall),
                ),
                const SizedBox(width: AppSpacing.s8),
                Expanded(
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(AppSpacing.s4),
                    child: LinearProgressIndicator(
                      value: _peak == 0 ? 0 : (distribution[star] ?? 0) / _peak,
                      minHeight: AppSpacing.s8,
                      backgroundColor: AppColors.divider,
                      valueColor: AlwaysStoppedAnimation(
                        star == 5 ? AppColors.ratingStar : AppColors.tertiary,
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: AppSpacing.s8),
                Text(
                  '${distribution[star] ?? 0}',
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
                ),
              ],
            ),
          ),
        ],
        Padding(
          padding: const EdgeInsets.only(top: AppSpacing.s4),
          child: Text(
            '$total ${total == 1 ? 'RATING' : 'RATINGS'}',
            style: theme.textTheme.labelSmall?.copyWith(
              color: AppColors.textSecondary,
            ),
          ),
        ),
      ],
    );
  }
}

/// Full-screen photo viewer for a review photo, opened by tapping a review's
/// photo thumbnail. No hero here — a review photo is opened from the product
/// page, not a grid, so a plain fade-through keeps the transition honest.
class _ReviewPhotoViewer extends StatelessWidget {
  const _ReviewPhotoViewer({required this.path, required this.heroTag});

  final String path;
  final String heroTag;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.close),
          onPressed: () => Navigator.of(context).pop(),
        ),
      ),
      body: Center(
        child: InteractiveViewer(
          panEnabled: true,
          scaleEnabled: true,
          minScale: 0.5,
          maxScale: 3,
          child: Image.file(File(path)),
        ),
      ),
    );
  }
}
