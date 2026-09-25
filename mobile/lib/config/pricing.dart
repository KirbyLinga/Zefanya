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

import '../data/mock_data.dart';
import '../state/cart_state.dart';

/// What shipping costs the buyer on an order below the free-shipping line.
/// Also what a courier is paid per delivery — free shipping is the
/// platform absorbing the fee, not the courier working for nothing.
const double kStandardShippingFee = 60;

/// Selected-items subtotal (before any voucher) at or above which the
/// buyer's shipping is free.
const double kFreeShippingThreshold = 1000;

/// What the buyer pays for shipping on a cart with this subtotal.
double shippingFeeFor(double subtotal) => subtotal >= kFreeShippingThreshold ? 0 : kStandardShippingFee;

/// The part of the cart a voucher applies to: every selected line, or, for
/// a category voucher, only the selected lines in that category. Both the
/// minimum-spend check and the percentage discount use this — not the whole
/// cart — so a Home & Living voucher can't be earned or paid out on a
/// dress.
double voucherBase(Voucher voucher, Iterable<CartItem> items) {
  return items
      .where((i) => i.selected && (voucher.category == null || i.product.category == voucher.category))
      .fold(0.0, (sum, i) => sum + i.lineTotal);
}

/// How much [voucher] takes off. Percentage vouchers use [base] (see
/// [voucherBase]) up to their cap. Flat vouchers (discountRate == 0, this
/// catalog's convention for shipping rebates) are capped at what shipping
/// actually costs, so FREESHIP can't also knock ₱60 off the goods once
/// shipping is already free.
double voucherDiscountFor(Voucher voucher, {required double base, required double shippingFee}) {
  if (voucher.discountRate <= 0) {
    return voucher.maxDiscount < shippingFee ? voucher.maxDiscount : shippingFee;
  }
  return (base * voucher.discountRate).clamp(0, voucher.maxDiscount).toDouble();
}
