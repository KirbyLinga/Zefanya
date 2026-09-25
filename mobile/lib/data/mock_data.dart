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

class Product {
  const Product({
    required this.id,
    required this.name,
    required this.category,
    required this.price,
    required this.sellerName,
    this.compareAtPrice,
    this.rating = 4.5,
    this.ratingCount = 0,
    this.soldCount = 0,
    this.variants = const [],
    this.description = '',
  });

  final String id;
  final String name;
  final String category;
  final double price;
  final double? compareAtPrice;
  final String sellerName;
  final double rating;
  final int ratingCount;
  final int soldCount;
  final List<String> variants;
  final String description;
}

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
}

class Voucher {
  const Voucher({
    required this.code,
    required this.title,
    required this.discountLabel,
    required this.minSpend,
    required this.expiryLabel,
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
  });

  final String id;
  final String shopName;
  final OrderStatus status;
  final String eta;
  final String itemSummary;

  /// True once the package has actually landed — what Profile counts
  /// as a completed order.
  bool get isDelivered => status == OrderStatus.toRate;

  Order copyWith({OrderStatus? status, String? eta}) => Order(
        id: id,
        shopName: shopName,
        status: status ?? this.status,
        eta: eta ?? this.eta,
        itemSummary: itemSummary,
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
  ),
  Order(
    id: 'ORD-10198',
    shopName: 'Rattan & Reed Co.',
    status: OrderStatus.inTransit,
    eta: 'Arriving Sep 3',
    itemSummary: 'Woven Rattan Tote Bag ×2',
  ),
  Order(
    id: 'ORD-10122',
    shopName: 'Sage & Clay Studio',
    status: OrderStatus.outForDelivery,
    eta: 'Arriving today, 2:00–5:00 PM',
    itemSummary: 'Sage Ceramic Mug Set',
  ),
  Order(
    id: 'ORD-09876',
    shopName: 'Willow & Bloom Atelier',
    status: OrderStatus.toRate,
    eta: 'Delivered Aug 20',
    itemSummary: 'Cotton Gauze Scarf',
  ),
];

const List<Product> mockProducts = [
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

const List<Voucher> mockVouchers = [
  Voucher(
    code: 'GRACE10',
    title: '10% Off Storewide',
    discountLabel: '10% off, up to ₱200',
    minSpend: 500,
    expiryLabel: 'Valid until Sep 30',
  ),
  Voucher(
    code: 'FREESHIP',
    title: 'Free Shipping Voucher',
    discountLabel: '₱60 off shipping',
    minSpend: 800,
    expiryLabel: 'Valid until Sep 15',
    discountRate: 0,
    maxDiscount: 60,
  ),
  Voucher(
    code: 'NEWHOME15',
    title: 'Home & Living Treat',
    discountLabel: '15% off Home & Living, up to ₱150',
    minSpend: 400,
    expiryLabel: 'Valid until Sep 20',
    discountRate: 0.15,
    maxDiscount: 150,
    category: 'Home & Living',
  ),
];
