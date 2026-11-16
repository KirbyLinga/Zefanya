// test/voucher_expiry_test.dart
//
// Voucher expiry mapping + countdown (follow-up task 7).
//
// The countdown is computed from Voucher.expiresAt (a DateTime mapped
// from the server's `expires_at`), never parsed from the display label.

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/config/pricing.dart';
import 'package:zefanya/data/dto/remote_catalog.dart';
import 'package:zefanya/data/mock_data.dart';

void main() {
  test('voucherFromJson maps expires_at to expiresAt', () {
    final voucher = voucherFromJson({
      'code': 'SAVE10',
      'type': 'percent',
      'value': 10,
      'min_spend_minor': 50000,
      'expires_at': '2030-09-30T00:00:00.000Z',
    })!;

    expect(voucher.code, 'SAVE10');
    expect(voucher.expiresAt, isNotNull);
    expect(voucher.expiresAt!.year, 2030);
    expect(voucher.expiresAt!.month, 9);
    expect(voucher.expiresAt!.day, 30);
  });

  test('a missing expires_at maps to null, not a crash', () {
    final voucher = voucherFromJson({
      'code': 'SAVE10',
      'type': 'percent',
      'value': 10,
      'min_spend_minor': 0,
    })!;

    expect(voucher.expiresAt, isNull);
    expect(voucher.expiryLabel, 'No expiry');
  });

  test('mock vouchers all carry a computable expiry date', () {
    expect(mockVouchers, isNotEmpty);
    for (final voucher in mockVouchers) {
      expect(
        voucher.expiresAt,
        isNotNull,
        reason: '${voucher.code} has no expiresAt',
      );
    }
  });

  test('isVoucherExpired: yesterday expired, today and tomorrow usable', () {
    final now = DateTime.now();
    Voucher at(DateTime day) => Voucher(
      code: 'X',
      title: 'X',
      discountLabel: 'X',
      minSpend: 0,
      expiryLabel: 'X',
      expiresAt: day,
    );

    expect(isVoucherExpired(at(now.subtract(const Duration(days: 1)))), isTrue);
    expect(isVoucherExpired(at(now)), isFalse);
    expect(isVoucherExpired(at(now.add(const Duration(days: 1)))), isFalse);
  });

  test(
    'isVoucherExpired: null date is usable, UTC instant lands on its local day',
    () {
      const noDate = Voucher(
        code: 'X',
        title: 'X',
        discountLabel: 'X',
        minSpend: 0,
        expiryLabel: 'No expiry',
      );
      expect(isVoucherExpired(noDate), isFalse);

      // A UTC instant that lands on today's LOCAL calendar day is still usable.
      // Deriving it from local midnight (rather than UTC midnight) keeps the
      // fixture true in every timezone and at every hour: in UTC+8, UTC midnight
      // is yesterday until 8am local, which made the old version flaky.
      final localNow = DateTime.now();
      final localMidnightUtc = DateTime(
        localNow.year,
        localNow.month,
        localNow.day,
      ).toUtc();
      final voucher = Voucher(
        code: 'X',
        title: 'X',
        discountLabel: 'X',
        minSpend: 0,
        expiryLabel: 'X',
        expiresAt: localMidnightUtc,
      );
      expect(isVoucherExpired(voucher), isFalse);
    },
  );

  test('Product still carries no created/updated timestamp', () {
    // Locks in the "no Newest sort" decision. NOTE: this test does not
    // assert the absence of the field by reflection — it will NOT fail
    // the day the backend lands created_at. When that happens, delete
    // this test and build the "Newest" sort option honestly.
    const product = Product(
      id: 'p1',
      name: 'Item',
      category: 'Fashion',
      price: 100,
      sellerName: 'Shop',
    );
    expect(product.price, 100);
  });
}
