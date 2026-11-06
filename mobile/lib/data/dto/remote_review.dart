// lib/data/dto/remote_review.dart
//
// Server-shape → ProductReview mapper.
//
// ## The wire shape (from ReviewResource)
//
//   {
//     "id":         "12",            // server row id, ignored by the app
//     "order_id":   "7",             // seller_order_id — what the app calls orderId
//     "product_id": "3",             // string, or null if the product was removed
//     "stars":      4,
//     "comment":    "Lovely bowls.", // empty string when none
//     "photo_url":  null,            // absolute URL or null
//     "created_at": "2026-10-05T14:23:00.000000Z"
//   }
//
// ## What gets dropped
//
// `id` — the server's row id. The app keys a review by `(orderId, productId)`,
// which is what makes re-submitting an edit rather than a duplicate. The row id
// adds nothing and is not carried into `ProductReview`.
//
// `photo_url` — the app's `ProductReview` stores a local `photoPath` (a file on
// the device), not a server URL. Until photo upload lands, the server returns
// null here and the app ignores it. When upload lands, the app will display
// `photo_url` on a server-backed review and only store `photoPath` for a locally
// taken one. That distinction is for the live repository to make when it comes.
//
// ## A null product_id
//
// A product removed after the review was written. The app calls this "contents
// unknown" for orders (empty productIds list); the same applies here — a review
// whose product is gone cannot appear on a product page, but it still exists in
// the history. The mapper returns null for those, and the repository filters them.

import '../../state/review_state.dart';

/// Maps one entry from `GET /api/reviews/mine` to a [ProductReview].
///
/// Returns null if any required field is absent or unparseable — a bad entry in
/// the list should cost one review, not the whole history. The same philosophy
/// as [ProductReview.fromJson] in the mock path.
ProductReview? reviewFromJson(Map<String, Object?> map) {
  final orderId = map['order_id']?.toString().trim();
  final productId = map['product_id']?.toString().trim();
  final stars = map['stars'];
  final createdAt = DateTime.tryParse(map['created_at'] as String? ?? '');

  // A review with no product_id is a ghost — it has no product page to appear
  // on. Skip it silently: the buyer already submitted it, and there is nothing
  // actionable to show. If the product comes back it will reappear.
  if (orderId == null ||
      orderId.isEmpty ||
      productId == null ||
      productId.isEmpty ||
      stars is! int ||
      createdAt == null) {
    return null;
  }

  return ProductReview(
    orderId: orderId,
    productId: productId,
    stars: stars,
    comment: map['comment'] as String? ?? '',
    // photo_url is deliberately not mapped to photoPath: photoPath is a local
    // file path, and a server URL is a different thing. Until photo upload
    // lands, this is always null from the server and the app's local photo
    // (if any) is what shows on the review form.
    photoPath: null,
    createdAt: createdAt,
  );
}
