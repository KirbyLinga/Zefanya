// test/pricing_rules_test.dart
//
// The money rules Cart and Checkout share. These are deliberately unit
// tests, not screenshots: a shipping threshold or a voucher cap is a
// calculation, and the same calculation has to hold on both screens.

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/config/pricing.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/state/cart_state.dart';

const _dress = Product(
  id: 'P-1',
  name: 'Linen Dress',
  category: 'Women',
  price: 500,
  sellerName: 'Willow',
);

const _lamp = Product(
  id: 'P-2',
  name: 'Rattan Lamp',
  category: 'Home & Living',
  price: 1000,
  sellerName: 'Hearth',
);

Voucher _voucher({
  String? category,
  double rate = 0.10,
  double maxDiscount = 200,
}) => Voucher(
  code: 'TEST',
  title: 'Test voucher',
  discountLabel: '10% off',
  minSpend: 0,
  expiryLabel: 'No expiry',
  category: category,
  discountRate: rate,
  maxDiscount: maxDiscount,
);

void main() {
  group('shippingFeeFor', () {
    test('charges the flat fee below the free-shipping line', () {
      expect(shippingFeeFor(0), kStandardShippingFee);
      expect(shippingFeeFor(999.99), kStandardShippingFee);
    });

    test('is free at and above the line', () {
      expect(shippingFeeFor(kFreeShippingThreshold), 0);
      expect(shippingFeeFor(1500), 0);
    });
  });

  group('voucherDiscountFor', () {
    test('percentage discount is capped', () {
      expect(voucherDiscountFor(_voucher(), base: 5000, shippingFee: 60), 200);
    });

    test('percentage discount applies to the given base', () {
      expect(
        voucherDiscountFor(
          _voucher(rate: 0.5, maxDiscount: 80),
          base: 100,
          shippingFee: 60,
        ),
        50,
      );
    });

    test('flat shipping voucher never exceeds the actual shipping fee', () {
      final freeShipping = _voucher(rate: 0, maxDiscount: 60);
      expect(voucherDiscountFor(freeShipping, base: 500, shippingFee: 60), 60);
      expect(voucherDiscountFor(freeShipping, base: 500, shippingFee: 0), 0);
    });
  });

  group('voucherBase', () {
    test('a category voucher only counts selected lines in that category', () {
      final items = [
        CartItem(product: _dress),
        CartItem(product: _lamp),
        CartItem(product: _lamp, selected: false),
      ];

      expect(voucherBase(_voucher(category: 'Home & Living'), items), 1000);
      expect(voucherBase(_voucher(), items), 1500);
    });
  });

  group('isVoucherExpired', () {
    test('a voucher dated yesterday is expired, today is usable', () {
      final now = DateTime.now();
      Voucher at(DateTime day) => Voucher(
        code: 'X',
        title: 'X',
        discountLabel: 'X',
        minSpend: 0,
        expiryLabel: 'X',
        expiresAt: day,
      );

      expect(
        isVoucherExpired(at(now.subtract(const Duration(days: 1)))),
        isTrue,
      );
      expect(isVoucherExpired(at(now)), isFalse);
    });
  });
}
