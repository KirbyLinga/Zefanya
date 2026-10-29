// lib/state/review_state.dart
//
// Reviews this buyer has actually written.
//
// ## Why this file exists
//
// "Leave a Review" was a live, enabled button with `onPressed: () {}` in two
// places (order_detail_screen.dart and orders_status_screen.dart). The app
// spent a lot of care making the courier's completion moment honest — no
// celebration for a return, a fee you can check — and then handed the buyer a
// button that silently did nothing. That is the failure mode worth fixing
// before anything cosmetic: a button that looks actionable and isn't teaches
// a general lesson about the app, not a specific one about reviews.
//
// ## Scope, stated honestly
//
// This holds *your* reviews, not everybody's. The storefront's aggregate
// (`Product.rating` / `Product.ratingCount`) stays catalog data — a single
// server number this app doesn't own and can't re-derive. So the two are
// deliberately NOT merged: a screen showing the aggregate is showing what the
// catalogue says, and one showing a review is showing one this device wrote,
// badged as such. Inventing a blend would have every screen disagree with
// every other one.
//
// That also means the reviews on a product page are only ever yours until a
// reviews API exists. The alternative — what was there before — was two
// hardcoded tiles with invented names ('Marielle S.', 'Jonah P.'), which is a
// claim about human specificity with nothing behind it.
//
// Persisted through the [ReviewRepository]: the write, the storage key and the
// JSON shape all live there now (mock mode keeps them in shared_preferences,
// unchanged, so an existing install's reviews survive), and this class holds
// only the loaded copy the synchronous getters below read.

import 'package:flutter/foundation.dart';
import '../data/repositories/review_repository.dart';

/// The workbook's limits, kept beside the review model so the form, the mapper
/// and tests cannot drift apart.
const int reviewPhotoLimit = 5;
const int reviewCommentLimit = 500;

/// One review, for one product, from one order.
///
/// Order id is part of the identity, not decoration: the same buyer can buy
/// the same product twice, and "which purchase am I rating" is the whole
/// reason the button appears on a delivered order. It's also what makes
/// re-submitting an edit rather than a duplicate.
class ProductReview {
  const ProductReview({
    required this.productId,
    required this.orderId,
    required this.stars,
    required this.createdAt,
    this.comment = '',
    this.photoPath,
    this.photoPaths = const [],
    this.editCount = 0,
  });

  final String productId;
  final String orderId;

  /// 1–5. The form only submits at 1 or above, so this is never 0 in stored
  /// data — a 0 here would mean "rated nothing", which isn't a rating.
  final int stars;

  /// Optional free text. Empty string rather than null so every consumer can
  /// treat it as "no comment" without a null check.
  final String comment;

  /// Local file path from pickAndSaveImage (see utils/image_picker_helper.dart),
  /// already copied into app storage before being stored here — the picker's
  /// own path can point at a temp location the OS may clear.
  ///
  /// Kept as the first entry of [photoPaths] for older stored reviews and the
  /// live API's single-photo field. New writes populate [photoPaths].
  final String? photoPath;

  /// Up to five local photos, in the order the buyer attached them.
  final List<String> photoPaths;

  /// How many times this review has been edited. The first write is 0; the
  /// workbook's rule is one edit inside the 30-day window, so a stored 1 means
  /// the edit action exists but can no longer save.
  final int editCount;

  /// Every attached photo, including a legacy single [photoPath].
  List<String> get photos {
    if (photoPaths.isNotEmpty) return List.unmodifiable(photoPaths);
    final single = photoPath;
    return single == null ? const [] : List.unmodifiable([single]);
  }

  final DateTime createdAt;

  ProductReview copyWith({
    int? stars,
    String? comment,
    String? photoPath,
    List<String>? photoPaths,
    DateTime? createdAt,
    int? editCount,
  }) =>
      ProductReview(
        productId: productId,
        orderId: orderId,
        stars: stars ?? this.stars,
        comment: comment ?? this.comment,
        photoPath: photoPath ?? this.photoPath,
        photoPaths: photoPaths ?? this.photoPaths,
        createdAt: createdAt ?? this.createdAt,
        editCount: editCount ?? this.editCount,
      );

  Map<String, dynamic> toJson() => {
        'productId': productId,
        'orderId': orderId,
        'stars': stars,
        'comment': comment,
        'photoPath': photoPath,
        'photoPaths': photoPaths,
        'createdAt': createdAt.toIso8601String(),
        'editCount': editCount,
      };

