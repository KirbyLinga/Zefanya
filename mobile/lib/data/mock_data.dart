// lib/data/mock_data.dart
//
// Single shared source of sample data for the whole app. Buyer screens
// (Home, Product Details, Cart, Orders) and Courier screens (Dashboard)
// all pull from here instead of each keeping their own private lists —
// that's what was making the same "Linen Blend Wrap Dress" show
// different prices/sellers depending which screen you were on.
//
// TODO: once the Laravel API is wired up, replace these constant lists
// with real fetches, but keep the Product / DeliveryJob shapes similar
// so screens don't need much rework.
//
// This file imports Flutter for exactly one reason: a promo slide's icon
// is part of its content (a florist motif vs a shipping motif), so
// [PromoSlide] carries an [IconData]. Nothing else here is framework-aware.

import 'package:flutter/material.dart';

/// One dimension a product varies on — "Color", "Size", "Set", and so on.
///
/// [values] stays in the seller's display order. The product screen renders
/// one control per type and requires a value from each before the item can be
/// added to the cart.
class VariationType {
  const VariationType({required this.name, required this.values});

  final String name;
  final List<String> values;
}

/// One purchasable combination of a product's [VariationType]s.
///
/// The old [Product.variants] list was enough for one-dimensional catalogues,
/// but it could not express what a real SKU does: a price that changes with the
/// combination, stock that belongs to that combination, and an image for that
/// colour. Both shapes live together so the existing mock products, live
/// mapper, and one-dimensional controls keep working unchanged.
class ProductVariant {
  const ProductVariant({
    required this.selections,
    required this.price,
    required this.stock,
    this.image,
  });

  /// Maps a [VariationType.name] to one of its values. Insertion order follows
  /// the product's own variation order, which is what [label] displays.
  final Map<String, String> selections;

  final double price;
  final int stock;
  final String? image;

  /// "Black / M", in the order the product declares its types.
  String get label => selections.values.join(' / ');
}

class Product {
  const Product({
    required this.id,
    required this.name,
    required this.category,
    required this.price,
    required this.sellerName,
    this.sellerMakerNote,
    this.compareAtPrice,
    this.rating = 4.5,
    this.ratingCount = 0,
    this.soldCount = 0,
    this.variants = const [],
    this.variationTypes = const [],
    this.variantSkus = const [],
    this.description = '',
    this.images = const [],
    this.stock = 24,
    this.shippingFee,
    this.purchaseLimit,
    this.sellerOnVacation = false,
  });

  final String id;
  final String name;
  final String category;
  final double price;
  final double? compareAtPrice;
  final String sellerName;

  /// The seller's own words for the "FROM THE MAKER" section, as received from
  /// the server. Null when the seller hasn't written one (live mode) or when
  /// the mock's `mockMakerNotes` map has no entry for this shop (mock mode).
  ///
  /// Screens read this rather than `makerNoteFor(sellerName)` in live mode, so
  /// the section is driven by the same data the server sent rather than a
  /// separate local lookup that can only match mock shop names.
  final String? sellerMakerNote;

  final double rating;
  final int ratingCount;
  final int soldCount;

  /// Legacy, one-dimensional option labels ("Small", "Blush", …).
  ///
  /// Kept because the live catalogue and most mock products only carry one
  /// dimension today. Products that need more than one use [variationTypes]
  /// plus [variantSkus]; see [optionGroups] for the shape the UI reads.
  final List<String> variants;

  /// Rich variation dimensions, in display order. Empty for legacy products
  /// that only use [variants].
  final List<VariationType> variationTypes;

  /// Per-combination price, stock and image. Empty when the product has no
  /// per-variant data — the screen then falls back to the product values.
  final List<ProductVariant> variantSkus;

  /// The variation controls to render, regardless of which model shape the
  /// product uses. A legacy flat list becomes one synthetic "Options" group.
  List<VariationType> get optionGroups {
    if (variationTypes.isNotEmpty) return variationTypes;
    if (variants.isEmpty) return const [];
    return [VariationType(name: 'Options', values: variants)];
  }

  /// True when this product has both dimensions and per-combination SKU data.
  bool get hasVariationMatrix =>
      variationTypes.isNotEmpty && variantSkus.isNotEmpty;

  /// The SKU matching [selections], or null when the combination doesn't exist.
  ProductVariant? variantForSelections(Map<String, String> selections) {
    if (variantSkus.isEmpty || selections.isEmpty) return null;
    for (final variant in variantSkus) {
      if (variant.selections.length != selections.length) continue;
      var matches = true;
      for (final entry in selections.entries) {
        if (variant.selections[entry.key] != entry.value) {
          matches = false;
          break;
        }
      }
      if (matches) return variant;
    }
    return null;
  }

  /// Resolves a cart line's stored variant label back to its SKU.
  ProductVariant? variantForLabel(String label) {
    if (label.isEmpty) return null;
    for (final variant in variantSkus) {
      if (variant.label == label) return variant;
    }
    return null;
  }

  final String description;

  /// This product's photos, **cover first**.
  ///
  /// ## This is a placeholder shape, and here is what it commits to
  ///
  /// A product has an ordered list of photos, and the first one is the cover —
  /// that is the whole contract, and it is the contract a Seller-side product
  /// upload screen would have to produce, so both sides can be built against
  /// it once rather than twice. Nothing here says *how many*; the layouts all
  /// degrade to a single photo, which is the case the app shipped with for far
  /// too long (see below).
  ///
  /// What is NOT settled, and shouldn't be guessed at in this file: whether a
  /// photo is a URL, a storage key, or an object with a caption and an
  /// ordering index, and whether sellers can reorder after upload. Those are
  /// the API's call, and this list of strings is deliberately the smallest
  /// thing that can express "an ordered set of photos per product". A
  /// per-variant image is now carried by [ProductVariant.image]; it overrides
  /// the cover while that combination is selected.
  ///
  /// ## Why it exists at all
  ///
  /// Every product in this catalogue had exactly one image, while the product
  /// page rendered a 1.05-aspect gallery and a "stacked" showcase — two
  /// layouts whose whole visual premise is *there is more than one photo of
  /// this thing*. That's the one place the mock data was actively lying to the
  /// UI rather than just being thin. Adding the list makes those layouts true.
  final List<String> images;

