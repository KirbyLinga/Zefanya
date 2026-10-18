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
    this.description = '',
    this.images = const [],
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
  final List<String> variants;
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
  /// ordering index; whether there's a per-variant photo set; and whether
  /// sellers can reorder after upload. Those are the API's call, and this list
  /// of strings is deliberately the smallest thing that can express "an
  /// ordered set of photos per product".
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
  List<String> get photos => images.isEmpty ? [placeholderPhoto(id, 0)] : images;

  /// Whether there is more than one photo to look through. The gallery asks
  /// this before drawing pager chrome, so a single-photo product gets no
  /// dots and no counter rather than a pager over one page.
  bool get hasGallery => photos.length > 1;

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

  /// Only identity can change after dispatch — a job's route, buyer
  /// and fee are fixed when the seller confirms it. (Seeded sample jobs are
  /// const and are never re-assigned; only checkout-dispatched ones are.)
  DeliveryJob copyWith({String? courierName, String? courierPhotoPath}) => DeliveryJob(
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
      );
}

class Voucher {
  const Voucher({
    required this.code,
    required this.title,
    required this.discountLabel,
    required this.minSpend,
    required this.expiryLabel,
    this.expiresAt,
    this.discountRate = 0.10,
    this.maxDiscount = 200,
    this.category,
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
  final double discountRate;
  final double maxDiscount;
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
  });

  final String id;
  final String shopName;
  final OrderStatus status;
  final String eta;
  final String itemSummary;

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

  /// True once the package has actually landed — what Profile counts
  /// as a completed order.
  bool get isDelivered => status == OrderStatus.toRate;

  Order copyWith({OrderStatus? status, String? eta}) => Order(
        id: id,
        shopName: shopName,
        status: status ?? this.status,
        eta: eta ?? this.eta,
        itemSummary: itemSummary,
        productIds: productIds,
      );
}

/// Curated to match the boutique/artisanal feel of the catalog —
/// swapped out the generic "Electronics / Groceries" chips for
/// categories that actually describe what's being sold.
const List<String> mockCategories = ['All', 'Fashion', 'Home & Living', 'Beauty'];

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

const List<String> courierVehicleTypes = ['Motorcycle', 'Tricycle', 'Sedan', 'Van', 'Pickup Truck'];

