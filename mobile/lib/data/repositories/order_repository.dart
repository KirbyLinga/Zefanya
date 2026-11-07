// lib/data/repositories/order_repository.dart
//
// The buyer's order history, and the act of placing an order.
//
// ## Why this one moved more than the others
//
// `OrderState` did three jobs: it held the buyer's orders, it *created* them
// from a cart, and it persisted them to `shared_preferences` under its own key.
// Only the first of those is presentation. Creating and persisting an order is
// a data operation, and in live mode it is emphatically the server's:
// `POST /orders` mints the id, prices the lines and decides the status. A state
// class that mints `ORD-10246` locally cannot be switched to a backend without
// rewriting that logic anyway.
//
// So the numbering, the seller grouping and the storage format live in the mock
// implementation, where they belong — unchanged, byte-for-byte the same
// behaviour, but now behind an interface that a live implementation can replace
// wholesale.
//
// ## What stayed in the state
//
// `resolvedOrders(CourierState)` — the join that makes a placed order's status
// follow its delivery job. That is a *cross-state* composition, not a data
// source: it reads two providers and produces a view. Moving it into a
// repository would mean a repository taking another state object as an
// argument, which is the dependency direction this whole pass is trying to
// straighten out.

import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../api/api_exception.dart';
import '../dto/remote_orders.dart';
import '../mock_data.dart';
import 'live_stub.dart';

/// One line of an order being placed.
///
/// A DTO, not a `CartItem`: the data layer must not depend on `state/`, and
/// the server does not want a `Product` object — it wants an id and a quantity
/// and does its own pricing.
///
/// `productId` is the app's own string id (`'2'`, from the catalogue), which is
/// why it is sent as one: PHP receives it as a numeric string, validates it as an
/// integer and casts it, so the app never has to know how the column is typed.
///
/// `unitPrice` and `sellerName` ride along and are **ignored** by the server —
/// deliberately, and the endpoint proves it with a test that posts a price of 1
/// and is still charged the catalogue's price. They are carried because the app
/// shows a total before placing, and a difference between what was shown and what
/// was charged is exactly the kind of thing worth being able to reconcile later.
/// (Who is authoritative on price was OPEN QUESTIONS §0.1; for orders the answer
/// is now the server's own, and `POST /orders` returns its answer in `meta`.)
class OrderLineRequest {
  const OrderLineRequest({
    required this.productId,
    required this.productName,
    required this.sellerName,
    required this.quantity,
    required this.unitPrice,
    this.variantId,
  });

  /// The server SKU id when the line is a specific variant (live catalog).
  final String? variantId;

  final String productId;
  final String productName;
  final String sellerName;
  final int quantity;
  final double unitPrice;

  Map<String, Object?> toJson() => {
    'product_id': productId,
    'quantity': quantity,
    // Sent, not trusted: see the class doc.
    'unit_price': unitPrice,
  };
}

abstract class OrderRepository {
  /// Restores local state before the app's first frame.
  ///
  /// On the interface rather than only on the mock because it is the same
  /// *obligation* in both modes: `main()` awaits it before `runApp` so no
  /// screen flashes empty and then jumps. A live implementation simply has
  /// nothing to restore (the server holds it) and returns.
  Future<void> load();

  /// The buyer's orders, newest first — history and anything placed in this
  /// install, already merged.
  Future<List<Order>> orders();

  /// Places [lines], returning the orders created (empty if [lines] is empty).
  ///
  /// One order per seller, because a marketplace order belongs to one seller —
  /// that is who ships it and who the buyer contacts. The split is the
  /// *server's* rule in live mode and the mock mirrors it, so switching modes
  /// cannot change how many orders a cart produces.
  Future<List<Order>> place(
    List<OrderLineRequest> lines, {
    List<String> voucherCodes = const [],
    double? expectedTotal,
  });

  /// Persists the buyer-controlled part of [order] — receipt confirmation and
  /// cancellation.
  ///
  /// Separate from [place] because it changes an order that already exists.
  /// The live API has no such route yet, so the live implementation names the
  /// missing endpoint instead of pretending a local flag reached the server.
  Future<void> saveBuyerState(Order order);
}

// ---------------------------------------------------------------------------
// Mock — the same behaviour and the same storage format as before
// ---------------------------------------------------------------------------

/// Holds the buyer's orders, persisting them exactly as `OrderState` did.
///
/// The storage key and the JSON shape are unchanged from `OrderState`'s own
/// implementation on purpose: an existing install must be able to upgrade
/// without losing order history, and a silent format change here would do
/// exactly that. Note the `productIds` read is null-safe for the same reason —
/// orders written before that field existed mean "contents unknown", not "bought
/// nothing".
class MockOrderRepository implements OrderRepository {
  static const _storageKey = 'placed_orders';
  static const _buyerStateKey = 'order_buyer_states';

