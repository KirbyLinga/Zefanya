// lib/state/cart_state.dart
//
// Global cart, exposed via Provider (see main.dart) — same pattern as
// AuthState. Product Details adds to it, Cart reads/mutates it, Checkout reads
// the selected subset and clears it on order placement.
//
// ## Where the cart's data comes from now
//
// From a [CatalogRepository], not from `mockProducts`. The cart stores product
// ids and resolves them through the data layer, which is what lets the same
// cart survive the catalogue becoming a network call.
//
// Rehydration is deliberately *not* allowed to silently drop a line whose
// product disappeared. The old behaviour did exactly that, which meant a buyer
// had no way to see that the thing they were about to check out no longer
// existed. A saved line now keeps its name/price/image snapshot, is marked
// [CartItem.missingFromCatalogue], and cannot be selected.
//
// ## Voucher state
//
// The voucher catalogue is the repository's answer. What a voucher is worth
// stays in `config/pricing.dart`; this class owns the buyer's *choices*:
// claimed codes, applied codes, and how many times the buyer has used a code.
// The quote itself is immutable and recomputed from the current lines, so Cart
// and Checkout cannot freeze a stale discount.

import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../config/pricing.dart';
import '../data/mock_data.dart' show Product, Voucher, VoucherScope;
import '../data/repositories/cart_repository.dart';
import '../data/repositories/catalog_repository.dart';
import '../utils/money_format.dart';

class CartItem implements PricedLine {
  CartItem({
    required this.product,
    this.variant = '',
    this.quantity = 1,
    this.selected = true,
    double? unitPrice,
    this.variantStock,
    this.variantImage,
    this.missingFromCatalogue = false,
  }) : unitPrice = unitPrice ?? product.price;

  final Product product;
  final String variant;
  int quantity;
  @override
  bool selected;

  /// The price of the chosen SKU. Defaults to [Product.price] for legacy
  /// one-dimensional lines, but a rich variant can cost more (or less).
  final double unitPrice;

  /// Stock for the chosen SKU; null means use [Product.stock].
  final int? variantStock;

  /// The chosen SKU's image, if it has one, so the cart can show the colour
  /// the buyer actually picked.
  final String? variantImage;

  /// True when the catalogue no longer returns this id during rehydration.
  ///
  /// The line is kept so the buyer can see what happened; it is not a product
  /// that can be selected or checked out.
  final bool missingFromCatalogue;

  int get availableStock => variantStock ?? product.stock;

  @override
  double get lineTotal => unitPrice * quantity;

  /// A line is usable only when the catalogue still has it, the store is open,
  /// there is stock, and the requested quantity fits inside that stock.
  bool get isAvailable =>
      !missingFromCatalogue &&
      !product.sellerOnVacation &&
      availableStock > 0 &&
      quantity <= availableStock;

  String get unavailableLabel {
    if (missingFromCatalogue) return 'No longer available';
    if (product.sellerOnVacation) return 'Store on vacation';
    if (availableStock <= 0) return 'Out of stock';
    if (quantity > availableStock) return 'Only $availableStock left';
    return '';
  }

  @override
  String get category => product.category;

  @override
  String get sellerName => product.sellerName;

  @override
  double? get shippingFee => product.shippingFee;
}

/// Why an apply attempt failed, or that it succeeded.
enum VoucherApplyStatus {
  applied,
  replaced,
  invalid,
  expired,
  notStarted,
  usageLimit,
  minimumSpend,
  notApplicable,
  alreadyApplied,
}

class VoucherApplyResult {
  const VoucherApplyResult({
    required this.status,
    required this.message,
    this.voucher,
    this.replaced,
  });

  final VoucherApplyStatus status;
  final String message;
  final Voucher? voucher;
  final Voucher? replaced;

  bool get succeeded =>
      status == VoucherApplyStatus.applied ||
      status == VoucherApplyStatus.replaced;

  bool get replacedAnother => status == VoucherApplyStatus.replaced;
}