  /// The photos to render — [images] when the catalogue has them, otherwise a
  /// single placeholder derived from [id].
  ///
  /// The fallback lives here, on the product, rather than in the widgets: a
  /// product with no photos still has a cover, and no downstream call site has
  /// to special-case it. It's also why [images] can default to empty — a const
  /// constructor cannot derive a URL from [id], so a non-empty default isn't
  /// expressible.
  List<String> get photos =>
      images.isEmpty ? [placeholderPhoto(id, 0)] : images;

  /// Whether there is more than one photo to look through. The gallery asks
  /// this before drawing pager chrome, so a single-photo product gets no
  /// dots and no counter rather than a pager over one page.
  bool get hasGallery => photos.length > 1;

  /// Quantity available in inventory.
  final int stock;

  /// Flat shipping fee charged for this seller's parcel, when the catalogue
  /// supplies one.
  ///
  /// Null means "use the platform rule" in [shippingFeeFor]. A value here is
  /// deliberately *not* waived by the free-shipping threshold: some sellers
  /// quote a real courier rate (longer distance, fragile parcel) and the
  /// threshold is a platform promotion, not a promise that every parcel ships
  /// free. This is a mock-side shape; a live quote remains the server's job.
  final double? shippingFee;

  /// How many units one buyer may purchase across all orders, or null when
  /// there is no product-level cap.
  ///
  /// This is deliberately per buyer, not per cart: the check has to consult
  /// order history, which is why the product screen and checkout read
  /// `OrderState.purchasedQuantity` rather than only the current cart.
  final int? purchaseLimit;

  /// True while the seller has paused their store.
  ///
  /// Browsing stays available — the workbook wants the product visible with an
  /// "On vacation" label — but every add-to-cart path must refuse the item.
  final bool sellerOnVacation;
}

/// A placeholder photo URL for [seed] at [index].
///
/// Lorem Picsum is a stock-photo service built for exactly this: deterministic
/// random photography for dev/demo UIs, not licensed for production. Seeded so
/// the same product always shows the same photo instead of a new one on every
/// rebuild. Swap the whole mechanism for seller uploads when those exist —
/// this is scaffolding, not an asset pipeline.
String placeholderPhoto(String seed, int index) =>
    'https://picsum.photos/seed/$seed-$index/1000/1000';

/// Builds [count] placeholder photos for a catalogue entry, cover first.
List<String> mockPhotos(String seed, int count) =>
    List<String>.generate(count, (index) => placeholderPhoto(seed, index));

class DeliveryJob {
  const DeliveryJob({
    required this.id,
    required this.shopName,
    required this.pickupAddress,
    required this.buyerName,
    required this.deliveryArea,
    required this.packageSize,
    required this.fee,
    this.orderId,
    this.courierName,
    this.courierPhotoPath,
    this.sellerPhone,
    this.buyerPhone,
    this.trackingNumber,
    this.parcelCount = 1,
  });

  final String id;
  final String shopName;
  final String pickupAddress;
  final String buyerName;
  final String deliveryArea;
  final String packageSize;
  final double fee;

  /// The buyer order this job delivers, when it was created by a real
  /// checkout (see CourierState.dispatchForOrder). Null for the seeded
  /// sample jobs. This is the link that lets OrderState show the courier's
  /// progress on the buyer's Orders screen.
  final String? orderId;

  /// Who is carrying this parcel, once dispatch has assigned it.
  ///
  /// Null until then, and the null is meaningful rather than missing data:
  /// before a rider exists there is nobody to name, so the buyer's tracker
  /// says "not assigned yet" instead of inventing someone. It also means
  /// the buyer's app can be shown a name without ever having access to a
  /// courier's account — the assignment is data the API hands over, not a
  /// session the buyer can see into.
  ///
  /// Recorded at the `assignedToRider` step, which is where the ERP spec's
  /// dispatcher assigns a rider. See CourierState.advanceMilestone.
  final String? courierName;

  /// The delivering rider's profile photo, recorded alongside [courierName]
  /// at the same dispatch step.
  ///
  /// A local file path (see utils/image_picker_helper.dart, which copies it
  /// into app storage), not a URL — the same as [AuthState.avatarPath], which
  /// is where it comes from. Null is the common case and is rendered as a
  /// monogram of the rider's name rather than a generic glyph: an initial is
  /// derived from data that *is* present, whereas a stock silhouette would be
  /// a picture of nobody in particular standing in for a person.
  ///
  /// ## Consent, since this is a real photo of a real gig worker
  ///
  /// This is the rider's *own* profile photo, chosen by them in their own
  /// profile screen, and it is shown to one buyer about one parcel they are
  /// currently carrying. That is the narrowest honest version of the feature:
  /// nothing is uploaded, nothing is public, no buyer can browse a rider, and
  /// it stops being shown when the job does.
  final String? courierPhotoPath;

  /// Contact details the rider needs to do the pickup and delivery. They
  /// belong to the job, not to the courier's account: a rider must be able
  /// to call this seller/buyer without gaining access to anyone else's.
  /// Null means the server has not shared a number (the UI hides the
  /// action rather than inventing one).
  final String? sellerPhone;
  final String? buyerPhone;

  /// Waybill/reference the pickup scan or manual entry is checked against.
  /// The order id is a safe fallback when the server has not issued one.
  final String? trackingNumber;

  /// How many parcels this job covers. One is the common case.
  final int parcelCount;

