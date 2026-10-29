// lib/screens/buyer/review_screen.dart
//
// Where "Leave a Review" finally goes.
//
// ## What this replaces
//
// Two buttons, in order_detail_screen.dart and orders_status_screen.dart, both
// with `onPressed: () {}` — enabled, styled as the primary action on a
// delivered order, and inert. `OrderStatus.toRate` IS the delivered state, so
// the Orders tab's terminal tab was named after a status whose only action did
// nothing.
//
// ## Shape of the form
//
// One screen per order, not per product: a marketplace order is one seller's
// parcel, and a buyer who received it forms one opinion while opening it. So
// every item in the order gets its own stars and its own optional comment, and
// they submit together. Re-opening it later is an edit — see
// ReviewState.submit, which replaces rather than appends.
//
// Stars alone are enough to submit, deliberately. A review that requires text
// is a review fewer people leave, and the rating is the part sellers and other
// buyers actually aggregate. The comment and the photo are both optional and
// both labelled as such, so the cheapest honest contribution is one tap.
//
// ## Why the body scrolls
//
// The same lesson as the courier's completion screen: an order with two or
// three items is a tall fixed-height stack, and at the app's maximum 1.3x text
// scale on a short phone it overflows. A ListView costs nothing and removes
// the whole class of bug.

import 'dart:io';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../data/mock_data.dart';
import '../../state/courier_state.dart';
import '../../state/review_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/image_picker_helper.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/success_flourish.dart';
import 'widgets/product_card.dart';

class ReviewScreen extends StatefulWidget {
  const ReviewScreen({super.key, required this.order, required this.products});

  /// The purchase being reviewed — its id is what makes this an edit rather
  /// than a duplicate if it's opened twice.
  final Order order;

  /// The order's catalogue items. Never empty: the callers only offer this
  /// screen when they could resolve at least one, because a review form with
  /// nothing to rate is the dead end this file exists to remove.
  final List<Product> products;

  @override
  State<ReviewScreen> createState() => _ReviewScreenState();
}

/// What each rating means, in words. Shown under the stars so the choice is
/// confirmed in language as well as in gold — colour alone is never the only
/// signal here.
const Map<int, String> _ratingWords = {
  1: 'Poor',
  2: 'Fair',
  3: 'Good',
  4: 'Great',
  5: 'Loved it',
};

class _ReviewScreenState extends State<ReviewScreen> {
  /// Draft state per product id.
  final Map<String, int> _stars = {};
  final Map<String, TextEditingController> _comments = {};
  final Map<String, List<String>> _photoPaths = {};

  /// The buyer's rating of whoever carried this order, 0 = not rated.
  ///
  /// Optional, unlike the product stars above it: a delivery can be perfectly
  /// fine with nobody worth remarking on, and requiring a courier score to
  /// submit a product review would make the courier the price of the product
  /// feedback. Only shown at all when a named rider actually carried it.
  int _courierStars = 0;

  bool _submitted = false;
  bool _submitting = false;
  String? _error;

  /// The workbook's ceiling is enforced by the form and the shared model
  /// constants.
  static const int maxPhotos = reviewPhotoLimit;
  static const int maxCommentLength = reviewCommentLimit;

  @override
  void initState() {
    super.initState();
    // Pre-fill from any existing review, so opening this from "Edit Your
    // Review" shows what was written rather than an empty form that would
    // silently overwrite it. context.read (not watch) is the documented-safe
    // call in initState.
    final existing = context.read<ReviewState>();
    for (final product in widget.products) {
      final review = existing.forOrderProduct(widget.order.id, product.id);
      _stars[product.id] = review?.stars ?? 0;
      _comments[product.id] = TextEditingController(text: review?.comment ?? '');
      _photoPaths[product.id] = [...?review?.photos];
    }

    // Pre-fill the courier rating too, so re-opening a review to change a
    // product comment doesn't silently drop the courier score that was given
    // the first time.
    final job = context.read<CourierState>().jobForOrder(widget.order.id);
    if (job != null) {
      _courierStars = context.read<CourierState>().ratingFor(job.id) ?? 0;
    }
  }

  @override
  void dispose() {
    for (final controller in _comments.values) {
      controller.dispose();
    }
    super.dispose();
  }

  /// Every item needs a rating. Not a "nice to have" rule: a review with no
  /// stars is a comment, and the aggregate the catalogue shows is built from
  /// stars. The courier score is not part of this — see [_courierStars].
  bool get _canSubmit =>
      widget.products.every((product) => (_stars[product.id] ?? 0) > 0);