class CartState extends ChangeNotifier {
  /// [catalog] defaults to the mock repository so existing call sites (and
  /// tests) keep working unchanged. `main.dart` passes the resolved bundle's
  /// repository explicitly, which is what makes the live switch possible.
  ///
  /// [vouchers] is named for the one thing that repository owns today — the
  /// voucher catalogue — rather than for its interface name, because a
  /// parameter called `cart` inside `CartState` would say nothing about what
  /// was being handed over.
  CartState({CatalogRepository? catalog, CartRepository? vouchers})
    : _catalog = catalog ?? const MockCatalogRepository(),
      _vouchers = vouchers ?? const MockCartRepository();

  final CatalogRepository _catalog;
  final CartRepository _vouchers;

  final List<CartItem> _items = [];

  /// The vouchers on offer, as of the last [load] / [refresh].
  List<Voucher> _offer = const [];

  List<Voucher> get vouchers => List.unmodifiable(_offer);

  /// Voucher codes the buyer has explicitly claimed from the wallet.
  final Set<String> _claimedCodes = {};

  /// How many times this buyer has used a code. Persisted because a
  /// per-buyer limit has to survive a new cart and an app restart.
  final Map<String, int> _buyerUses = {};

  /// Codes currently in the three stacking slots.
  final List<String> _appliedCodes = [];

  static const _storageKey = 'cart_items';
  static const _claimsKey = 'voucher_claims';
  static const _usesKey = 'voucher_buyer_uses';

  /// A cart line cannot exceed this many units, regardless of stock. The
  /// server still has the final say on stock; this is the buyer-facing
  /// per-line rule the cart and product screen both enforce.
  static const int maxQuantityPerLine = 20;

  /// The most this line may hold: 20, or the line's stock when that is lower.
  /// A line with no recorded stock is capped at 20 rather than 0, so a missing
  /// stock number cannot silently empty an existing cart line.
  int maxQuantityFor(CartItem item) {
    final stock = item.availableStock;
    if (stock <= 0) return maxQuantityPerLine;
    return math.min(maxQuantityPerLine, stock);
  }

  int maxQuantityForProduct(Product product) {
    final stock = product.stock;
    if (stock <= 0) return maxQuantityPerLine;
    return math.min(maxQuantityPerLine, stock);
  }

  List<CartItem> get items => List.unmodifiable(_items);
  bool get isEmpty => _items.isEmpty;
  int get itemCount => _items.fold(0, (sum, i) => sum + i.quantity);

  /// Selected lines that can actually be checked out.
  List<CartItem> get selectedItems => [
    for (final item in _items)
      if (item.selected && item.isAvailable) item,
  ];

  double get subtotal =>
      selectedItems.fold(0.0, (sum, item) => sum + item.lineTotal);

  List<Voucher> get appliedVouchers {
    final byCode = {for (final voucher in _offer) voucher.code: voucher};
    return [
      for (final code in _appliedCodes)
        if (byCode[code] != null) byCode[code]!,
    ];
  }

  List<String> get appliedVoucherCodes => List.unmodifiable(_appliedCodes);

  bool isClaimed(String code) => _claimedCodes.contains(code);

  int buyerUsesFor(String code) => _buyerUses[code] ?? 0;

  CartQuote get quote => quoteFor(_items);

  CartQuote quoteFor(Iterable<CartItem> lines) => quoteCart(
    lines,
    appliedVouchers,
    buyerUsesFor: (voucher) => buyerUsesFor(voucher.code),
  );