  /// Only identity can change after dispatch — a job's route, buyer
  /// and fee are fixed when the seller confirms it. (Seeded sample jobs are
  /// const and are never re-assigned; only checkout-dispatched ones are.)
  DeliveryJob copyWith({String? courierName, String? courierPhotoPath}) =>
      DeliveryJob(
        id: id,
        shopName: shopName,
        pickupAddress: pickupAddress,
        buyerName: buyerName,
        deliveryArea: deliveryArea,
        packageSize: packageSize,
        fee: fee,
        orderId: orderId,
        courierName: courierName ?? this.courierName,
        courierPhotoPath: courierPhotoPath ?? this.courierPhotoPath,
        sellerPhone: sellerPhone,
        buyerPhone: buyerPhone,
        trackingNumber: trackingNumber,
        parcelCount: parcelCount,
      );
}

/// What part of the cart a voucher is allowed to discount.
///
/// Stacking is expressed by slot, not by an unbounded list: one store voucher
/// per seller, one platform voucher, and one shipping voucher. That is the rule
/// the workbook's worked example (MCH50 + PLAT100 + FREESHIP) exercises.
enum VoucherScope { store, platform, shipping }

class Voucher {
  const Voucher({
    required this.code,
    required this.title,
    required this.discountLabel,
    required this.minSpend,
    required this.expiryLabel,
    this.expiresAt,
    this.startsAt,
    this.discountRate = 0.10,
    this.maxDiscount = 200,
    this.fixedDiscount,
    this.category,
    this.scope = VoucherScope.platform,
    this.sellerName,
    this.usageLimit,
    this.usedCount = 0,
    this.perBuyerLimit,
  });

  final String code;
  final String title;

  /// When set, the voucher only applies to (and its minimum spend only
  /// counts) products in this category — see config/pricing.dart.
  final String? category;

  /// Display text, e.g. "10% off". `discountRate`/`maxDiscount` below
  /// are what Cart actually computes with — this is just the label.
  final String discountLabel;
  final double minSpend;
  final String expiryLabel;

  /// The real expiry moment behind [expiryLabel], when known.
  ///
  /// Null means "no computable date" — the card then shows only the
  /// label text with no countdown. Live vouchers map this from the
  /// server's `expires_at` (see voucherFromJson); mock entries use
  /// dates relative to "now" (see mockVouchers), so the countdown
  /// always has something true to say in mock mode.
  final DateTime? expiresAt;

  /// When set, the voucher cannot be used before this instant.
  ///
  /// The workbook distinguishes "expired" from "not started yet", so the app
  /// needs the start moment rather than treating every inactive voucher as a
  /// bad code.
  final DateTime? startsAt;

  final double discountRate;
  final double maxDiscount;

  /// A flat peso amount, when the voucher is not a percentage.
  ///
  /// Null keeps the legacy shape: `discountRate > 0` is a percentage, and
  /// `discountRate == 0` with no fixed amount is a shipping rebate. A fixed
  /// merchandise voucher sets this and leaves [discountRate] at zero.
  final double? fixedDiscount;

  /// Whether the voucher belongs to a store, the platform, or shipping.
  ///
  /// [sellerName] is required for a store voucher and ignored otherwise.
  final VoucherScope scope;
  final String? sellerName;

  /// Total uses allowed across all buyers; null means unlimited.
  final int? usageLimit;

  /// Uses the server has already recorded. Mock fixtures can pre-set this to
  /// model an exhausted voucher without inventing a fake claim in the UI.
  final int usedCount;

  /// Uses allowed per buyer; null means unlimited.
  final int? perBuyerLimit;

  bool get isShipping => scope == VoucherScope.shipping;
  bool get isFixed => fixedDiscount != null;
  bool get isPercentage => !isShipping && fixedDiscount == null && discountRate > 0;

  /// The peso figure the stub should display for a fixed/shipping voucher.
  double get flatValue => fixedDiscount ?? maxDiscount;

  bool get usageExhausted => usageLimit != null && usedCount >= usageLimit!;

  /// "Store · Rattan & Reed Co." etc. — used by the wallet card.
  String get scopeLabel => switch (scope) {
    VoucherScope.store => sellerName == null ? 'Store voucher' : 'Store · $sellerName',
    VoucherScope.platform => 'Platform voucher',
    VoucherScope.shipping => 'Shipping voucher',
  };
}

/// Order lifecycle stage. Lives here rather than inside
/// orders_status_screen.dart because Profile now reads order history
/// too — a private list on one screen meant every other screen had to
/// either duplicate it or pretend it didn't exist.
enum OrderStatus { toShip, inTransit, outForDelivery, toRate }

class Order {
  const Order({
    required this.id,
    required this.shopName,
    required this.status,
    required this.eta,
    required this.itemSummary,
    this.productIds = const [],
    this.productQuantities = const {},
    this.buyerConfirmed = false,
    this.cancelled = false,
    this.cancellationReason,
    this.deliveredAt,
  });

  final String id;
  final String shopName;
  final OrderStatus status;
  final String eta;
  final String itemSummary;

  /// True once the buyer has tapped "Order Received" on a delivered parcel.
  ///
  /// This is deliberately a flag beside [status], not a fifth status: the
  /// courier's delivered milestone and the buyer's own acknowledgement are two
  /// different facts, and collapsing them would make the tracker unable to say
  /// "delivered, waiting for the buyer to confirm".
  final bool buyerConfirmed;

  /// True once the buyer cancels an order that has not shipped yet.
  final bool cancelled;

  /// The reason chosen in the cancellation sheet. Null unless [cancelled].
  final String? cancellationReason;

