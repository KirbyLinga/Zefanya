// lib/utils/money_format.dart
//
// Single source of truth for Philippine Peso (₱) formatting across Zefanya.

import 'package:intl/intl.dart';

/// Formats a numeric [amount] in Philippine Pesos (₱).
///
/// Output examples:
/// - `formatPeso(1299)` -> `"₱1,299"`
/// - `formatPeso(1299.50)` -> `"₱1,299.50"`
/// - `formatPeso(1299, showDecimals: true)` -> `"₱1,299.00"`
///
/// Shows decimals only when the amount has a non-zero fractional part,
/// unless [showDecimals] is set to true.
String formatPeso(num amount, {bool showDecimals = false}) {
  // Normalise to whole centavos first. This stops a calculated amount such as
  // 980.499999999 from silently becoming "₱980" while 980.50 keeps its cents,
  // and makes the "is there a fractional part?" decision on the same value
  // that is printed.
  final centavos = (amount * 100).round();
  final normalized = centavos / 100;
  final hasFractions = centavos % 100 != 0;
  final decimalDigits = (hasFractions || showDecimals) ? 2 : 0;
  final formatter = NumberFormat.currency(
    symbol: '₱',
    decimalDigits: decimalDigits,
  );
  return formatter.format(normalized);
}