  /// Returns null for anything unreadable rather than throwing — a single
  /// corrupt entry should cost one review, not the whole list.
  static ProductReview? fromJson(Map<String, dynamic> map) {
    final productId = map['productId'];
    final orderId = map['orderId'];
    final stars = map['stars'];
    final createdAt = DateTime.tryParse(map['createdAt'] as String? ?? '');
    if (productId is! String || orderId is! String || stars is! int || createdAt == null) {
      return null;
    }
    final photoPath = map['photoPath'] as String?;
    final rawPhotos = map['photoPaths'];
    final photoPaths = rawPhotos is List
        ? rawPhotos.whereType<String>().toList(growable: false)
        : const <String>[];
    return ProductReview(
      productId: productId,
      orderId: orderId,
      stars: stars,
      comment: map['comment'] as String? ?? '',
      photoPath: photoPath,
      photoPaths: photoPaths,
      editCount: map['editCount'] as int? ?? 0,
      createdAt: createdAt,
    );
  }
}

/// The edit window/one-edit rule refused a submission.
///
/// A typed exception rather than a bool so the screen can show the exact
/// sentence, and so a future server 409 can be mapped to the same UI.
class ReviewEditException implements Exception {
  const ReviewEditException(this.message);

  final String message;

  @override
  String toString() => message;
}

class ReviewState extends ChangeNotifier {
  /// [repository] defaults to the mock implementation so existing call sites
  /// (and tests) are unaffected; `main.dart` passes the resolved bundle's.
  ///
  /// A repository rather than this class owning its own `shared_preferences`
  /// entry — see the note on `load()`. Two stores would be two sources of
  /// truth for the same data, which is exactly the kind of drift this pass is
  /// meant to remove rather than introduce.
  ReviewState({ReviewRepository? repository})
      : _repository = repository ?? MockReviewRepository();

  final ReviewRepository _repository;

  /// Newest first, matching the order the screens read them in.
  ///
  /// A cache over the repository's answer, filled by [load] and [submit]. The
  /// getters below are synchronous because they are called from `build()`.
  List<ProductReview> _reviews = const [];

  List<ProductReview> get all => List.unmodifiable(_reviews);

  /// Everything this buyer has written about one product — normally 0 or 1,
  /// more if they bought it on separate orders and rated each purchase.
  List<ProductReview> forProduct(String productId) =>
      _reviews.where((r) => r.productId == productId).toList(growable: false);

  /// The review for one specific purchase of one product, if it exists. This
  /// is what makes the form open pre-filled instead of blank.
  ProductReview? forOrderProduct(String orderId, String productId) {
    for (final review in _reviews) {
      if (review.orderId == orderId && review.productId == productId) return review;
    }
    return null;
  }

  List<ProductReview> forOrder(String orderId) =>
      _reviews.where((r) => r.orderId == orderId).toList(growable: false);

  /// Whether this order has been reviewed at all. Orders are submitted as a
  /// whole (every item on one form), so this is all-or-nothing by
  /// construction — there's no half-reviewed order to represent.
  bool hasReviewedOrder(String orderId) => _reviews.any((r) => r.orderId == orderId);

  /// Call once before runApp (see main.dart).
  ///
  /// The persistence format lives in [ProductReview.toJson] / `fromJson` and is
  /// read and written by the repository, so an existing install's stored
  /// reviews keep parsing after this move.
  Future<void> load() async {
    await _repository.load();
    _reviews = await _repository.all();
  }

  /// Why this existing review can no longer be edited, or null when it can.
  ///
  /// [now] is injectable so the 30-day boundary is testable without waiting a
  /// month. New reviews return null: "edit" only has a meaning when there is
  /// already a first write.
  String? editRefusal(
    String orderId,
    String productId, {
    DateTime? now,
  }) {
    final existing = forOrderProduct(orderId, productId);
    if (existing == null) return null;
    if (existing.editCount >= 1) {
      return 'This review has already been edited once.';
    }
    final elapsed = (now ?? DateTime.now()).difference(existing.createdAt);
    if (elapsed.inDays >= 30) {
      return 'Reviews can only be edited within 30 days.';
    }
    return null;
  }

  /// Adds a review, or replaces the existing one for the same purchase.
  ///
  /// Replacing rather than appending is what makes "Edit Your Review" an edit
  /// and not a second review of the same purchase — and it is the behaviour the
  /// repository is required to implement too (see `ReviewRepository.submit`),
  /// because in live mode the replacement happens on the server.
  ///
  /// The one-edit / 30-day rule is enforced here, at the write seam, rather
  /// than only in the form: a hidden button is not a rule, and a direct call
  /// from another screen must be refused too. The original [ProductReview.createdAt]
  /// is preserved across an edit so the window does not restart every time.
  ///
  /// A failure propagates: the review form renders the error rather than
  /// appearing to save something that was not saved.
  Future<void> submit(ProductReview review) async {
    final existing = forOrderProduct(review.orderId, review.productId);
    final refusal = editRefusal(review.orderId, review.productId);
    if (refusal != null) throw ReviewEditException(refusal);

    final toSave = existing == null
        ? review
        : review.copyWith(
            createdAt: existing.createdAt,
            editCount: existing.editCount + 1,
          );

    await _repository.submit(toSave);
    _reviews = await _repository.all();
    notifyListeners();
  }
}