// lib/config/pricing.dart
//
// The app's money rules, in one place, so a banner can't promise something
// checkout doesn't do. Until this existed the promo carousel advertised
// "free shipping over ₱1,000" while checkout charged a flat ₱60 on
// everything, and the "Home & Living" voucher discounted any product.
//
// Cart, Checkout and the promo copy all read from here. If you change a
// number, change the matching text in widgets/promo_carousel.dart (its
// slide strings are const, so they can't interpolate these).
//
// ## Why the quote lives here now
//
// A cart can carry one store voucher per seller, one platform voucher and one
// shipping voucher. The discount each one gets depends on which selected lines
// it covers and on the shipping fee, so computing the total in a screen would
// make Cart and Checkout disagree the moment either one changed. `quoteCart`
// is the single calculation both screens call.
//
// This file deliberately does not import `state/cart_state.dart`: `CartItem`
// implements [PricedLine], and the dependency points one way. Before that, a
// shared quote here created an import cycle.

import 'dart:math' as math;

import '../data/mock_data.dart';

/// What shipping costs the buyer on an order below the free-shipping line.
/// Also what a courier is paid per delivery — free shipping is the
/// platform absorbing the fee, not the courier working for nothing.
const double kStandardShippingFee = 60;

/// Selected-items subtotal (before any voucher) at or above which the
/// buyer's shipping is free.
const double kFreeShippingThreshold = 1000;

/// What the buyer pays for shipping on a cart with this subtotal.
double shippingFeeFor(double subtotal) =>
    subtotal >= kFreeShippingThreshold ? 0 : kStandardShippingFee;

/// The minimum shape a pricing line has to expose.
///
/// `CartItem` is the production implementation. Keeping this interface here
/// means the pricing rules can be unit-tested with plain fixtures without the
/// state layer having to import the pricing layer and the pricing layer having
/// to import the state layer back.
abstract interface class PricedLine {
  bool get selected;
  double get lineTotal;
  String get category;
  String get sellerName;

  /// A seller-specific flat shipping fee, or null for the platform rule.
  double? get shippingFee;
}

/// Shipping charged for the selected [items], grouped by seller.
///
/// A seller with an explicit [PricedLine.shippingFee] uses that rate for the
/// whole group and is not waived by the platform free-shipping threshold. A
/// seller without one falls back to [shippingFeeFor] on that seller's
/// subtotal — the old single-store behaviour, preserved for every catalogue
/// that has not supplied a rate.
double shippingForItems(Iterable<PricedLine> items) {
  final groups = <String, List<PricedLine>>{};
  for (final item in items) {
    if (!item.selected) continue;
    groups.putIfAbsent(item.sellerName, () => []).add(item);
  }

  var total = 0.0;
  for (final group in groups.values) {
    double? explicit;
    for (final line in group) {
      if (line.shippingFee != null) {
        explicit = line.shippingFee;
        break;
      }
    }
    if (explicit != null) {
      total += explicit;
      continue;
    }
    final groupSubtotal = group.fold(0.0, (sum, line) => sum + line.lineTotal);
    total += shippingFeeFor(groupSubtotal);
  }
  return total;
}

/// The part of the cart a voucher applies to: every selected line, or, for a
/// store/category voucher, only the selected lines it covers. Both the
/// minimum-spend check and the discount use this — not the whole cart.
double voucherBase(Voucher voucher, Iterable<PricedLine> items) {
  return items
      .where((item) {
        if (!item.selected) return false;
        if (voucher.scope == VoucherScope.store &&
            voucher.sellerName != null &&
            item.sellerName != voucher.sellerName) {
          return false;
        }
        if (voucher.category != null && item.category != voucher.category) {
          return false;
        }
        return true;
      })
      .fold(0.0, (sum, item) => sum + item.lineTotal);
}

/// Whether [voucher] can no longer be used (calendar-day, local).
///
/// Null [Voucher.expiresAt] means "no computable date" — treated as
/// usable, since the voucher never stated an expiry. Checked at apply
/// time and by the cart's validity sweep, so an expired code can
/// neither be applied nor linger once its day passes.
bool isVoucherExpired(Voucher voucher, {DateTime? now}) {
  final expiresAt = voucher.expiresAt;
  if (expiresAt == null) return false;
  final local = expiresAt.toLocal();
  final current = (now ?? DateTime.now()).toLocal();
  final today = DateTime(current.year, current.month, current.day);
  final day = DateTime(local.year, local.month, local.day);
  return day.isBefore(today);
}

/// Whether [voucher] has a start time in the future.
bool isVoucherNotStarted(Voucher voucher, {DateTime? now}) {
  final startsAt = voucher.startsAt;
  if (startsAt == null) return false;
  return (now ?? DateTime.now()).isBefore(startsAt.toLocal());
}

