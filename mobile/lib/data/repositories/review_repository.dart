// lib/data/repositories/review_repository.dart
//
// Reviews this buyer has written.
//
// ## The honest scope, which is the same as `ReviewState`'s
//
// There is no reviews table on the Laravel side yet (it is one of the four the
// brief lists as not existing). So the *store* stays device-local, and this
// repository is the seam that will change when the table arrives — with one
// behaviour that is worth stating because it is what makes "Edit Your Review"
// an edit rather than a second review:
//
//   **Submitting a review for a purchase that already has one replaces it.**
//
// The interface says `submit`, not `add`, and the mock implements replacement.
// A live implementation must do the same — the contract specifies `PUT
// /reviews/{id}` semantics on the purchase (order + product), not an append.
//
// ## Why your reviews are not merged with the aggregate
//
// `Product.rating` / `ratingCount` are catalogue data: a server-owned average
// this app cannot re-derive. Written reviews are labelled `YOUR REVIEW`. The two
// are shown as separate blocks on purpose — inventing a blend would make every
// screen disagree with every other one. Moving to a real reviews API does not
// change that: the aggregate stays the server's number.

import '../../state/review_state.dart';
import '../api/api_exception.dart';

import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import 'live_stub.dart';

abstract class ReviewRepository {
  /// Restores local reviews before the first frame (see `main()`).
  Future<void> load();

  /// Everything this buyer has written, newest first.
  Future<List<ProductReview>> all();

  /// Adds or replaces the review for one purchase of one product.
  Future<void> submit(ProductReview review);
}

// ---------------------------------------------------------------------------
// Mock
// ---------------------------------------------------------------------------

class MockReviewRepository implements ReviewRepository {
  static const _storageKey = 'product_reviews';

  /// Newest first, matching the order screens read them in.
  final List<ProductReview> _reviews = [];

  @override
  Future<void> load() async {
    // The format lives on [ProductReview] itself (toJson/fromJson), which is
    // where it has always lived — so this reads what the old state class wrote
    // and an existing install's reviews survive the move.
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_storageKey);
      if (raw == null) return;
      final decoded =
          (jsonDecode(raw) as List<dynamic>).cast<Map<String, dynamic>>();
      _reviews
        ..clear()
        ..addAll(decoded.map(ProductReview.fromJson).whereType<ProductReview>());
    } catch (_) {
      // Corrupt or old shape — one bad entry should cost the list, not the app.
      _reviews.clear();
    }
  }

  @override
  Future<List<ProductReview>> all() async => List.unmodifiable(_reviews);

  @override
  Future<void> submit(ProductReview review) async {
    _reviews.removeWhere(
      (r) => r.orderId == review.orderId && r.productId == review.productId,
    );
    _reviews.insert(0, review);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
      _storageKey,
      jsonEncode([for (final r in _reviews) r.toJson()]),
    );
  }
}

// ---------------------------------------------------------------------------
// Live
// ---------------------------------------------------------------------------

class LiveReviewRepository extends LiveRepository implements ReviewRepository {
  LiveReviewRepository(super.client);

  @override
  Future<void> load() async {
    // Nothing local to restore; the server owns the reviews.
  }

  @override
  Future<List<ProductReview>> all() {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('GET /reviews/mine', section: '§8.1');
  }

  @override
  Future<void> submit(ProductReview review) {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('PUT /orders/{order}/items/{product}/review', section: '§8.2');
  }
}