  /// Call once before runApp (see main.dart) to restore the cart and voucher
  /// choices from disk.
  Future<void> load() async {
    // The voucher catalogue first, and *outside* the try below: a failure to
    // ask for it is a real "couldn't reach the server" in live mode, whereas
    // everything inside the try is local restore where a corrupt value should
    // cost one line rather than the whole account.
    _offer = List.of(await _vouchers.vouchers());
    try {
      final prefs = await SharedPreferences.getInstance();
      _restoreVoucherState(prefs);

      final raw = prefs.getString(_storageKey);
      if (raw == null) {
        _items.clear();
        return;
      }

      final decoded = jsonDecode(raw) as List<dynamic>;
      // One catalogue read for the whole restore, indexed by id — rather than
      // one lookup per line, which would be N requests in live mode.
      final byId = <String, Product>{
        for (final p in await _catalog.products()) p.id: p,
      };

      _items.clear();
      for (final entry in decoded) {
        final map = entry as Map<String, dynamic>;
        final productId = map['productId'] as String? ?? '';
        final variant = map['variant'] as String? ?? '';
        final product = byId[productId];
        final sku = product?.variantForLabel(variant);
        final savedPrice = (map['unitPrice'] as num?)?.toDouble();
        final savedStock = (map['variantStock'] as num?)?.toInt();
        final savedImage = map['variantImage'] as String?;

        if (product == null) {
          // Keep the line as an unavailable snapshot. The old implementation
          // dropped it, which made "deleted while in the cart" indistinguishable
          // from "user removed it".
          final snapshotPrice = savedPrice ?? 0;
          final snapshotImage = savedImage ?? (map['image'] as String?);
          final snapshot = Product(
            id: productId,
            name: (map['productName'] as String?) ?? 'Unavailable item',
            category: (map['category'] as String?) ?? '',
            price: snapshotPrice,
            sellerName: (map['sellerName'] as String?) ?? '',
            images: snapshotImage == null ? const [] : [snapshotImage],
            stock: 0,
          );
          _items.add(
            CartItem(
              product: snapshot,
              variant: variant,
              quantity: map['quantity'] as int? ?? 1,
              selected: false,
              unitPrice: snapshotPrice,
              variantStock: 0,
              variantImage: snapshotImage,
              missingFromCatalogue: true,
            ),
          );
          continue;
        }

        final item = CartItem(
          product: product,
          variant: variant,
          quantity: map['quantity'] as int? ?? 1,
          selected: map['selected'] as bool? ?? true,
          unitPrice: savedPrice ?? sku?.price,
          variantStock: savedStock ?? sku?.stock,
          variantImage: savedImage ?? sku?.image,
        );
        if (!item.isAvailable) item.selected = false;
        _items.add(item);
      }
    } catch (_) {
      // Corrupt/old data shape, or the catalogue is unreachable — start with an
      // empty cart rather than crash. In live mode this means a cart that
      // cannot be restored renders as empty rather than as an error, which is a
      // deliberate trade: a cart is trivially re-fillable, and blocking the
      // whole app's startup on a network call would be worse.
      _items.clear();
    }
  }

  void _restoreVoucherState(SharedPreferences prefs) {
    final claimed = prefs.getStringList(_claimsKey);
    _claimedCodes
      ..clear()
      ..addAll(claimed ?? const []);
    _buyerUses.clear();
    final rawUses = prefs.getString(_usesKey);
    if (rawUses == null) return;
    try {
      final decoded = jsonDecode(rawUses) as Map<String, dynamic>;
      decoded.forEach((code, value) {
        if (value is num) _buyerUses[code] = value.toInt();
      });
    } catch (_) {
      // A corrupt usage map must not resurrect a used-up voucher. Treat it as
      // "no recorded uses" rather than crashing the cart.
      _buyerUses.clear();
    }
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    final encoded = jsonEncode(
      _items
          .map(
            (i) => {
              'productId': i.product.id,
              'productName': i.product.name,
              'sellerName': i.product.sellerName,
              'category': i.product.category,
              'image': i.product.images.isEmpty ? null : i.product.images.first,
              'variant': i.variant,
              'quantity': i.quantity,
              'selected': i.selected,
              'unitPrice': i.unitPrice,
              'variantStock': i.variantStock,
              'variantImage': i.variantImage,
            },
          )
          .toList(),
    );
    await prefs.setString(_storageKey, encoded);
    await _saveVoucherState(prefs);
  }