  /// When this parcel was marked delivered, used only to measure the buyer's
  /// seven-day return window.
  ///
  /// This is a *local* view of an event, not a field guessed from the API: the
  /// courier completion timestamp feeds it in [OrderState.resolvedOrders], and
  /// the seeded delivered order carries a fixture date so mock mode can
  /// exercise the window. The live order mapper deliberately leaves it null
  /// until the server exposes a delivered timestamp; an unknown date must not
  /// be invented.
  final DateTime? deliveredAt;

  /// The catalogue items this order actually contains — ids from
  /// `mockProducts`.
  ///
  /// This is the join key that lets purchase history talk to the rest of the
  /// app ("stop showing me what I already bought"). [itemSummary] can't do
  /// that job: it's the human-readable line the order card renders — prose
  /// like `'Woven Rattan Tote Bag ×2'` — not an id. Checkout throws the ids
  /// away when it builds that string, which is *why* the wishlist, the
  /// recently-viewed rail and order history had no way to reference each
  /// other.
  ///
  /// Empty means "contents unknown" (an order placed before this field
  /// existed), not "bought nothing" — so consumers only ever exclude ids
  /// they can actually match.
  final List<String> productIds;

  /// Units ordered per product id.
  ///
  /// [productIds] is retained because older saved orders only have the flat
  /// list; this map is additive. A missing entry is read as one unit when the
  /// id is present, which is the only honest interpretation of an old order
  /// whose quantities were never stored.
  final Map<String, int> productQuantities;

  /// True once the package has actually landed and the buyer has not
  /// cancelled it — what Profile counts as a completed order.
  bool get isDelivered => status == OrderStatus.toRate && !cancelled;

  /// True when the buyer has acknowledged receipt. A delivered parcel that
  /// has not been acknowledged is still on the Delivered tab; the badge says
  /// "Delivered", then changes to "Completed" after the confirmation.
  bool get isCompleted => buyerConfirmed && !cancelled;

  /// Cancellation is only offered before the seller ships. The screen checks
  /// the *resolved* status (so a courier pickup also blocks it), and this
  /// getter is the model-level rule the repository/state repeats.
  bool get canCancel =>
      status == OrderStatus.toShip && !buyerConfirmed && !cancelled;

  /// Order Received is only offered after delivery and only once.
  bool get canConfirmReceived =>
      status == OrderStatus.toRate && !buyerConfirmed && !cancelled;

  Order copyWith({
    OrderStatus? status,
    String? eta,
    bool? buyerConfirmed,
    bool? cancelled,
    String? cancellationReason,
    DateTime? deliveredAt,
  }) => Order(
    id: id,
    shopName: shopName,
    status: status ?? this.status,
    eta: eta ?? this.eta,
    itemSummary: itemSummary,
    productIds: productIds,
    productQuantities: productQuantities,
    buyerConfirmed: buyerConfirmed ?? this.buyerConfirmed,
    cancelled: cancelled ?? this.cancelled,
    cancellationReason: cancellationReason ?? this.cancellationReason,
    deliveredAt: deliveredAt ?? this.deliveredAt,
  );
}

/// Curated to match the boutique/artisanal feel of the catalog —
/// swapped out the generic "Electronics / Groceries" chips for
/// categories that actually describe what's being sold.
const List<String> mockCategories = [
  'All',
  'Fashion',
  'Home & Living',
  'Beauty',
];

/// Cascading PH address sample: province -> municipality -> [barangays].
/// Shared by registration_screen.dart and edit_profile_screen.dart so
/// both forms agree on the same options. Placeholder only — replace
/// with a real PSGC API lookup when that's wired up.
const Map<String, Map<String, List<String>>> phAddressData = {
  'Laguna': {
    'Pila': ['San Antonio', 'Santa Clara Norte', 'Poblacion'],
    'Santa Cruz': ['Bubukal', 'Poblacion I', 'Gatid'],
    'Victoria': ['Bagumbayan', 'Poblacion', 'Tagumpay'],
  },
  'Batangas': {
    'Lipa City': ['Antipolo del Norte', 'Bulacnin', 'Marauoy'],
    'Batangas City': ['Alangilan', 'Kumintang Ilaya', 'Poblacion'],
  },
};

const List<String> courierVehicleTypes = [
  'Motorcycle',
  'Tricycle',
  'Sedan',
  'Van',
  'Pickup Truck',
];

/// Sample order history — moved out of orders_status_screen.dart so
/// Profile can count it. Swap for the real orders provider once the
/// API exists; keep the shape.
///
/// The delivered sample's date is relative to app start so the seven-day
/// return window is exercisable in mock mode instead of being permanently
/// closed by a hard-coded calendar date. The label says the same thing the
/// timestamp means, so the screen does not claim "Aug 20" while accepting a
/// return in October.
final List<Order> mockOrders = [
  const Order(
    id: 'ORD-10245',
    shopName: 'Willow & Bloom Atelier',
    status: OrderStatus.toShip,
    eta: 'Seller ships within 1-2 days',
    itemSummary: 'Linen Blend Wrap Dress',
    productIds: ['p1'],
    productQuantities: {'p1': 1},
  ),
  const Order(
    id: 'ORD-10198',
    shopName: 'Rattan & Reed Co.',
    status: OrderStatus.inTransit,
    eta: 'Arriving Sep 3',
    itemSummary: 'Woven Rattan Tote Bag ×2',
    productIds: ['p2'],
    productQuantities: {'p2': 2},
  ),
  const Order(
    id: 'ORD-10122',
    shopName: 'Sage & Clay Studio',
    status: OrderStatus.outForDelivery,
    eta: 'Arriving today, 2:00–5:00 PM',
    itemSummary: 'Sage Ceramic Mug Set',
    productIds: ['p3'],
    productQuantities: {'p3': 1},
  ),
  Order(
    id: 'ORD-09876',
    shopName: 'Willow & Bloom Atelier',
    status: OrderStatus.toRate,
    eta: 'Delivered 2 days ago',
    itemSummary: 'Cotton Gauze Scarf',
    productIds: ['p4'],
    productQuantities: {'p4': 1},
    deliveredAt: DateTime.now().subtract(const Duration(days: 2)),
  ),
];

