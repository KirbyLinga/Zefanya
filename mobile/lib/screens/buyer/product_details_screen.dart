// lib/screens/buyer/product_details_screen.dart
//
// Buyer-facing Product Details screen, fully styled with the
// "Zefanya" theme tokens (see theme/app_theme.dart). Renders
// whichever Product is passed in (see data/mock_data.dart) rather
// than a single hardcoded item, and Add to Cart / Buy Now write to
// the shared CartState so Cart/Checkout reflect the same data.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';
import '../../data/mock_data.dart';
import '../../state/cart_state.dart';
import '../../state/recently_viewed_state.dart';
import '../../theme/app_theme.dart';
import '../shared/chat_screen.dart';
import 'cart_screen.dart';
import 'checkout_screen.dart';
import 'seller_screen.dart';
import 'widgets/product_card.dart';
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
        borderRadius: BorderRadius.circular(18),
        child: ProductImage(
          productId: _product.id,
          tint: productTint(_product.category),
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
                          _buildReviewsSection(theme, ext),
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
    // Uses the same tint helper + ProductImage as ProductCard so the
    // Hero flight between grid and details is showing the same photo
    // the whole time, not swapping mid-flight.
    final image = ProductImage(productId: _product.id, tint: productTint(_product.category), iconSize: 64);

    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 0),
      child: ClipRRect(
        // Asymmetric corners rather than a uniform cardRadius — same
        // shape language as the grid tiles this screen is entered from.
        borderRadius: editorialRadius(0, big: 48, small: 10),
        child: AspectRatio(
          aspectRatio: 1.05,
          child: KeyedSubtree(
            key: _galleryKey,
            child: widget.heroTag == null ? image : Hero(tag: widget.heroTag!, child: image),
          ),
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
            const Icon(Icons.star_rounded, size: 18, color: AppColors.ratingStar),
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
          const CircleAvatar(
            radius: 20,
            backgroundColor: AppColors.tertiary,
            child: Icon(Icons.storefront_outlined, color: AppColors.textOnDark, size: 20),
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
  static const Map<String, Color> _colorNames = {
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
      borderRadius: BorderRadius.circular(999),
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

  void _showAllReviews(BuildContext context) {
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;

    // Stub review data — replace with a real API call once the backend
    // is wired. Shown here as three entries so the sheet has enough
    // content to feel like a real screen rather than an empty slide.
    final names = ['Marielle S.', 'Jonah P.', 'Celine R.', 'Marco T.', 'Ana G.'];
    final comments = [
      'Fabric feels premium and true to what\'s described. Would repurchase.',
      'Arrived earlier than expected and well packaged. Very happy with it.',
      'The color matches the photos exactly. Fits true to size.',
      'Really good quality for the price. Will buy more colors.',
      'Seller was very responsive. Product came neatly folded.',
    ];
    final ratings = [5, 4, 5, 4, 5];

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => DraggableScrollableSheet(
        initialChildSize: 0.7,
        minChildSize: 0.4,
        maxChildSize: 0.95,
        expand: false,
        builder: (_, scrollController) => Container(
          decoration: BoxDecoration(
            color: AppColors.surface,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(28)),
          ),
          child: Column(
            children: [
              // Drag handle
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 12),
                child: Container(
                  width: 40,
                  height: 4,
                  decoration: BoxDecoration(
                    color: AppColors.divider,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 4, 20, 16),
                child: Row(
                  children: [
                    Text('Reviews', style: theme.textTheme.titleMedium),
                    const SizedBox(width: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                      decoration: BoxDecoration(
                        color: AppColors.surfaceMuted,
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: Text(
                        '${_product.ratingCount}',
                        style: theme.textTheme.labelSmall,
                      ),
                    ),
                    const Spacer(),
                    Row(
                      children: [
                        const Icon(Icons.star_rounded, size: 16, color: AppColors.ratingStar),
                        const SizedBox(width: 4),
                        Text(
                          _product.rating.toStringAsFixed(1),
                          style: theme.textTheme.titleSmall,
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              const Divider(height: 1),
              Expanded(
                child: ListView.builder(
                  controller: scrollController,
                  padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
                  itemCount: names.length,
                  itemBuilder: (_, i) => _allReviewTile(theme, ext, i, names[i], comments[i], ratings[i]),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _allReviewTile(ThemeData theme, AppThemeExtension ext, int i, String name, String comment, int rating) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(i, big: 20, small: 8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(name, style: theme.textTheme.labelLarge),
              const Spacer(),
              Row(
                children: List.generate(
                  5,
                  (star) => Icon(
                    Icons.star_rounded,
                    size: 14,
                    color: star < rating ? AppColors.ratingStar : AppColors.divider,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(comment, style: theme.textTheme.bodySmall?.copyWith(height: 1.4)),
        ],
      ),
    );
  }

  Widget _buildReviewsSection(ThemeData theme, AppThemeExtension ext) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // Reviews header: eyebrow + display numeral so the section
        // reads as a genuine feature, not a plain form label.
        Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('WHAT BUYERS SAY', style: AppType.eyebrow),
                  const SizedBox(height: 6),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.baseline,
                    textBaseline: TextBaseline.alphabetic,
                    children: [
                      Text('${_product.ratingCount}', style: AppType.priceNumeral(34)),
                      const SizedBox(width: 8),
                      Padding(
                        padding: const EdgeInsets.only(bottom: 4),
                        child: Text(
                          _product.ratingCount == 1 ? 'REVIEW' : 'REVIEWS',
                          style: AppType.eyebrow,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            TextButton(onPressed: () => _showAllReviews(context), child: const Text('See all')),
          ],
        ),
        const SizedBox(height: 8),
        if (_product.ratingCount == 0)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 12),
            child: Text('No reviews yet — be the first to leave one.', style: theme.textTheme.bodySmall),
          )
        else
          ...List.generate(2, (i) => _reviewTile(theme, ext, i)),
      ],
    );
  }

  Widget _reviewTile(ThemeData theme, AppThemeExtension ext, int i) {
    final names = ['Marielle S.', 'Jonah P.'];
    final comments = [
      'Fabric feels premium and true to what\'s described. Would repurchase.',
      'Arrived earlier than expected and well packaged. Very happy with it.',
    ];
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(i, big: 20, small: 8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(names[i], style: theme.textTheme.labelLarge),
              const Spacer(),
              Row(
                children: List.generate(
                  5,
                  (star) => Icon(
                    Icons.star_rounded,
                    size: 14,
                    color: star < 5 - i ? AppColors.ratingStar : AppColors.divider,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(comments[i], style: theme.textTheme.bodySmall?.copyWith(height: 1.4)),
        ],
      ),
    );
  }

  Widget _buildBottomActionBar(BuildContext context, ThemeData theme) {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 14, 20, 16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: const BorderRadius.only(
          topLeft: Radius.circular(10),
          topRight: Radius.circular(28),
        ),
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