  Future<void> _saveVoucherState(SharedPreferences prefs) async {
    await prefs.setStringList(_claimsKey, _claimedCodes.toList()..sort());
    await prefs.setString(_usesKey, jsonEncode(_buyerUses));
  }

  /// Re-fetches vouchers from the server. Called by pull-to-refresh on
  /// CartScreen.
  Future<void> refresh() async {
    _offer = List.of(await _vouchers.vouchers());
    pruneAppliedVouchers();
    notifyListeners();
  }

  Voucher? voucherForCode(String rawCode) {
    final code = rawCode.trim().toUpperCase();
    for (final voucher in _offer) {
      if (voucher.code == code) return voucher;
    }
    return null;
  }

  /// Claims [rawCode] into the wallet. Returns null on success, or the sentence
  /// to show when the claim is refused.
  Future<String?> claimVoucher(String rawCode, {DateTime? now}) async {
    final code = rawCode.trim().toUpperCase();
    final voucher = voucherForCode(code);
    if (voucher == null) return '"$code" isn\'t a valid voucher code.';
    if (_claimedCodes.contains(code)) {
      return 'Voucher "$code" is already claimed.';
    }
    if (isVoucherExpired(voucher, now: now)) {
      return 'Voucher "$code" has expired.';
    }
    if (isVoucherNotStarted(voucher, now: now)) {
      return 'Voucher "$code" is not active yet.';
    }
    if (isVoucherUsageExhausted(voucher, buyerUses: buyerUsesFor(code))) {
      return 'Voucher "$code" has reached its usage limit.';
    }

    _claimedCodes.add(code);
    notifyListeners();
    final prefs = await SharedPreferences.getInstance();
    await _saveVoucherState(prefs);
    return null;
  }

  /// Applies a code to its stacking slot.
  ///
  /// Store vouchers replace another store voucher for the same seller; platform
  /// and shipping codes replace the one already in their slot. A different
  /// store's voucher can coexist because it discounts different lines.
  VoucherApplyResult applyVoucher(String rawCode, {DateTime? now}) {
    final code = rawCode.trim().toUpperCase();
    if (code.isEmpty) {
      return const VoucherApplyResult(
        status: VoucherApplyStatus.invalid,
        message: 'Enter a voucher code.',
      );
    }

    final voucher = voucherForCode(code);
    if (voucher == null) {
      return VoucherApplyResult(
        status: VoucherApplyStatus.invalid,
        message: '"$code" isn\'t a valid voucher code.',
      );
    }
    if (_appliedCodes.contains(code)) {
      return VoucherApplyResult(
        status: VoucherApplyStatus.alreadyApplied,
        message: 'Voucher "$code" is already applied.',
        voucher: voucher,
      );
    }
    if (isVoucherExpired(voucher, now: now)) {
      return VoucherApplyResult(
        status: VoucherApplyStatus.expired,
        message: 'Voucher "$code" has expired.',
        voucher: voucher,
      );
    }
    if (isVoucherNotStarted(voucher, now: now)) {
      return VoucherApplyResult(
        status: VoucherApplyStatus.notStarted,
        message: 'Voucher "$code" is not active yet.',
        voucher: voucher,
      );
    }
    if (isVoucherUsageExhausted(voucher, buyerUses: buyerUsesFor(code))) {
      return VoucherApplyResult(
        status: VoucherApplyStatus.usageLimit,
        message: 'Voucher "$code" has reached its usage limit.',
        voucher: voucher,
      );
    }

    final base = voucherBase(voucher, _items);
    if (base <= 0) {
      return VoucherApplyResult(
        status: VoucherApplyStatus.notApplicable,
        message:
            'Voucher "$code" doesn\'t apply to the items in your cart.',
        voucher: voucher,
      );
    }
    if (base < voucher.minSpend) {
      final scope = voucher.scope == VoucherScope.store &&
              voucher.sellerName != null
          ? ' at ${voucher.sellerName}'
          : voucher.category == null
          ? ''
          : ' of ${voucher.category}';
      return VoucherApplyResult(
        status: VoucherApplyStatus.minimumSpend,
        message:
            'Spend at least ${formatPeso(voucher.minSpend)}$scope to use "$code".',
        voucher: voucher,
      );
    }

    Voucher? replaced;
    final slot = _slotFor(voucher);
    if (slot != null) {
      for (final appliedCode in List<String>.of(_appliedCodes)) {
        final applied = voucherForCode(appliedCode);
        if (applied != null && _slotFor(applied) == slot) {
          replaced = applied;
          _appliedCodes.remove(appliedCode);
          break;
        }
      }
    }
    _appliedCodes.add(code);
    notifyListeners();
    _save();

    if (replaced != null) {
      return VoucherApplyResult(
        status: VoucherApplyStatus.replaced,
        message:
            'Voucher "$code" applied, replacing "${replaced.code}".',
        voucher: voucher,
        replaced: replaced,
      );
    }
    return VoucherApplyResult(
      status: VoucherApplyStatus.applied,
      message: 'Voucher "$code" applied.',
      voucher: voucher,
    );
  }

