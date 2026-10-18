// lib/data/dto/remote_catalog.dart
//
// The catalogue's wire -> app mapping: `GET /products`, `/products/{id}`,
// `/categories` and `/vouchers`, as this app's own models.
//
// ## Why this is a file rather than code inside the repository
//
// For the same reason as `registration_payload.dart` — which does the *other*
// direction — the names the server uses are a contract, and a contract spelled
// out inside a repository is one nobody can read on its own. The job here is also
// subtler than renaming fields: four things the app's screens render have no
// server-side source at all, and each has to be answered once rather than
// differently at each call site.
//
// ## The gaps, and what happens at each
//
//  1. **A sale price.** `products` has no `compare_at_price` column, so
//     `Product.compareAtPrice` is null and the "ON SALE" badge does not render.
//     The web buyer page fakes a struck-through price as `price / 0.75`; a
//     discount that does not exist is the kind of claim this project's
//     no-invented-content rule forbids, so a live product is simply not on sale.
//  2. **Ratings.** `rating_count` and `rating_avg` are now real fields in the
//     server's response, computed from the `reviews` table. A product with no
//     reviews sends `0` for both, and the product page already handles that by
//     dropping its rating row rather than printing "0.0 ★ (0 reviews)". The
//     constructor's own defaults (4.5 stars) are the *mock* catalogue's numbers,
//     which is why they are overwritten explicitly — an average over no ratings
//     is not a fact about anything.
//  3. **The maker note.** Now present as `seller.maker_note` in every product
//     response. `productFromJson` reads it into `Product.sellerMakerNote`, and
//     `SellerStoryCard` uses it directly. A seller who has not written one sends
//     null, and the section hides — same rule as the mock.
//  4. **Voucher caps and scopes.** See [voucherFromJson] — the one gap here that
//     can cost money rather than merely look thin, answered by refusing to offer
//     what cannot be applied correctly.
//
// Money: every amount arrives in **centavos** and goes through `money.dart`,
// which is the only file in the app allowed to know the scale factor.

import '../mock_data.dart';
import 'money.dart';

/// One product from `GET /api/products`.
Product productFromJson(Map<String, Object?> json) => Product(
      id: _text(json['id']),
      name: _text(json['name']),
      category: _text(json['category']),
      // Centavos on the wire; `pesosFromJsonOr` is the one conversion.
      price: pesosFromJsonOr(json['price_minor'], 0),
      sellerName: _shopName(json['seller']),
      sellerMakerNote: _makerNote(json['seller']),
      description: _text(json['description']),
      // Cover first — the server orders it, and `Product.photos` falls back to
      // its own labelled placeholder when this is empty, so an empty list here
      // stays empty rather than becoming a stock photo.
      images: _textList(json['images']),
      // Not sent, so not shown. See gap 1 in the header.
      compareAtPrice: null,
      // Real aggregate from the reviews table, when there are any. The
      // constructor's own defaults are the *mock* catalogue's numbers (4.5
      // stars), which is why they are overwritten here explicitly instead of
      // left to default: an average over no ratings is not a fact about anything,
      // and a mock number on a live product would be a claim nobody made.
      rating: _number(json['rating_avg']),
      ratingCount: _int(json['rating_count']),
      soldCount: 0,
    );

/// One category from `GET /api/categories`, as the label a chip renders.
String categoryLabelFromJson(Map<String, Object?> json) => _text(json['label']);