  /// The delivery job that carried this order, if a real checkout dispatched
  /// one. Null for the seeded sample history.
  DeliveryJob? get _job => context.read<CourierState>().jobForOrder(widget.order.id);

  /// Whether to offer a courier rating — only when a named rider actually
  /// carried the parcel. Rating a rider who was never assigned would be
  /// scoring nobody.
  bool get _canRateCourier {
    final job = _job;
    return job != null && job.courierName != null;
  }

  Future<void> _submit() async {
    if (_submitting) return;
    final reviews = context.read<ReviewState>();
    // Both read before the awaits below: BuildContext must not be used after
    // an async gap, and the courier score is written after the loop.
    final courier = context.read<CourierState>();
    final job = courier.jobForOrder(widget.order.id);
    final now = DateTime.now();

    // Check every item before writing any: submitting three products and
    // failing on the third would leave a half-edited order behind.
    for (final product in widget.products) {
      final refusal =
          reviews.editRefusal(widget.order.id, product.id, now: now);
      if (refusal != null) {
        setState(() => _error = refusal);
        return;
      }
    }

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      for (final product in widget.products) {
        final paths = List<String>.unmodifiable(
          _photoPaths[product.id] ?? const [],
        );
        await reviews.submit(
          ProductReview(
            productId: product.id,
            orderId: widget.order.id,
            stars: _stars[product.id]!,
            comment: _comments[product.id]!.text.trim(),
            photoPath: paths.isEmpty ? null : paths.first,
            photoPaths: paths,
            // Stamped once for the whole submission rather than per item, so
            // the items in one review can't drift apart by a timestamp.
            createdAt: now,
          ),
        );
      }
      // The courier score rides along with the review rather than being its own
      // screen: this is already the app's post-delivery moment, and a second
      // "rate your rider" prompt a day later is the kind of thing people learn
      // to dismiss.
      if (_courierStars > 0 && job != null) {
        courier.rateJob(job.id, _courierStars);
      }
      if (!mounted) return;
      setState(() => _submitted = true);
    } on ReviewEditException catch (error) {
      if (mounted) setState(() => _error = error.message);
    } catch (_) {
      if (mounted) {
        setState(() => _error = 'Your review could not be saved. Try again.');
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: SafeArea(
        child: _submitted ? _confirmation(context) : _form(context),
      ),
    );
  }