  /// The stacking slot a voucher occupies. Null means it cannot stack at all
  /// (e.g. a malformed store voucher with no seller).
  String? _slotFor(Voucher voucher) {
    return switch (voucher.scope) {
      VoucherScope.store =>
        voucher.sellerName == null || voucher.sellerName!.isEmpty
            ? null
            : 'store:${voucher.sellerName}',
      VoucherScope.platform => 'platform',
      VoucherScope.shipping => 'shipping',
    };
  }

  void removeVoucher(String code) {
    if (_appliedCodes.remove(code)) {
      notifyListeners();
      _save();
    }
  }

  void clearAppliedVouchers() {
    if (_appliedCodes.isEmpty) return;
    _appliedCodes.clear();
    notifyListeners();
  }

  /// Records that the applied vouchers were used by a placed order.
  ///
  /// Called before the selected lines are removed. The counters are what make a
  /// per-buyer limit visible in a *new* cart, rather than only in the cart the
  /// voucher was applied to.
  Future<void> recordAppliedVouchersUsed() async {
    for (final code in _appliedCodes) {
      _buyerUses.update(code, (value) => value + 1, ifAbsent: () => 1);
    }
    _appliedCodes.clear();
    final prefs = await SharedPreferences.getInstance();
    await _saveVoucherState(prefs);
    notifyListeners();
  }

  /// Drops applied codes that no longer qualify and returns the notices to
  /// show. Called from CartScreen after a cart mutation.
  List<String> pruneAppliedVouchers({DateTime? now}) {
    final notices = <String>[];
    for (final code in List<String>.of(_appliedCodes)) {
      final voucher = voucherForCode(code);
      if (voucher == null) {
        _appliedCodes.remove(code);
        notices.add('Voucher "$code" removed — it is no longer available.');
        continue;
      }
      if (isVoucherExpired(voucher, now: now)) {
        _appliedCodes.remove(code);
        notices.add('Voucher "$code" removed — it has expired.');
        continue;
      }
      if (isVoucherNotStarted(voucher, now: now)) {
        _appliedCodes.remove(code);
        notices.add('Voucher "$code" removed — it is not active yet.');
        continue;
      }
      if (isVoucherUsageExhausted(voucher, buyerUses: buyerUsesFor(code))) {
        _appliedCodes.remove(code);
        notices.add('Voucher "$code" removed — it has reached its limit.');
        continue;
      }
      final base = voucherBase(voucher, _items);
      if (base < voucher.minSpend) {
        _appliedCodes.remove(code);
        notices.add(
          'Voucher "$code" removed — cart dropped below '
          '${formatPeso(voucher.minSpend)} minimum.',
        );
      }
    }
    if (notices.isNotEmpty) {
      notifyListeners();
      _save();
    }
    return notices;
  }