final List<Product> mockProducts = [
  Product(
    id: 'p1',
    name: 'Linen Blend Wrap Dress',
    category: 'Fashion',
    price: 1299,
    compareAtPrice: 1599,
    sellerName: 'Willow & Bloom Atelier',
    rating: 4.8,
    ratingCount: 212,
    soldCount: 1100,
    stock: 12,
    variants: ['Small', 'Medium', 'Large', 'X-Large'],
    // Counts differ per product on purpose: a real catalogue has sellers who
    // upload one photo and sellers who upload eight, and the layouts have to
    // survive both. See Product.images.
    images: mockPhotos('p1', 4),
    description:
        'A breathable linen-cotton blend wrap dress with adjustable tie waist. '
        'Soft, breathable, and finished with mother-of-pearl buttons. '
        'True to size — see the size guide for measurements.',
  ),
  Product(
    id: 'p2',
    name: 'Woven Rattan Tote Bag',
    category: 'Fashion',
    price: 699,
    sellerName: 'Rattan & Reed Co.',
    rating: 4.7,
    ratingCount: 86,
    soldCount: 340,
    stock: 3,
    shippingFee: 65,
    variants: ['Natural', 'Natural (Large)'],
    description:
        'Hand-woven rattan tote with a soft cotton lining and reinforced handles. '
        'Lightweight enough for everyday errands, sturdy enough for market runs.',
    images: mockPhotos('p2', 3),
  ),
  Product(
    id: 'p3',
    name: 'Sage Ceramic Mug Set',
    category: 'Home & Living',
    price: 549,
    sellerName: 'Sage & Clay Studio',
    rating: 4.9,
    ratingCount: 154,
    soldCount: 610,
    stock: 0,
    variants: ['Set of 2', 'Set of 4'],
    description:
        'Hand-thrown ceramic mugs in a muted sage glaze. Microwave and '
        'dishwasher safe, each piece kiln-finished with slight natural variation.',
    images: mockPhotos('p3', 4),
  ),
  Product(
    id: 'p4',
    name: 'Cotton Gauze Scarf',
    category: 'Fashion',
    price: 349,
    sellerName: 'Willow & Bloom Atelier',
    rating: 4.6,
    ratingCount: 58,
    soldCount: 220,
    stock: 24,
    variants: ['Blush', 'Sage', 'Neutral'],
    description:
        'Lightweight double-gauze scarf that softens with every wash. '
        'Works as a light wrap, a hair scarf, or a bag accent.',
    // Two — the smallest gallery the pager has to handle without chrome
    // getting silly.
    images: mockPhotos('p4', 2),
  ),
  Product(
    id: 'p5',
    name: 'Dried Botanical Bouquet',
    category: 'Home & Living',
    price: 429,
    sellerName: 'Field & Fold Florals',
    rating: 4.8,
    ratingCount: 41,
    soldCount: 175,
    stock: 0,
    description:
        'A hand-tied bundle of dried pampas, bunny tail grass, and native '
        'foliage. Arrives ready to display, no water needed.',
    images: mockPhotos('p5', 3),
  ),
  Product(
    id: 'p6',
    name: 'Linen Throw Pillow Cover',
    category: 'Home & Living',
    price: 399,
    compareAtPrice: 499,
    sellerName: 'Sage & Clay Studio',
    rating: 4.5,
    ratingCount: 63,
    soldCount: 290,
    stock: 4,
    shippingFee: 120,
    variants: ['16x16', '18x18', '20x20'],
    description:
        'Stonewashed linen cover with a hidden zip closure. Pairs well '
        'with the ceramic mug set for a matching shelf styling moment.',
    images: mockPhotos('p6', 3),
  ),
  Product(
    id: 'p7',
    name: 'Handwoven Straw Hat',
    category: 'Fashion',
    price: 599,
    sellerName: 'Rattan & Reed Co.',
    rating: 4.4,
    ratingCount: 29,
    soldCount: 98,
    stock: 18,
    purchaseLimit: 2,
    variants: ['One Size'],
    description:
        'Wide-brim straw hat woven from natural raffia, with a soft '
        'cotton drawstring for a secure, adjustable fit.',
    // One. A single-photo product is the case the gallery has to render
    // without pager chrome, and there was no way to test that path before.
    images: mockPhotos('p7', 1),
  ),
  Product(
    id: 'p8',
    name: 'Botanical Hand Soap Set',
    category: 'Beauty',
    price: 459,
    sellerName: 'Field & Fold Florals',
    rating: 4.7,
    ratingCount: 74,
    soldCount: 260,
    stock: 4,
    sellerOnVacation: true,
    variants: ['Set of 3'],
    description:
        'Three cold-processed bar soaps scented with rosemary, '
        'chamomile, and sweet orange. Free from sulfates and parabens.',
    images: mockPhotos('p8', 3),
  ),
  // The only two-dimensional product in the mock catalogue. It exists so the
  // variation picker has an honest fixture for the behaviour a real SKU model
  // needs: every control must be chosen, combination stock can disable one
  // size without disabling the colour, and price/image follow the chosen SKU.
  // White/M is deliberately in stock while Black/M is not — that is what lets
  // the screen prove "disabled because this combination is gone", not
  // "disabled because M is a bad value".
  Product(
    id: 'p9',
    name: 'Everyday Cotton Tee',
    category: 'Fashion',
    price: 199,
    sellerName: 'Willow & Bloom Atelier',
    rating: 4.6,
    ratingCount: 44,
    soldCount: 130,
    stock: 10,
    variationTypes: [
      VariationType(name: 'Color', values: ['Black', 'White']),
      VariationType(name: 'Size', values: ['S', 'M', 'L']),
    ],
    variantSkus: [
      ProductVariant(
        selections: {'Color': 'Black', 'Size': 'S'},
        price: 199,
        stock: 10,
        image: placeholderPhoto('p9-black', 0),
      ),
      ProductVariant(
        selections: {'Color': 'Black', 'Size': 'M'},
        price: 249,
        stock: 0,
        image: placeholderPhoto('p9-black', 0),
      ),
      ProductVariant(
        selections: {'Color': 'Black', 'Size': 'L'},
        price: 299,
        stock: 5,
        image: placeholderPhoto('p9-black', 0),
      ),
      ProductVariant(
        selections: {'Color': 'White', 'Size': 'S'},
        price: 199,
        stock: 10,
        image: placeholderPhoto('p9-white', 0),
      ),
      ProductVariant(
        selections: {'Color': 'White', 'Size': 'M'},
        price: 249,
        stock: 10,
        image: placeholderPhoto('p9-white', 0),
      ),
      // Same price/stock as White/S so switching colour while L is selected
      // also demonstrates the expected ₱299 → ₱199 recovery.
      ProductVariant(
        selections: {'Color': 'White', 'Size': 'L'},
        price: 199,
        stock: 10,
        image: placeholderPhoto('p9-white', 0),
      ),
    ],
    description:
        'A heavyweight cotton tee with a relaxed shoulder and a clean, '
        'ribbed neckline. Pre-shrunk, garment-dyed, and made to be worn '
        'on repeat.',
    images: mockPhotos('p9', 3),
  ),
  // Sale fixture for BUY-VOU-011: the paid price is already marked down from
  // ₱450, and MCH50 has to discount that paid price rather than the struck-
  // through one. Its ₱50 shipping is also explicit so the case's "₱360 total"
  // is reproducible.
  Product(
    id: 'p10',
    name: 'Rattan Market Basket',
    category: 'Home & Living',
    price: 360,
    compareAtPrice: 450,
    sellerName: 'Rattan & Reed Co.',
    rating: 4.6,
    ratingCount: 37,
    soldCount: 84,
    stock: 8,
    shippingFee: 50,
    description:
        'A low, wide market basket woven from rattan offcuts. The sale price '
        'is the price the buyer pays, and vouchers apply to it — not to the '
        'old list price.',
    images: mockPhotos('p10', 3),
  ),
];