/// Sample order history — moved out of orders_status_screen.dart so
/// Profile can count it. Swap for the real orders provider once the
/// API exists; keep the shape.
const List<Order> mockOrders = [
  Order(
    id: 'ORD-10245',
    shopName: 'Willow & Bloom Atelier',
    status: OrderStatus.toShip,
    eta: 'Seller ships within 1-2 days',
    itemSummary: 'Linen Blend Wrap Dress',
    productIds: ['p1'],
  ),
  Order(
    id: 'ORD-10198',
    shopName: 'Rattan & Reed Co.',
    status: OrderStatus.inTransit,
    eta: 'Arriving Sep 3',
    itemSummary: 'Woven Rattan Tote Bag ×2',
    productIds: ['p2'],
  ),
  Order(
    id: 'ORD-10122',
    shopName: 'Sage & Clay Studio',
    status: OrderStatus.outForDelivery,
    eta: 'Arriving today, 2:00–5:00 PM',
    itemSummary: 'Sage Ceramic Mug Set',
    productIds: ['p3'],
  ),
  Order(
    id: 'ORD-09876',
    shopName: 'Willow & Bloom Atelier',
    status: OrderStatus.toRate,
    eta: 'Delivered Aug 20',
    itemSummary: 'Cotton Gauze Scarf',
    productIds: ['p4'],
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
    variants: ['Small', 'Medium', 'Large', 'X-Large'],
    // Counts differ per product on purpose: a real catalogue has sellers who
    // upload one photo and sellers who upload eight, and the layouts have to
    // survive both. See Product.images.
    images: mockPhotos('p1', 4),
    description: 'A breathable linen-cotton blend wrap dress with adjustable tie waist. '
        'Soft, breathable, and finished with mother-of-pearl buttons. '
        'True to size — see the size guide for measurements.',
  ),
  Product(
    id: 'p2',
    name: 'Woven Rattan Tote Bag',
    category: 'Fashion',
    price: 899,
    sellerName: 'Rattan & Reed Co.',
    rating: 4.7,
    ratingCount: 86,
    soldCount: 340,
    variants: ['Natural', 'Natural (Large)'],
    description: 'Hand-woven rattan tote with a soft cotton lining and reinforced handles. '
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
    variants: ['Set of 2', 'Set of 4'],
    description: 'Hand-thrown ceramic mugs in a muted sage glaze. Microwave and '
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
    variants: ['Blush', 'Sage', 'Neutral'],
    description: 'Lightweight double-gauze scarf that softens with every wash. '
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
    description: 'A hand-tied bundle of dried pampas, bunny tail grass, and native '
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
    variants: ['16x16', '18x18', '20x20'],
    description: 'Stonewashed linen cover with a hidden zip closure. Pairs well '
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
    variants: ['One Size'],
    description: 'Wide-brim straw hat woven from natural raffia, with a soft '
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
    variants: ['Set of 3'],
    description: 'Three cold-processed bar soaps scented with rosemary, '
        'chamomile, and sweet orange. Free from sulfates and parabens.',
    images: mockPhotos('p8', 3),
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
  ),
  DeliveryJob(
    id: 'REQ-5519',
    shopName: 'Rattan & Reed Co.',
    pickupAddress: '22 Rizal St., Pila, Laguna',
    buyerName: 'Jonah Perez',
    deliveryArea: 'Victoria, Laguna (9.4 km)',
    packageSize: 'Medium box · 2.1 kg',
    fee: 120.00,
  ),
  DeliveryJob(
    id: 'REQ-5516',
    shopName: 'Sage & Clay Studio',
    pickupAddress: '8 Burgos Ave., Pila, Laguna',
    buyerName: 'Andrea Cruz',
    deliveryArea: 'Pila, Laguna (1.8 km)',
    packageSize: 'Small parcel · 1.4 kg',
    fee: 60.00,
  ),
  DeliveryJob(
    id: 'REQ-5508',
    shopName: 'Field & Fold Florals',
    pickupAddress: '15 Bonifacio St., Pila, Laguna',
    buyerName: 'Miguel Torres',
    deliveryArea: 'Los Baños, Laguna (11.7 km)',
    packageSize: 'Fragile · 0.5 kg',
    fee: 140.00,
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
    discountLabel: '₱60 off shipping',
    minSpend: 800,
    expiryLabel: 'Valid until Sep 15',
    expiresAt: DateTime.now().add(const Duration(days: 12)),
    discountRate: 0,
    maxDiscount: 60,
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
        'text_left'     => PromoSlideLayout.textLeft,
        'centered_hero' => PromoSlideLayout.centeredHero,
        'icon_split'    => PromoSlideLayout.iconSplit,
        _               => PromoSlideLayout.textLeft, // safe fallback
      };

  static PromoGround _groundFromString(String s) => switch (s) {
        'brand_rose'  => PromoGround.brandRose,
        'sage'        => PromoGround.sage,
        'terracotta'  => PromoGround.terracotta,
        _             => PromoGround.brandRose, // safe fallback
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
IconData iconForName(String name) => _iconsByName[name] ?? Icons.campaign_outlined;

const Map<String, IconData> _iconsByName = {
  // Used by the seeded slides
  'local_florist_outlined'     : Icons.local_florist_outlined,
  'local_offer_outlined'       : Icons.local_offer_outlined,
  'local_shipping_outlined'    : Icons.local_shipping_outlined,
  'cottage_outlined'           : Icons.cottage_outlined,
  'directions_bike_outlined'   : Icons.directions_bike_outlined,
  // Common campaign motifs
  'campaign_outlined'          : Icons.campaign_outlined,
  'star_outlined'              : Icons.star_outline,
  'star_border'                : Icons.star_border,
  'percent'                    : Icons.percent,
  'discount_outlined'          : Icons.discount_outlined,
  'new_releases_outlined'      : Icons.new_releases_outlined,
  'celebration_outlined'       : Icons.celebration_outlined,
  'card_giftcard_outlined'     : Icons.card_giftcard_outlined,
  'storefront_outlined'        : Icons.storefront_outlined,
  'favorite_border'            : Icons.favorite_border,
  'bolt_outlined'              : Icons.bolt_outlined,
  'redeem_outlined'            : Icons.redeem_outlined,
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