  /// The seeded sample orders use ORD-10xxx numbers up to 10245; new ones
  /// continue from there so a generated id never collides with sample history.
  static const int _firstPlacedNumber = 10246;

  final List<Order> _placed = [];

  /// Buyer-controlled state for any order, including the seeded sample
  /// history. Keyed by id so confirming receipt on ORD-09876 survives a
  /// restart even though that row came from `mockOrders`, not `_placed`.
  final Map<String, Order> _buyerStates = {};

  @override
  Future<void> load() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_storageKey);
      if (raw != null) {
        final decoded = jsonDecode(raw) as List<dynamic>;
        _placed.clear();
        for (final entry in decoded) {
          _placed.add(_orderFromJson(entry as Map<String, dynamic>));
        }
      }

      _buyerStates.clear();
      final statesRaw = prefs.getString(_buyerStateKey);
      if (statesRaw != null) {
        for (final entry in jsonDecode(statesRaw) as List<dynamic>) {
          final order = _orderFromJson(entry as Map<String, dynamic>);
          _buyerStates[order.id] = order;
        }
      }
    } catch (_) {
      // Corrupt/old data shape — start clean rather than crash.
      _placed.clear();
      _buyerStates.clear();
    }
  }

  static Order _orderFromJson(Map<String, dynamic> map) => Order(
    id: map['id'] as String,
    shopName: map['shopName'] as String,
    status: OrderStatus.values.byName(map['status'] as String),
    eta: map['eta'] as String,
    itemSummary: map['itemSummary'] as String,
    productIds:
        (map['productIds'] as List<dynamic>?)?.cast<String>() ?? const [],
    productQuantities:
        (map['productQuantities'] as Map<String, dynamic>?)?.map(
          (id, quantity) => MapEntry(id, (quantity as num).toInt()),
        ) ??
        const {},
    buyerConfirmed: map['buyerConfirmed'] as bool? ?? false,
    cancelled: map['cancelled'] as bool? ?? false,
    cancellationReason: map['cancellationReason'] as String?,
    deliveredAt: DateTime.tryParse(map['deliveredAt'] as String? ?? ''),
    completedAt: DateTime.tryParse(map['completedAt'] as String? ?? ''),
    cancellationAllowed: map['cancellationAllowed'] as bool?,
  );

  static Map<String, Object?> _orderToJson(Order o) => {
    'id': o.id,
    'shopName': o.shopName,
    'status': o.status.name,
    'eta': o.eta,
    'itemSummary': o.itemSummary,
    'productIds': o.productIds,
    'productQuantities': o.productQuantities,
    'buyerConfirmed': o.buyerConfirmed,
    'cancelled': o.cancelled,
    'cancellationReason': o.cancellationReason,
    'deliveredAt': o.deliveredAt?.toIso8601String(),
    'completedAt': o.completedAt?.toIso8601String(),
    'cancellationAllowed': o.cancellationAllowed,
  };

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
      _storageKey,
      jsonEncode([for (final o in _placed) _orderToJson(o)]),
    );
    await prefs.setString(
      _buyerStateKey,
      jsonEncode([for (final o in _buyerStates.values) _orderToJson(o)]),
    );
  }

  @override
  Future<List<Order>> orders() async => List.unmodifiable([
    for (final order in [..._placed, ...mockOrders])
      _buyerStates[order.id] ?? order,
  ]);

  @override
  Future<void> saveBuyerState(Order order) async {
    _buyerStates[order.id] = order;
    await _save();
  }

  @override
  Future<List<Order>> place(
    List<OrderLineRequest> lines, {
    List<String> voucherCodes = const [],
    double? expectedTotal,
  }) async {
    if (lines.isEmpty) return const [];

    // Group by seller, keeping the order sellers first appear in the cart.
    final bySeller = <String, List<OrderLineRequest>>{};
    for (final line in lines) {
      bySeller.putIfAbsent(line.sellerName, () => []).add(line);
    }

    final created = <Order>[];
    for (final entry in bySeller.entries) {
      final summary = entry.value
          .map(
            (l) => l.quantity > 1
                ? '${l.productName} ×${l.quantity}'
                : l.productName,
          )
          .join(', ');

      // Newest first. Each new order goes to the front, so a checkout with two
      // sellers ends up with the later seller's order on top; fine, they were
      // placed in the same instant.
      final quantities = <String, int>{};
      for (final line in entry.value) {
        quantities.update(
          line.productId,
          (quantity) => quantity + line.quantity,
          ifAbsent: () => line.quantity,
        );
      }

      final order = Order(
        id: 'ORD-${_firstPlacedNumber + _placed.length}',
        shopName: entry.key,
        status: OrderStatus.toShip,
        eta: 'Seller ships within 1-2 days',
        itemSummary: summary,
        // The ids the summary above was built from. Checkout used to drop them
        // here, which is what left order history unable to reference the
        // catalogue at all.
        productIds: quantities.keys.toList(),
        productQuantities: quantities,
      );
      _placed.insert(0, order);
      created.add(order);
    }

    await _save();
    return created;
  }
}