const List<DeliveryJob> mockDeliveryJobs = [
  DeliveryJob(
    id: 'REQ-5521',
    shopName: 'Willow & Bloom Atelier',
    pickupAddress: 'Unit 4B, Pila Commercial Complex, Pila, Laguna',
    buyerName: 'Marielle Santos',
    deliveryArea: 'Sta. Cruz, Laguna (6.2 km)',
    packageSize: 'Small parcel · 0.8 kg',
    fee: 85.00,
    sellerPhone: '0917 555 0101',
    buyerPhone: '0917 555 0201',
    trackingNumber: 'ZEF-5521-01',
  ),
  DeliveryJob(
    id: 'REQ-5519',
    shopName: 'Rattan & Reed Co.',
    pickupAddress: '22 Rizal St., Pila, Laguna',
    buyerName: 'Jonah Perez',
    deliveryArea: 'Victoria, Laguna (9.4 km)',
    packageSize: 'Medium box · 2.1 kg',
    fee: 120.00,
    sellerPhone: '0917 555 0102',
    buyerPhone: '0917 555 0202',
    trackingNumber: 'ZEF-5519-01',
  ),
  DeliveryJob(
    id: 'REQ-5516',
    shopName: 'Sage & Clay Studio',
    pickupAddress: '8 Burgos Ave., Pila, Laguna',
    buyerName: 'Andrea Cruz',
    deliveryArea: 'Pila, Laguna (1.8 km)',
    packageSize: 'Small parcel · 1.4 kg',
    fee: 60.00,
    sellerPhone: '0917 555 0103',
    buyerPhone: '0917 555 0203',
    trackingNumber: 'ZEF-5516-01',
  ),
  DeliveryJob(
    id: 'REQ-5508',
    shopName: 'Field & Fold Florals',
    pickupAddress: '15 Bonifacio St., Pila, Laguna',
    buyerName: 'Miguel Torres',
    deliveryArea: 'Los Baños, Laguna (11.7 km)',
    packageSize: 'Fragile · 0.5 kg',
    fee: 140.00,
    sellerPhone: '0917 555 0104',
    buyerPhone: '0917 555 0204',
    trackingNumber: 'ZEF-5508-01',
  ),
];

