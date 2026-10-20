// test/money_format_test.dart

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/utils/money_format.dart';

void main() {
  group('formatPeso', () {
    test('formats whole numbers without decimals by default', () {
      expect(formatPeso(1299), '₱1,299');
      expect(formatPeso(0), '₱0');
      expect(formatPeso(50), '₱50');
      expect(formatPeso(1000000), '₱1,000,000');
    });

    test('formats numbers with fractional parts with 2 decimal places', () {
      expect(formatPeso(1299.5), '₱1,299.50');
      expect(formatPeso(1299.75), '₱1,299.75');
      expect(formatPeso(0.25), '₱0.25');
    });

    test('forces 2 decimal places when showDecimals is true', () {
      expect(formatPeso(1299, showDecimals: true), '₱1,299.00');
      expect(formatPeso(0, showDecimals: true), '₱0.00');
    });

    test('formats negative amounts with minus sign before currency', () {
      expect(formatPeso(-120), '-₱120');
      expect(formatPeso(-50.5), '-₱50.50');
    });
  });
}