/// One voucher from `GET /api/vouchers`, or **null** when this app cannot apply
/// it correctly.
///
/// ## Why this returns a nullable
///
/// A flat voucher means "₱200 off the goods". This app's pricing engine has no
/// such rule: `voucherDiscountFor` reads `discountRate <= 0` as a *shipping*
/// rebate, so a fixed voucher mapped into this model would silently become ₱200
/// off the delivery fee. That is a money bug that looks like working code, and it
/// is the exact failure `cart_repository.dart` refuses to pre-empt while the
/// pricing question (docs/api-contract.md §0.1/§0.2) is open. Refusing to offer a
/// voucher is visible and arguable; quietly applying the wrong one is neither.
///
/// A percentage voucher, by contrast, maps exactly: the server's "10% off" is
/// this engine's `discountRate: 0.10`.
Voucher? voucherFromJson(Map<String, Object?> json) {
  final code = _text(json['code']);
  final type = _text(json['type']);
  final value = _number(json['value']);

  // An unreadable value drops the voucher for the same reason: no discount is
  // better than a guessed one.
  if (code.isEmpty || type != 'percent' || value <= 0) return null;

  final percent = value.toStringAsFixed(0);

  return Voucher(
    code: code,
    // The server has no voucher names, so the title is the voucher's own fact
    // rather than a campaign name nobody wrote.
    title: '$percent% Off',
    discountLabel: '$percent% off',
    minSpend: pesosFromJsonOr(json['min_spend_minor'], 0),
    expiryLabel: _expiryLabel(json['expires_at']),
    // Parsed from the raw `expires_at`, never from the label string —
    // the label is display text ("Valid until Sep 30") and re-parsing
    // it would couple the countdown to copy.
    expiresAt: _expiryDate(json['expires_at']),
    discountRate: value / 100,
    // The server caps nothing, and `double.infinity` says exactly that —
    // `voucherDiscountFor` clamps to it, and clamping to infinity is not
    // clamping. A made-up ceiling here would be a number the server never said.
    maxDiscount: double.infinity,
  );
}

/// A display label for a voucher's expiry, or `'No expiry'`.
///
/// `Voucher.expiryLabel` is required display text and the server's expiry is
/// optional, so both cases get words rather than an empty string.
String _expiryLabel(Object? value) {
  final raw = value?.toString();
  final parsed = raw == null || raw.isEmpty ? null : DateTime.tryParse(raw);
  if (parsed == null) return 'No expiry';
  // Local, not the ISO offset: an expiry is a moment the person experiences, so
  // "Dec 31" should be their Dec 31.
  final local = parsed.toLocal();
  return 'Valid until ${_months[local.month - 1]} ${local.day}';
}

/// The raw expiry moment behind [_expiryLabel], or null when the server
/// sent nothing parseable. Kept as a separate parse (not derived from
/// the label) so the countdown never depends on display copy.
DateTime? _expiryDate(Object? value) {
  final raw = value?.toString();
  if (raw == null || raw.isEmpty) return null;
  return DateTime.tryParse(raw);
}

/// Spelled here rather than pulled in from `intl`: this is the only server date
/// the app formats, and it already spells months out locally
/// (`courier_profit_screen.dart`).
const List<String> _months = [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
];

String _text(Object? value) => value?.toString().trim() ?? '';

/// The shop name out of the nested `seller` object, tolerant of its absence.
String _shopName(Object? seller) => seller is Map ? _text(seller['name']) : '';

/// The maker note out of the nested `seller` object, or null when absent.
///
/// An empty string is treated as null — a seller who saved a blank note
/// should behave the same as one who never set one: the section hides.
String? _makerNote(Object? seller) {
  if (seller is! Map) return null;
  final raw = seller['maker_note']?.toString().trim();
  if (raw == null || raw.isEmpty) return null;
  return raw;
}

List<String> _textList(Object? value) {
  if (value is! List) return const [];
  return [
    for (final entry in value)
      if (entry is String) entry,
  ];
}

/// A number from a JSON value that may arrive as a number or a string.
///
/// PHP's encoder emits both shapes for the same column depending on how the value
/// left the database, and rejecting one would fail a whole response over a
/// difference with no meaning — the rule `money.dart` already states.
double _number(Object? value) {
  if (value is num) return value.toDouble();
  if (value is String) return double.tryParse(value) ?? 0;
  return 0;
}

/// An integer from a JSON value that may arrive as a number or a string.
int _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value) ?? 0;
  return 0;
}