/// Mock voucher catalogue. Dates are relative to "now" (not const) so
/// the countdown on each card always has something true to say:
/// one expiring today-ish, one comfortably ahead, one far out.
final List<Voucher> mockVouchers = [
  Voucher(
    code: 'GRACE10',
    title: '10% Off Storewide',
    discountLabel: '10% off, up to ₱200',
    minSpend: 500,
    expiryLabel: 'Valid until Sep 30',
    expiresAt: DateTime.now().add(const Duration(days: 2)),
  ),
  Voucher(
    code: 'FREESHIP',
    title: 'Free Shipping Voucher',
    discountLabel: '₱50 off shipping',
    minSpend: 800,
    expiryLabel: 'Valid until Sep 15',
    expiresAt: DateTime.now().add(const Duration(days: 12)),
    discountRate: 0,
    maxDiscount: 50,
    scope: VoucherScope.shipping,
  ),
  Voucher(
    code: 'NEWHOME15',
    title: 'Home & Living Treat',
    discountLabel: '15% off Home & Living, up to ₱150',
    minSpend: 400,
    expiryLabel: 'Valid until Sep 20',
    expiresAt: DateTime.now().add(const Duration(hours: 8)),
    discountRate: 0.15,
    maxDiscount: 150,
    category: 'Home & Living',
  ),
  // Stacking fixture from the workbook's worked example. MCH50 and MCH10
  // share the same store slot; applying MCH10 replaces MCH50 rather than
  // stacking two store discounts on one seller.
  Voucher(
    code: 'MCH50',
    title: 'Rattan & Reed ₱50 Treat',
    discountLabel: '₱50 off this store',
    minSpend: 300,
    expiryLabel: 'Valid until Dec 31',
    expiresAt: DateTime.now().add(const Duration(days: 20)),
    discountRate: 0,
    fixedDiscount: 50,
    scope: VoucherScope.store,
    sellerName: 'Rattan & Reed Co.',
    usageLimit: 500,
    perBuyerLimit: 1,
  ),
  Voucher(
    code: 'MCH10',
    title: 'Rattan & Reed 10% Off',
    discountLabel: '10% off this store, up to ₱100',
    minSpend: 300,
    expiryLabel: 'Valid until Dec 31',
    expiresAt: DateTime.now().add(const Duration(days: 20)),
    discountRate: 0.10,
    maxDiscount: 100,
    scope: VoucherScope.store,
    sellerName: 'Rattan & Reed Co.',
    usageLimit: 500,
  ),
  Voucher(
    code: 'PLAT100',
    title: 'Platform ₱100 Off',
    discountLabel: '₱100 off your order',
    minSpend: 1000,
    expiryLabel: 'Valid until Dec 31',
    expiresAt: DateTime.now().add(const Duration(days: 15)),
    discountRate: 0,
    fixedDiscount: 100,
    scope: VoucherScope.platform,
    usageLimit: 1000,
  ),
  Voucher(
    code: 'RVSECRET',
    title: 'Sage & Clay Secret Sale',
    discountLabel: '₱100 off this store',
    minSpend: 1000,
    expiryLabel: 'Valid until Dec 31',
    expiresAt: DateTime.now().add(const Duration(days: 10)),
    discountRate: 0,
    fixedDiscount: 100,
    scope: VoucherScope.store,
    sellerName: 'Sage & Clay Studio',
  ),
  // Eligibility rejection fixtures. Each is deliberately a different reason
  // so the cart can prove its messages are distinct, not one generic failure.
  Voucher(
    code: 'EXPIRED50',
    title: 'Expired ₱50 Voucher',
    discountLabel: '₱50 off',
    minSpend: 0,
    expiryLabel: 'Expired yesterday',
    expiresAt: DateTime.now().subtract(const Duration(days: 1)),
    discountRate: 0,
    fixedDiscount: 50,
  ),
  Voucher(
    code: 'NOTYET50',
    title: 'Starts Tomorrow',
    discountLabel: '₱50 off',
    minSpend: 0,
    expiryLabel: 'Starts tomorrow',
    startsAt: DateTime.now().add(const Duration(days: 1)),
    expiresAt: DateTime.now().add(const Duration(days: 10)),
    discountRate: 0,
    fixedDiscount: 50,
  ),
  Voucher(
    code: 'LIMIT50',
    title: 'Fully Claimed Voucher',
    discountLabel: '₱50 off',
    minSpend: 0,
    expiryLabel: 'Valid until Dec 31',
    expiresAt: DateTime.now().add(const Duration(days: 10)),
    discountRate: 0,
    fixedDiscount: 50,
    usageLimit: 1,
    usedCount: 1,
  ),
];

// ---------------------------------------------------------------------------
// Home promo carousel
// ---------------------------------------------------------------------------
//
// Moved out of promo_carousel.dart, where each slide carried its own Color
// values. That made "run a different campaign" an edit to a widget, and it
// meant every slide had to know what colour it was in both modes — so the
// brand's own colour decisions were being made three times, by three
// slides, instead of once by the palette.
//
// Now a slide is content: copy, which layout it wants, which icon, and
// *which ground* as a semantic choice ([PromoGround]). The carousel maps
// that ground to palette tokens. Real campaigns replace this list, not the
// widget.

/// The layout a promo slide renders with. Three deliberately different
/// compositions — a carousel of three identical templates is a slot
/// machine, not a campaign.
enum PromoSlideLayout {
  /// Large eyebrow + stacked text left, decorative shape right.
  textLeft,

  /// Full-bleed tinted surface, centered bold headline, small icon seal.
  centeredHero,

  /// Split: icon motif fills the left half, copy lives on the right.
  iconSplit,
}

/// Which brand ground a slide sits on.
///
/// Deliberately *not* a `Color`. The ground is a design-system decision that
/// has to differ per mode — a deep sage ground reads as "calm and
/// expensive" on ink and as a black-green block on paper — so that mapping
/// belongs in the theme, not in the content.
enum PromoGround { brandRose, sage, terracotta }

/// One banner campaign slide. See [mockPromoSlides].
class PromoSlide {
  const PromoSlide({
    required this.eyebrow,
    required this.title,
    required this.subtitle,
    required this.layout,
    required this.ground,
    required this.icon,
    this.bigIcon,
  });

  final String eyebrow;
  final String title;
  final String subtitle;
  final PromoSlideLayout layout;
  final PromoGround ground;
  final IconData icon;

  /// Optional second, larger icon used as a background motif.
  final IconData? bigIcon;

  /// Creates a [PromoSlide] from a server JSON object.
  ///
  /// Unknown `layout` / `ground` / icon name strings fall back to safe defaults
  /// so a CMS typo does not crash the carousel — it renders a neutral slide
  /// instead.
  factory PromoSlide.fromJson(Map<String, Object?> json) {
    final layout = _layoutFromString(json['layout']?.toString() ?? '');
    final ground = _groundFromString(json['ground']?.toString() ?? '');
    final icon = iconForName(json['icon']?.toString() ?? '');
    final bigIconName = json['big_icon']?.toString();
    final bigIcon = bigIconName == null || bigIconName.isEmpty
        ? null
        : iconForName(bigIconName);
    return PromoSlide(
      eyebrow: json['eyebrow']?.toString() ?? '',
      title: (json['title']?.toString() ?? '').replaceAll(r'\n', '\n'),
      subtitle: json['subtitle']?.toString() ?? '',
      layout: layout,
      ground: ground,
      icon: icon,
      bigIcon: bigIcon,
    );
  }