  Widget _form(BuildContext context) {
    final theme = Theme.of(context);

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 4, 20, 32),
      children: [
        Text('${widget.order.shopName} · ${widget.order.id}',
            style: AppType.eyebrow),
        const SizedBox(height: 8),
        Text(widget.products.length == 1 ? 'How was it?' : 'How were they?',
            style: AppType.sectionDisplay),
        const SizedBox(height: 8),
        Text(
          'Your rating helps other buyers — and the maker. A few words are '
          'optional.',
          style: theme.textTheme.bodyMedium?.copyWith(color: AppColors.textSecondary),
        ),
        const SizedBox(height: 22),
        for (var i = 0; i < widget.products.length; i++)
          _productForm(theme, widget.products[i], i),
        if (_canRateCourier) _courierForm(theme),
        if (_error != null) ...[
          const SizedBox(height: 4),
          Text(
            _error!,
            key: const ValueKey('review-error'),
            style: theme.textTheme.bodySmall?.copyWith(color: AppColors.danger),
          ),
        ],
        if (!_canSubmit) ...[
          const SizedBox(height: 4),
          Center(
            child: Text(
              widget.products.length == 1
                  ? 'Tap a star to rate this item.'
                  : 'Tap a star for each item.',
              style: theme.textTheme.bodySmall?.copyWith(color: AppColors.textSecondary),
            ),
          ),
          const SizedBox(height: 10),
        ],
        const SizedBox(height: 4),
        ElevatedButton(
          // Disabled rather than silently refusing on tap: the hint above
          // says exactly what's missing, so the state is never a mystery.
          onPressed: _canSubmit && !_submitting ? _submit : null,
          child: Text(_submitting ? 'Submitting…' : 'Submit Review'),
        ),
      ],
    );
  }

  /// The courier, rated separately from the goods and labelled optional.
  ///
  /// Deliberately a small card after the products rather than a second hero
  /// section: the parcel's contents are what the buyer came to this screen to
  /// talk about, and the person who carried it is a different question with a
  /// different answer.
  ///
  /// The rider's name is shown because it's the reason the rating means
  /// anything — a score attached to nobody in particular isn't feedback.
  Widget _courierForm(ThemeData theme) {
    final job = _job!;
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: editorialRadius(1, ShapeTier.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('YOUR COURIER', style: AppType.eyebrow),
          const SizedBox(height: 6),
          Text(job.courierName!, style: theme.textTheme.titleSmall),
          const SizedBox(height: 12),
          _StarPicker(
            value: _courierStars,
            onChanged: (value) => setState(() => _courierStars = value),
          ),
          const SizedBox(height: 8),
          Text(
            _courierStars == 0
                ? 'Optional — how was the delivery?'
                : '${_ratingWords[_courierStars]}',
            style: theme.textTheme.labelMedium?.copyWith(
              color: _courierStars == 0
                  ? AppColors.textSecondary
                  : AppColors.textPrimary,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }

  Widget _productForm(ThemeData theme, Product product, int index) {
    final stars = _stars[product.id] ?? 0;
    final photoPaths = _photoPaths[product.id] ?? const <String>[];

    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: editorialRadius(index, ShapeTier.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              ClipRRect(
                borderRadius: editorialRadius(index, ShapeTier.accent),
                child: SizedBox(
                  width: 56,
                  height: 56,
                  child: ProductImage(product: product, iconSize: 20),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      product.name,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      product.sellerName,
                      style: theme.textTheme.bodySmall
                          ?.copyWith(color: AppColors.textSecondary),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          _StarPicker(
            value: stars,
            onChanged: (value) => setState(() => _stars[product.id] = value),
          ),
          const SizedBox(height: 8),
          // The rating in words, so the choice is never communicated by
          // colour alone.
          Text(
            stars == 0 ? 'Tap a star' : '${_ratingWords[stars]}',
            style: theme.textTheme.labelMedium?.copyWith(
              color: stars == 0 ? AppColors.textSecondary : AppColors.textPrimary,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 14),
          TextField(
            controller: _comments[product.id],
            maxLines: 4,
            maxLength: maxCommentLength,
            textCapitalization: TextCapitalization.sentences,
            decoration: const InputDecoration(
              hintText: 'What should other buyers know? (optional)',
            ),
          ),
          const SizedBox(height: 4),
          _photoPicker(theme, product, photoPaths),
        ],
      ),
    );
  }

  /// Attach a photo of what actually arrived — the same picker the courier's
  /// proof-of-delivery and the profile avatar already use, so this is one
  /// proven flow rather than a second one built beside it.
  ///
  /// The cap is checked before the picker opens, not after: asking someone to
  /// take a sixth photo and then discarding it is worse than not offering the
  /// button.
  Future<void> _attachPhoto(Product product) async {
    final current = _photoPaths[product.id] ?? const <String>[];
    if (current.length >= maxPhotos) return;
    final picked = await pickAndSaveImage(
      context,
      filenamePrefix: 'review_${product.id}',
    );
    if (picked == null || !mounted) return;
    setState(() {
      _photoPaths.putIfAbsent(product.id, () => []).add(picked.path);
    });
  }

  void _removePhoto(Product product, String path) {
    setState(() {
      _photoPaths[product.id]?.remove(path);
    });
  }

  Widget _photoPicker(
    ThemeData theme,
    Product product,
    List<String> photoPaths,
  ) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final path in photoPaths)
          _photoThumb(theme, product, path),
        if (photoPaths.length < maxPhotos)
          Semantics(
            button: true,
            label:
                'Add photo (${photoPaths.length} of $maxPhotos attached)',
            child: InkWell(
              key: ValueKey('review-photo-add-${product.id}'),
              onTap: () => _attachPhoto(product),
              borderRadius: editorialRadius(0, ShapeTier.accent),
              child: Container(
                width: 64,
                height: 64,
                decoration: BoxDecoration(
                  color: AppColors.surfaceMuted,
                  borderRadius: editorialRadius(0, ShapeTier.accent),
                  border: Border.all(color: AppColors.divider),
                ),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(
                      Icons.add_a_photo_outlined,
                      color: AppColors.primaryDark.withValues(alpha: 0.55),
                      size: 20,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      '${photoPaths.length}/$maxPhotos',
                      style: theme.textTheme.labelSmall?.copyWith(
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
      ],
    );
  }

  Widget _photoThumb(ThemeData theme, Product product, String path) {
    return SizedBox(
      width: 64,
      height: 64,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          ClipRRect(
            borderRadius: editorialRadius(0, ShapeTier.accent),
            child: Image.file(
              File(path),
              width: 64,
              height: 64,
              fit: BoxFit.cover,
              // The file is gone (cleared storage) — say so rather than
              // rendering a broken-image glyph.
              errorBuilder: (context, error, stack) => Container(
                width: 64,
                height: 64,
                color: AppColors.surfaceMuted,
                child: const Icon(Icons.image_not_supported_outlined),
              ),
            ),
          ),
          Positioned(
            top: -6,
            right: -6,
            child: Semantics(
              button: true,
              label: 'Remove photo',
              child: InkWell(
                onTap: () => _removePhoto(product, path),
                customBorder: const CircleBorder(),
                child: Container(
                  padding: const EdgeInsets.all(3),
                  decoration: BoxDecoration(
                    color: AppColors.surface,
                    shape: BoxShape.circle,
                    border: Border.all(color: AppColors.divider),
                  ),
                  child: const Icon(Icons.close, size: 14),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// The moment after submitting. A state swap rather than a SnackBar: this is
  /// a peer of "order placed" (both are the end of a deliberate act), and the
  /// app already answers those with a mark on the screen rather than a strip
  /// that slides away.
  Widget _confirmation(BuildContext context) {
    final theme = Theme.of(context);
    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 12, 24, 32),
      children: [
        const Center(child: SuccessFlourish(size: 88)),
        const SizedBox(height: 22),
        Text(
          'Thank you',
          textAlign: TextAlign.center,
          style: AppType.sectionDisplay,
        ),
        const SizedBox(height: 10),
        Text(
          'Your review now shows on ${widget.order.shopName}\'s product '
          '${widget.products.length == 1 ? 'page' : 'pages'}.',
          textAlign: TextAlign.center,
          style: theme.textTheme.bodyMedium?.copyWith(color: AppColors.textSecondary),
        ),
        const SizedBox(height: 24),
        for (final product in widget.products)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Row(
              children: [
                Expanded(
                  child: Text(product.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodyMedium),
                ),
                const SizedBox(width: 10),
                // Echo back what was submitted, so the confirmation states a
                // fact rather than just a thank-you.
                Row(
                  children: List.generate(
                    5,
                    (i) => Icon(
                      i < (_stars[product.id] ?? 0)
                          ? Icons.star_rounded
                          : Icons.star_border_rounded,
                      size: 16,
                      color: i < (_stars[product.id] ?? 0)
                          ? AppColors.ratingStar
                          : AppColors.textSecondary,
                    ),
                  ),
                ),
              ],
            ),
          ),
        const SizedBox(height: 8),
        Text(
          'You can edit it once within 30 days.',
          textAlign: TextAlign.center,
          style: theme.textTheme.bodySmall?.copyWith(color: AppColors.textSecondary),
        ),
        const SizedBox(height: 26),
        ElevatedButton(
          // Provider rebuilds the order screens from ReviewState, so there's
          // nothing to hand back — this just leaves.
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Done'),
        ),
      ],
    );
  }
}

/// Five tappable stars.
///
/// Two things this does that the app's existing star *displays* didn't:
///
///  * Every star is its own semantic button with a label ("4 stars") and a
///    `selected` flag on the current choice, so the rating is settable by a
///    screen reader. Nine `Semantics` calls existed in the whole app before
///    this; a rating control you can't operate without sight is the version of
///    this screen that would have shipped by default.
///  * Tap targets are 46px, not the 34px the icon draws at.
///
/// The gold now clears 3:1 on both grounds (see AppPalette.light.ratingStar,
/// which measures 3.17:1; dark is 8.58:1). At 1.84:1 the chosen star was hard
/// to see at all on a light ground — a control whose selected state you have
/// to hunt for isn't a control.
class _StarPicker extends StatelessWidget {
  const _StarPicker({required this.value, required this.onChanged});

  /// 0 = not rated yet.
  final int value;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Row(
      children: [
        for (var star = 1; star <= 5; star++)
          Semantics(
            button: true,
            selected: star == value,
            label: star == 1 ? '1 star' : '$star stars',
            child: ExcludeSemantics(
              child: InkResponse(
                onTap: () => onChanged(star),
                radius: 26,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
                  child: Icon(
                    star <= value ? Icons.star_rounded : Icons.star_border_rounded,
                    size: 34,
                    // Empty stars are outline *and* a legible neutral, not a
                    // pale rule: an unrated star still has to be visible
                    // enough to know it's there to be tapped.
                    color: star <= value ? AppColors.ratingStar : AppColors.textSecondary,
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}