  /// Adds a line. Refuses a product that is not currently sellable so the same
  /// rule covers the product screen, a future quick-add, and a stale catalogue.
  void addItem(
    Product product, {
    String variant = '',
    int quantity = 1,
    double? unitPrice,
    int? variantStock,
    String? variantImage,
  }) {
    if (quantity <= 0) return;
    if (product.sellerOnVacation) return;
    if (variantStock != null) {
      if (variantStock <= 0) return;
    } else if (product.stock <= 0) {
      return;
    }

    final index = _items.indexWhere(
      (i) => i.product.id == product.id && i.variant == variant,
    );
    final int cap;
    if (index >= 0) {
      cap = maxQuantityFor(_items[index]);
    } else if (variantStock != null) {
      cap = math.min(maxQuantityPerLine, variantStock);
    } else {
      cap = maxQuantityForProduct(product);
    }
    if (index >= 0) {
      _items[index].quantity = math.min(cap, _items[index].quantity + quantity);
      _items[index].selected = true;
    } else {
      _items.add(
        CartItem(
          product: product,
          variant: variant,
          quantity: math.min(cap, quantity),
          unitPrice: unitPrice,
          variantStock: variantStock,
          variantImage: variantImage,
        ),
      );
    }
    notifyListeners();
    _save();
  }

  /// How many units of [productId] are already in this cart.
  int quantityInCart(String productId, {String? variant}) => _items
      .where(
        (i) =>
            i.product.id == productId &&
            (variant == null || i.variant == variant),
      )
      .fold(0, (sum, item) => sum + item.quantity);

  /// Applies [delta] to [item]. Returns false when the change would take
  /// the line above [maxQuantityFor] — the caller shows the "only N
  /// available" message, because CartState deliberately knows nothing
  /// about SnackBars.
  bool updateQuantity(CartItem item, int delta) {
    final next = item.quantity + delta;
    if (next <= 0) {
      _items.remove(item);
    } else if (next > maxQuantityFor(item)) {
      return false;
    } else {
      item.quantity = next;
    }
    notifyListeners();
    _save();
    return true;
  }

  void toggleSelected(CartItem item, bool selected) {
    if (selected && !item.isAvailable) return;
    item.selected = selected;
    notifyListeners();
    _save();
  }

  void setAllSelected(bool selected) {
    for (final item in _items) {
      if (selected && !item.isAvailable) continue;
      item.selected = selected;
    }
    notifyListeners();
    _save();
  }

  /// Selects/deselects every line from one seller — the per-shop
  /// checkbox in the grouped cart. Sits next to [setAllSelected];
  /// selection stays owned here, the screen only groups for display.
  void setSellerSelected(String sellerName, bool selected) {
    for (final item in _items) {
      if (item.product.sellerName != sellerName) continue;
      if (selected && !item.isAvailable) continue;
      item.selected = selected;
    }
    notifyListeners();
    _save();
  }

  /// Removes and returns the item so the caller can offer an Undo
  /// action (see cart_screen.dart) without CartState knowing about
  /// SnackBars.
  CartItem removeItem(CartItem item) {
    _items.remove(item);
    notifyListeners();
    _save();
    return item;
  }

  /// Undo for [removeItem]. Pass the item's original [index] to put it back
  /// where it was; without it (or if the list has shrunk since) it lands at
  /// the end.
  void restoreItem(CartItem item, {int? index}) {
    if (index != null && index >= 0 && index <= _items.length) {
      _items.insert(index, item);
    } else {
      _items.add(item);
    }
    notifyListeners();
    _save();
  }

  /// Called once an order is successfully placed for the selected items.
  void clearSelected() {
    _items.removeWhere((i) => i.selected);
    notifyListeners();
    _save();
  }

  /// Removes everything and returns the snapshot so the caller can offer
  /// an Undo action (same pattern as [removeItem], one level up).
  List<CartItem> clearAll() {
    final snapshot = List<CartItem>.of(_items);
    _items.clear();
    notifyListeners();
    _save();
    return snapshot;
  }

  /// Undo for [clearAll].
  void restoreAll(List<CartItem> snapshot) {
    _items
      ..clear()
      ..addAll(snapshot);
    notifyListeners();
    _save();
  }
}