/// Whether the voucher's total-use cap is used up.
bool isVoucherUsageExhausted(Voucher voucher, {int buyerUses = 0}) {
  if (voucher.usageExhausted) return true;
  final perBuyer = voucher.perBuyerLimit;
  return perBuyer != null && buyerUses >= perBuyer;
}

/// How much [voucher] takes off.
///
/// Shipping vouchers discount the shipping fee (capped by it). Fixed
/// merchandise vouchers discount the eligible base. Percentage vouchers use
/// the base up to their cap. The legacy "rate == 0 with no fixed amount" shape
/// still means a shipping rebate, so older vouchers and tests keep working.
double voucherDiscountFor(
  Voucher voucher, {
  required double base,
  required double shippingFee,
}) {
  if (voucher.isShipping ||
      (voucher.fixedDiscount == null && voucher.discountRate <= 0)) {
    final cap = voucher.fixedDiscount ?? voucher.maxDiscount;
    return math.min(cap, shippingFee).clamp(0, shippingFee).toDouble();
  }
  if (voucher.fixedDiscount != null) {
    return math.min(voucher.fixedDiscount!, base).clamp(0, base).toDouble();
  }
  return (base * voucher.discountRate)
      .clamp(0, voucher.maxDiscount)
      .toDouble();
}

/// One voucher's contribution to a quote.
class VoucherDiscount {
  const VoucherDiscount({required this.voucher, required this.amount});

  final Voucher voucher;
  final double amount;
}

/// The authoritative cart arithmetic: merchandise, per-seller shipping, the
/// discounts actually earned, and the payable total.
class CartQuote {
  const CartQuote({
    required this.merchandise,
    required this.shipping,
    required this.merchandiseDiscount,
    required this.shippingDiscount,
    required this.voucherDiscounts,
  });

  final double merchandise;
  final double shipping;
  final double merchandiseDiscount;
  final double shippingDiscount;
  final List<VoucherDiscount> voucherDiscounts;

  double get discount => merchandiseDiscount + shippingDiscount;

  double get total => (merchandise - merchandiseDiscount + shipping - shippingDiscount)
      .clamp(0, double.infinity)
      .toDouble();

  bool get hasVoucherDiscount => voucherDiscounts.isNotEmpty;
}

/// Quotes selected [items] with [vouchers] already applied.
///
/// The caller is responsible for keeping only one voucher in each stack slot.
/// Ineligible vouchers (expired, not started, below minimum, used up) contribute
/// nothing, which makes this safe to call while the cart is being edited and a
/// previously eligible voucher temporarily stops qualifying.
CartQuote quoteCart(
  Iterable<PricedLine> items,
  Iterable<Voucher> vouchers, {
  DateTime? now,
  int Function(Voucher voucher)? buyerUsesFor,
}) {
  final selected = items.where((item) => item.selected).toList(growable: false);
  final merchandise = selected.fold(0.0, (sum, item) => sum + item.lineTotal);
  final shipping = shippingForItems(selected);

  var merchandiseDiscount = 0.0;
  var shippingDiscount = 0.0;
  final applied = <VoucherDiscount>[];
  final seen = <String>{};

  for (final voucher in vouchers) {
    if (!seen.add(voucher.code)) continue;
    if (isVoucherExpired(voucher, now: now) ||
        isVoucherNotStarted(voucher, now: now) ||
        isVoucherUsageExhausted(
          voucher,
          buyerUses: buyerUsesFor?.call(voucher) ?? 0,
        )) {
      continue;
    }
    if (voucher.scope == VoucherScope.store &&
        (voucher.sellerName == null || voucher.sellerName!.isEmpty)) {
      continue;
    }

    final base = voucherBase(voucher, selected);
    if (base < voucher.minSpend) continue;

    final isShipping = voucher.isShipping ||
        (voucher.fixedDiscount == null && voucher.discountRate <= 0);
    if (isShipping) {
      final amount = voucherDiscountFor(
        voucher,
        base: merchandise,
        shippingFee: (shipping - shippingDiscount).clamp(0, shipping),
      );
      if (amount <= 0) continue;
      shippingDiscount += amount;
      applied.add(VoucherDiscount(voucher: voucher, amount: amount));
    } else {
      final amount = voucherDiscountFor(
        voucher,
        base: base,
        shippingFee: 0,
      );
      if (amount <= 0) continue;
      merchandiseDiscount += amount;
      applied.add(VoucherDiscount(voucher: voucher, amount: amount));
    }
  }

  return CartQuote(
    merchandise: merchandise,
    shipping: shipping,
    merchandiseDiscount: merchandiseDiscount,
    shippingDiscount: shippingDiscount,
    voucherDiscounts: List.unmodifiable(applied),
  );
}