  static PromoSlideLayout _layoutFromString(String s) => switch (s) {
    'text_left' => PromoSlideLayout.textLeft,
    'centered_hero' => PromoSlideLayout.centeredHero,
    'icon_split' => PromoSlideLayout.iconSplit,
    _ => PromoSlideLayout.textLeft, // safe fallback
  };

  static PromoGround _groundFromString(String s) => switch (s) {
    'brand_rose' => PromoGround.brandRose,
    'sage' => PromoGround.sage,
    'terracotta' => PromoGround.terracotta,
    _ => PromoGround.brandRose, // safe fallback
  };
}

/// Resolves a Dart icon name (e.g. `'local_florist_outlined'`) to its
/// [IconData], falling back to [Icons.campaign_outlined] for unknown names.
///
/// The lookup table covers every icon the seeded slides use, plus a handful of
/// extras likely to appear in future campaigns. It is an explicit allowlist
/// rather than a reflective lookup because Flutter tree-shakes unused icons in
/// release builds — a name-based reflection would silently resolve to a broken
/// codepoint. When a new slide needs an icon that isn't here, add it.
IconData iconForName(String name) =>
    _iconsByName[name] ?? Icons.campaign_outlined;

const Map<String, IconData> _iconsByName = {
  // Used by the seeded slides
  'local_florist_outlined': Icons.local_florist_outlined,
  'local_offer_outlined': Icons.local_offer_outlined,
  'local_shipping_outlined': Icons.local_shipping_outlined,
  'cottage_outlined': Icons.cottage_outlined,
  'directions_bike_outlined': Icons.directions_bike_outlined,
  // Common campaign motifs
  'campaign_outlined': Icons.campaign_outlined,
  'star_outlined': Icons.star_outline,
  'star_border': Icons.star_border,
  'percent': Icons.percent,
  'discount_outlined': Icons.discount_outlined,
  'new_releases_outlined': Icons.new_releases_outlined,
  'celebration_outlined': Icons.celebration_outlined,
  'card_giftcard_outlined': Icons.card_giftcard_outlined,
  'storefront_outlined': Icons.storefront_outlined,
  'favorite_border': Icons.favorite_border,
  'bolt_outlined': Icons.bolt_outlined,
  'redeem_outlined': Icons.redeem_outlined,
};

/// The live slides, in the order they advance. `buyer_shell`'s tap
/// destinations are keyed to this order — see `_onPromoTap`.
const List<PromoSlide> mockPromoSlides = [
  // The brand slot: the brand rose gets the prime position so the banner
  // matches the nav bar and buttons.
  PromoSlide(
    eyebrow: 'JUST ARRIVED',
    title: 'New This\nSeason',
    subtitle: 'Fresh arrivals from Willow & Bloom',
    layout: PromoSlideLayout.textLeft,
    ground: PromoGround.brandRose,
    icon: Icons.local_florist_outlined,
  ),

  // Sage. It had been living only as a nav-bar badge and a background
  // glow; giving it a whole banner surface is the first place it reads as
  // a real second accent rather than decoration.
  PromoSlide(
    eyebrow: 'LIMITED TIME',
    title: '15% Off\nHome & Living',
    subtitle: 'Code NEWHOME15 · up to ₱150 off',
    layout: PromoSlideLayout.centeredHero,
    ground: PromoGround.sage,
    icon: Icons.local_offer_outlined,
    bigIcon: Icons.cottage_outlined,
  ),

  // Terracotta. The accent gets a full banner so it reads as a brand
  // colour, not just a price tint.
  PromoSlide(
    eyebrow: 'ALWAYS ON',
    title: 'Free\nShipping',
    subtitle: 'On every order over ₱1,000',
    layout: PromoSlideLayout.iconSplit,
    ground: PromoGround.terracotta,
    icon: Icons.local_shipping_outlined,
    bigIcon: Icons.directions_bike_outlined,
  ),
];

// ---------------------------------------------------------------------------
// Maker notes — the "FROM THE MAKER" section on Home
// ---------------------------------------------------------------------------
//
// Keyed by *shop*, not by product: the note is the seller's voice, and a
// shop says one thing regardless of which of their products you land on.
//
// Placeholder content, but the right shape — whatever replaces it (an API
// field a seller types into) fills the same `makerNoteFor` lookup, so the
// widget doesn't change. What changed here is the honesty of the shape: it
// used to be four lines chosen by `sellerName.hashCode`, i.e. a shop's
// "voice" was decided by a string hash, and the widget had no way to know
// whether a shop had written anything at all.
//
// House rule for the copy: specific, plain, slightly unglamorous. "Fresh
// arrivals from our shop" is what every marketplace writes, and the entire
// point of this section is that it doesn't sound like that.
const Map<String, String> mockMakerNotes = {
  'Willow & Bloom Atelier':
      'We only make what we can finish by hand in a week. That is the whole business plan.',
  'Rattan & Reed Co.':
      'Everything here starts on the same worktable, and most of it takes three tries.',
  'Sage & Clay Studio':
      'If a piece does not survive a week in my own house, it does not get listed.',
  'Field & Fold Florals':
      'I started making these for my sister. She still gets the first one of every batch.',
};

/// The maker note for a shop, or null when that seller hasn't written one.
///
/// Null rather than a generic fallback, deliberately: a shop with no note
/// should render as a shop with no note (the section hides), not as a shop
/// saying something it never said.
String? makerNoteFor(String sellerName) => mockMakerNotes[sellerName];
