// lib/screens/buyer/product_details_screen.dart
//
// Buyer-facing Product Details screen, fully styled with the
// "Zefanya" theme tokens (see theme/app_theme.dart). Renders
// whichever Product is passed in (see data/mock_data.dart) rather
// than a single hardcoded item, and Add to Cart / Buy Now write to
// the shared CartState so Cart/Checkout reflect the same data.

import 'dart:io';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';
import '../../data/mock_data.dart';
import '../../state/cart_state.dart';
import '../../state/courier_state.dart';
import '../../state/order_state.dart';
import '../../state/recently_viewed_state.dart';
import '../../state/review_state.dart';
import '../../theme/app_theme.dart';
import '../shared/chat_screen.dart';
import 'cart_screen.dart';
import 'checkout_screen.dart';
import 'review_screen.dart';
import 'seller_screen.dart';
import 'widgets/product_card.dart';
import '../../utils/motion.dart';
import '../../utils/timed_snackbar.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/cart_flight.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/heart_button.dart';

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
  int _selectedVariantIndex = 0;
  static const int _stock = 24;

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

  Product get _product => widget.product;
  String get _selectedVariant =>
      _product.variants.isEmpty ? '' : _product.variants[_selectedVariantIndex];

  @override
  void initState() {
    super.initState();
    // Deferred to post-frame: this notifies RecentlyViewedState, and
    // calling that during this screen's own initial build (rather than
    // after it) would trigger a rebuild of whatever's listening — Home,
    // in this case — while this screen is still mid-build.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) context.read<RecentlyViewedState>().recordView(_product.id);
    });
  }

  @override
  void dispose() {
    _galleryController.dispose();
    super.dispose();
  }

  void _incrementQty() {
    if (_quantity < _stock) setState(() => _quantity++);
  }

  void _decrementQty() {
    if (_quantity > 1) setState(() => _quantity--);
  }

  /// Returns false (and does nothing) if a previous tap is still inside
  /// its cooldown, so callers can skip whatever they were going to do
  /// next (e.g. Buy Now's navigation to Checkout).
  bool _addToCart({required bool showSnackBar}) {
    if (_addCooldown) return false;
    setState(() => _addCooldown = true);
    Future.delayed(const Duration(milliseconds: 700), () {
      if (mounted) setState(() => _addCooldown = false);
    });

    context.read<CartState>().addItem(_product, variant: _selectedVariant, quantity: _quantity);

    // The signature moment: the product physically flies into the cart
    // icon (see widgets/cart_flight.dart). Fired before the SnackBar so
    // the two don't compete for the same beat.
    CartFlight.send(
      context,
      originKey: _galleryKey,
      thumbnail: ClipRRect(
        borderRadius: radiusOf(ShapeTier.card),
        child: ProductImage(product: _product, iconSize: 28),
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
        content: Text('Added ${_product.name} to cart'),
        persist: false,
        action: SnackBarAction(
          label: 'View Cart',
          onPressed: () => Navigator.of(context).push(
            AppPageRoute(builder: (_) => const CartScreen()),
          ),
        ),
      ),
    );
    return true;
  }

  void _shareProduct() {
    // Deep links aren't wired yet, so share a plain text summary.
    // Swap the text for a real URL once product pages have permalinks.
    Share.share(
      '${_product.name} — ₱${_product.price.toStringAsFixed(0)}\n'
      'Sold by ${_product.sellerName} on Zefanya.',
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    return Scaffold(
      backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      // The action bar lives in bottomNavigationBar (not as the last child
      // of body's Column) so Scaffold knows about it and lifts floating
      // SnackBars ABOVE it instead of letting them cover the buttons.
      bottomNavigationBar: _buildBottomActionBar(context, theme),
      body: SafeArea(
        bottom: false, // the bottom bar handles its own bottom inset
        child: Column(
          children: [
            _buildAppBar(context),
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.only(bottom: 24),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _buildGallery(ext),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(20, 20, 20, 0),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          _buildTitleAndPrice(theme),
                          const SizedBox(height: 6),
                          _buildRatingRow(theme),
                          const SizedBox(height: 20),
                          _buildSellerRow(theme, ext),
                          const SizedBox(height: 24),
                          if (_product.variants.isNotEmpty) ...[
                            _buildVariantPicker(theme, ext),
                            const SizedBox(height: 24),
                          ],
                          _buildQuantityStepper(theme, ext),
                          const SizedBox(height: 24),
                          _buildDescription(theme),
                          const SizedBox(height: 24),
                          _buildReviewsSection(theme),
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
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
      child: Row(
        children: [
          _circleIconButton(Icons.arrow_back, () => Navigator.of(context).maybePop()),
          const Spacer(),
          HeartButton(product: _product),
          const SizedBox(width: 8),
          _circleIconButton(Icons.share_outlined, _shareProduct),
          const SizedBox(width: 8),
          // Gives the flight somewhere to land on this route. Home's
          // anchor is still mounted underneath, but an item arcing off
          // to an icon the user can't currently see would read as a
          // bug rather than a flourish.
          CartAnchor(
            child: _circleIconButton(
              Icons.shopping_bag_outlined,
              () => Navigator.of(context).push(
                AppPageRoute(builder: (_) => const CartScreen()),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _circleIconButton(IconData icon, VoidCallback onTap) {
    return Material(
      color: AppColors.surface,
      shape: const CircleBorder(),
      elevation: 1,
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Icon(icon, size: 20, color: AppColors.textPrimary),
        ),
      ),
    );
  }

  Widget _buildGallery(AppThemeExtension ext) {
    final photos = _product.photos;

    Widget photo(int index) {
      // Same ProductImage as ProductCard, so the Hero flight between grid
      // and details shows the same photo the whole time instead of
      // swapping mid-flight.
      final image = ProductImage(
        product: _product,
        photoIndex: index,
        iconSize: 64,
      );
      // The Hero wraps the cover *only*. Two pages carrying the same tag
      // at once is a duplicate-tag error, and the shared-element flight
      // always starts from the cover, so the other pages don't need one.
      return index == 0 && widget.heroTag != null
          ? Hero(tag: widget.heroTag!, child: image)
          : image;
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 0),
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
            child: _product.hasGallery
                ? Stack(
                    fit: StackFit.expand,
                    children: [
                      PageView.builder(
                        controller: _galleryController,
                        itemCount: photos.length,
                        onPageChanged: (i) => setState(() => _photoIndex = i),
                        itemBuilder: (context, i) => photo(i),
                      ),
                      Positioned(
                        left: 0,
                        right: 0,
                        bottom: 10,
                        child: Center(child: _buildGalleryDots(photos.length)),
                      ),
                    ],
                  )
                : photo(0),
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
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
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
              margin: const EdgeInsets.symmetric(horizontal: 3),
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
        Text(_product.name, style: theme.textTheme.titleLarge),
        const SizedBox(height: 12),
        Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            // The one dramatically oversized element on this screen.
            // Was headlineSmall (24pt) — close enough to the product
            // name above it that neither won, so the page had no focal
            // point at all.
            Text(
              '₱${_product.price.toStringAsFixed(0)}',
              style: AppType.priceNumeral(52),
            ),
            if (_product.compareAtPrice != null) ...[
              const SizedBox(width: 12),
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(
                  '₱${_product.compareAtPrice!.toStringAsFixed(0)}',
                  style: theme.textTheme.bodyMedium?.copyWith(
                    color: AppColors.textSecondary,
                    decoration: TextDecoration.lineThrough,
                  ),
                ),
              ),
            ],
          ],
        ),
      ],
    );
  }

  Widget _buildRatingRow(ThemeData theme) {
    final sold = _product.soldCount >= 1000
        ? '${(_product.soldCount / 1000).toStringAsFixed(1)}k sold'
        : '${_product.soldCount} sold';
    // Wrap (grouped so a bullet never dangles alone): on narrow screens or
    // large text the "sold" count drops to a second line instead of overflowing.
    return Wrap(
      spacing: 12,
      runSpacing: 4,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.star_rounded, size: 18, color: AppColors.ratingStar),
            const SizedBox(width: 4),
            Text(_product.rating.toStringAsFixed(1), style: theme.textTheme.bodyMedium),
            const SizedBox(width: 4),
            Text('(${_product.ratingCount} ratings)', style: theme.textTheme.bodySmall),
          ],
        ),
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text('•', style: theme.textTheme.bodySmall),
            const SizedBox(width: 12),
            Text(sold, style: theme.textTheme.bodySmall),
          ],
        ),
      ],
    );
  }

  Widget _buildSellerRow(ThemeData theme, AppThemeExtension ext) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: BorderRadius.circular(ext.cardRadius),
      ),
      child: Row(
        children: [
          CircleAvatar(
            radius: 20,
            backgroundColor: AppColors.tertiary,
            child: Icon(Icons.storefront_outlined, color: AppColors.textOnLight, size: 20),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // Tappable — pushes SellerScreen to browse all their products.
                GestureDetector(
                  onTap: () => Navigator.of(context).push(
                    AppPageRoute(
                      builder: (_) => SellerScreen(sellerName: _product.sellerName),
                    ),
                  ),
                  child: Text(
                    _product.sellerName,
                    style: theme.textTheme.titleSmall?.copyWith(
                      color: AppColors.primaryDark,
                      decoration: TextDecoration.underline,
                      decorationColor: AppColors.primaryDark.withValues(alpha: 0.4),
                    ),
                  ),
                ),
                Text('Usually replies within an hour', style: theme.textTheme.bodySmall),
              ],
            ),
          ),
          OutlinedButton.icon(
            onPressed: () => Navigator.of(context).push(
              AppPageRoute(
                builder: (_) => ChatScreen(contactName: _product.sellerName, contactRole: 'Seller'),
              ),
            ),
            icon: const Icon(Icons.chat_bubble_outline, size: 16),
            label: const Text('Chat'),
            style: OutlinedButton.styleFrom(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
            ),
          ),
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

  bool get _variantsAreColors =>
      _product.variants.isNotEmpty && _product.variants.every((v) => _colorNames.containsKey(v.toLowerCase()));

  Widget _buildVariantPicker(ThemeData theme, AppThemeExtension ext) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(_variantsAreColors ? 'Color' : 'Options', style: theme.textTheme.titleSmall),
        const SizedBox(height: 10),
        if (_variantsAreColors)
          Row(
            children: List.generate(_product.variants.length, (i) {
              final name = _product.variants[i];
              final color = _colorNames[name.toLowerCase()]!;
              final selected = i == _selectedVariantIndex;
              return Padding(
                padding: const EdgeInsets.only(right: 14),
                child: Semantics(
                  button: true,
                  selected: selected,
                  label: selected ? '$name color, selected' : '$name color',
                  child: GestureDetector(
                    onTap: () => setState(() => _selectedVariantIndex = i),
                    child: Tooltip(
                    message: name,
                    child: AnimatedContainer(
                      duration: const Duration(milliseconds: 180),
                      curve: Curves.easeOut,
                      width: selected ? 34 : 28,
                      height: selected ? 34 : 28,
                      decoration: BoxDecoration(
                        color: color,
                        shape: BoxShape.circle,
                        border: Border.all(
                          color: selected ? AppColors.primaryDark : AppColors.divider,
                          width: selected ? 2.5 : 1,
                        ),
                        // Always present — null vs list can't lerp, so
                        // the shadow would snap instead of easing in.
                        // alpha: 0 when unselected gives a smooth fade.
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withValues(alpha: selected ? 0.15 : 0.0),
                            blurRadius: 6,
                            offset: const Offset(0, 2),
                          ),
                        ],
                      ),
                    ),
                  ),
                  ),
                ),
              );
            }),
          )
        else
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: List.generate(_product.variants.length, (i) {
              final selected = i == _selectedVariantIndex;
              return ChoiceChip(
                label: Text(_product.variants[i]),
                selected: selected,
                onSelected: (_) => setState(() => _selectedVariantIndex = i),
                shape: StadiumBorder(
                  side: BorderSide(
                    color: selected ? AppColors.primaryDark : AppColors.divider,
                  ),
                ),
              );
            }),
          ),
      ],
    );
  }

  Widget _buildQuantityStepper(ThemeData theme, AppThemeExtension ext) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text('Quantity', style: theme.textTheme.titleSmall),
        Flexible(
          child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Flexible(
              child: Text('$_stock available', style: theme.textTheme.bodySmall, maxLines: 1, overflow: TextOverflow.ellipsis),
            ),
            const SizedBox(width: 12),
            Container(
              decoration: BoxDecoration(
                border: Border.all(color: AppColors.divider),
                borderRadius: BorderRadius.circular(ext.pillRadius),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  _stepperButton(Icons.remove, _decrementQty),
                  SizedBox(
                    width: 32,
                    child: Text(
                      '$_quantity',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
                    ),
                  ),
                  _stepperButton(Icons.add, _incrementQty),
                ],
              ),
            ),
          ],
        ),
        ),
      ],
    );
  }

  Widget _stepperButton(IconData icon, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: radiusOf(ShapeTier.pill),
      child: Padding(
        padding: const EdgeInsets.all(8),
        child: Icon(icon, size: 16, color: AppColors.textPrimary),
      ),
    );
  }

  Widget _buildDescription(ThemeData theme) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Description', style: theme.textTheme.titleSmall),
        const SizedBox(height: 8),
        Text(
          _product.description.isEmpty ? 'No description provided yet.' : _product.description,
          style: theme.textTheme.bodyMedium?.copyWith(height: 1.5),
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
    final purchased =
        context.watch<OrderState>().purchasedProductIds.contains(_product.id);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('WHAT BUYERS SAY', style: AppType.eyebrow),
        const SizedBox(height: 10),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: AppColors.surfaceMuted,
            borderRadius: editorialRadius(0, ShapeTier.accent),
          ),
          child: Row(
            children: [
              // Display numeral, so the section reads as a genuine feature
              // rather than a plain form label.
              Text('${_product.ratingCount}', style: AppType.priceNumeral(34)),
              const SizedBox(width: 12),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    _product.ratingCount == 1 ? 'REVIEW' : 'REVIEWS',
                    style: AppType.eyebrow,
                  ),
                  const SizedBox(height: 4),
                  Row(
                    children: [
                      Icon(Icons.star_rounded,
                          size: 16, color: AppColors.ratingStar),
                      const SizedBox(width: 4),
                      Text(
                        '${_product.rating.toStringAsFixed(1)} average',
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        for (final review in myReviews) _ownReviewTile(theme, review),
        if (myReviews.isEmpty && _product.ratingCount == 0)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Text('No reviews yet — be the first to leave one.',
                style: theme.textTheme.bodySmall),
          ),
        // Only offered when there's something to review: you can't rate a
        // product you never received, and the button would have no order to
        // attach the review to.
        if (purchased && myReviews.isEmpty) ...[
          const SizedBox(height: 4),
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
    final orders = context
        .read<OrderState>()
        .resolvedOrders(context.read<CourierState>());
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
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
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
            const SizedBox(height: 8),
            Text(review.comment,
                style: theme.textTheme.bodySmall?.copyWith(height: 1.4)),
          ],
          if (review.photoPath != null) ...[
            const SizedBox(height: 10),
            ClipRRect(
              borderRadius: editorialRadius(0, ShapeTier.accent),
              child: Image.file(
                File(review.photoPath!),
                width: 88,
                height: 88,
                fit: BoxFit.cover,
                // The file is gone (cleared storage) — a label, not a broken
                // glyph.
                errorBuilder: (context, error, stack) => SizedBox(
                  width: 88,
                  height: 88,
                  child: Icon(Icons.image_not_supported_outlined,
                      color: AppColors.textSecondary),
                ),
              ),
            ),
          ],
          const SizedBox(height: 6),
          Row(
            children: [
              Text(
                '${written.day}/${written.month}/${written.year}',
                style: theme.textTheme.labelSmall
                    ?.copyWith(color: AppColors.textSecondary),
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
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 14, 20, 16),
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
        child: Row(
          children: [
            _circleIconButton(
              Icons.chat_bubble_outline,
              () => Navigator.of(context).push(
                AppPageRoute(
                  builder: (_) => ChatScreen(contactName: _product.sellerName, contactRole: 'Seller'),
                ),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () => _addToCart(showSnackBar: true),
                icon: const Icon(Icons.add_shopping_cart_outlined, size: 18),
                label: const Text('Add to Cart'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: ElevatedButton(
                onPressed: () {
                  if (!_addToCart(showSnackBar: false)) return;
                  Navigator.of(context).push(
                    AppPageRoute(builder: (_) => const CheckoutScreen()),
                  );
                },
                child: const Text('Buy Now'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