// ---------------------------------------------------------------------------
// Live
// ---------------------------------------------------------------------------

/// Implemented by repositories whose orders are priced by a server: the
/// checkout screen asks for the server's payable total and shows it before
/// the buyer places the order (live mode only; the mock prices locally).
abstract class QuotesCheckout {
  /// The server's payable total (pesos) for [lines] with [voucherCodes]
  /// applied, including shipping. Throws the server's refusal.
  Future<double> quoteTotal(
    List<OrderLineRequest> lines, {
    List<String> voucherCodes = const [],
  });
}

class LiveOrderRepository extends LiveRepository
    implements OrderRepository, QuotesCheckout {
  LiveOrderRepository(super.client);

  @override
  Future<void> load() async {
    // Live orders are read from the server on demand; nothing to restore.
  }

  /// The buyer's per-seller shipments (WebZefanya `GET /api/buyer/shipments`).
  @override
  Future<List<Order>> orders() async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    final data = unwrapMap(await client.getJson('/buyer/shipments'));
    final rows = data['data'];
    return [
      for (final row in rows is List ? rows : const [])
        if (row is Map) orderFromShipmentJson(row.cast<String, Object?>()),
    ];
  }

  /// Receipt confirmation and cancellation go to WebZefanya's own routes,
  /// which run the same services as the web order page (row locks, stock
  /// restore on cancel, 409 when the seller already accepted).
  @override
  Future<void> saveBuyerState(Order order) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    final parent = order.serverOrderId;
    if (parent == null || parent.isEmpty) {
      notWired('POST /buyer/orders/{order}/shipments/{shipment}/…');
    }
    final base = '/buyer/orders/$parent/shipments/${order.id}';

    if (order.cancelled) {
      await client.postJson(
        '$base/cancel',
        body: {'reason': order.cancellationReason ?? 'Cancelled by buyer'},
      );
    } else if (order.buyerConfirmed) {
      await client.postJson('$base/confirm-received');
    }
  }

  @override
  Future<double> quoteTotal(
    List<OrderLineRequest> lines, {
    List<String> voucherCodes = const [],
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final quote = unwrapMap(
      await client.postJson(
        '/v1/buyer/checkout/quote',
        body: {
          'lines': [
            for (final line in lines)
              {
                'product_id': line.productId,
                if (line.variantId != null) 'variant_id': line.variantId,
                'quantity': line.quantity,
              },
          ],
          'voucher_codes': voucherCodes,
        },
      ),
    );
    return _payable(quote) / 100;
  }

  /// Places a Cash-on-Delivery order through WebZefanya's checkout:
  /// the lines are synced to the buyer's server cart, the vouchers are
  /// applied (and re-validated) by the server, a fresh quote is taken, and
  /// the order is placed against that quote's one-time token and review
  /// fingerprints. If the server's total differs from [expectedTotal] the
  /// order is not placed (409) so the buyer never pays a total they did not see.
  @override
  Future<List<Order>> place(
    List<OrderLineRequest> lines, {
    List<String> voucherCodes = const [],
    double? expectedTotal,
  }) async {
    if (lines.isEmpty) return const [];
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    final quote = await _quote(lines, voucherCodes);
    final payable = _payable(quote);
    if (expectedTotal != null && (expectedTotal * 100).round() != payable) {
      throw RequestRejectedException(
        statusCode: 409,
        message:
            'The order total is now ${_peso(payable)}. '
            'Review it and place your order again.',
      );
    }

    final fingerprints = (quote['fingerprints'] as Map?) ?? const {};
    final body = unwrapMap(
      await client.postJson(
        '/buyer/checkout',
        body: {
          'item_ids': quote['item_ids'],
          'checkout_token': quote['checkout_token'],
          'carriers': quote['carriers'],
          'vouchers': quote['vouchers_state'],
          if (expectedTotal != null)
            'expected_total': expectedTotal.toStringAsFixed(2),
          'price_fingerprint': fingerprints['price'],
          'address_fingerprint': fingerprints['address'],
          'shipping_fingerprint': fingerprints['shipping'],
          'voucher_fingerprint': fingerprints['voucher'],
        },
      ),
    );

    final orderId = body['id']?.toString();
    final shipments = body['shipments'];
    return [
      for (final row in shipments is List ? shipments : const [])
        if (row is Map)
          orderFromShipmentJson({
            ...row.cast<String, Object?>(),
            if (orderId != null) 'order_id': orderId,
          }),
    ];
  }

  /// Syncs [lines] into the server cart, applies [voucherCodes] and returns
  /// the checkout quote, with `carriers` (the first offered carrier per shop,
  /// the same default the web checkout shows) added for the place call.
  Future<Map<String, Object?>> _quote(
    List<OrderLineRequest> lines,
    List<String> voucherCodes,
  ) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    final itemIds = await _syncCart(lines);

    Map<String, Object?> vouchers = const {};
    for (final code in voucherCodes) {
      final applied = unwrapMap(
        await client.postJson(
          '/buyer/cart/vouchers',
          body: {'code': code, 'vouchers': vouchers, 'selected_ids': itemIds},
        ),
      );
      vouchers = ((applied['vouchers_state'] as Map?) ?? const {})
          .cast<String, Object?>();
    }

    var quote = unwrapMap(
      await client.postJson(
        '/buyer/checkout/quote',
        body: {'item_ids': itemIds, 'vouchers': vouchers},
      ),
    );

    final shipping = (quote['shipping'] as Map?) ?? const {};
    if (shipping['all_routes_available'] != true) {
      throw const RequestRejectedException(
        statusCode: 422,
        message:
            'Shipping is not available to your address for one or more '
            'shops. Check your delivery address.',
      );
    }

    final carriers = <String, Object?>{};
    for (final row in (shipping['options'] as List?) ?? const []) {
      if (row is! Map) continue;
      final offered = row['carriers'];
      if (offered is List && offered.isNotEmpty && offered.first is Map) {
        carriers['${row['seller_id']}'] = (offered.first as Map)['id'];
      }
    }

    // Re-quote with the explicit carrier choice so the token, fees and
    // fingerprints all describe exactly what will be submitted.
    quote = unwrapMap(
      await client.postJson(
        '/buyer/checkout/quote',
        body: {'item_ids': itemIds, 'vouchers': vouchers, 'carriers': carriers},
      ),
    );
    return {...quote, 'carriers': carriers};
  }

  /// Makes the server cart hold exactly these quantities for these lines and
  /// returns their server ids. Other lines in the server cart are left alone.
  Future<List<int>> _syncCart(List<OrderLineRequest> lines) async {
    var cart = unwrapMap(await client.getJson('/buyer/cart'));
    final ids = <int>[];

    for (final line in lines) {
      final productId = int.tryParse(line.productId);
      if (productId == null) {
        throw ValidationException(
          fieldErrors: {'product_id': '${line.productName} is not available.'},
        );
      }
      final variantId = line.variantId == null
          ? null
          : int.tryParse(line.variantId!);

      final existing = _findLine(cart, productId, variantId);
      if (existing != null) {
        cart = unwrapMap(
          await client.patchJson(
            '/buyer/cart/items/${existing['id']}',
            body: {'quantity': line.quantity},
          ),
        );
      } else {
        cart = unwrapMap(
          await client.postJson(
            '/buyer/cart/items',
            body: {
              'product_id': productId,
              'variant_id': ?variantId,
              'quantity': line.quantity,
            },
          ),
        );
      }

      final synced = _findLine(cart, productId, variantId);
      if (synced == null) {
        throw RequestRejectedException(
          statusCode: 409,
          message: '${line.productName} could not be added to your order.',
        );
      }
      final quantity = (synced['quantity'] as num?)?.toInt() ?? 0;
      if (quantity < line.quantity) {
        throw RequestRejectedException(
          statusCode: 409,
          message:
              'Only $quantity left for ${line.productName}. '
              'Reduce the quantity and try again.',
        );
      }
      ids.add((synced['id'] as num).toInt());
    }
    return ids;
  }

  Map<Object?, Object?>? _findLine(
    Map<String, Object?> cart,
    int productId,
    int? variantId,
  ) {
    for (final row in (cart['items'] as List?) ?? const []) {
      if (row is! Map) continue;
      if ((row['product_id'] as num?)?.toInt() != productId) continue;
      if (variantId != null &&
          (row['variant_id'] as num?)?.toInt() != variantId) {
        continue;
      }
      return row;
    }
    return null;
  }

  int _payable(Map<String, Object?> quote) =>
      (((quote['totals'] as Map?) ?? const {})['payable_total_minor'] as num?)
          ?.toInt() ??
      0;

  String _peso(int minor) => '₱${(minor / 100).toStringAsFixed(2)}';
}
