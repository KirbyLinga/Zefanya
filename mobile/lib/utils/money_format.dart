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
  final hasFractions = (amount % 1).abs() > 0.0001;
  final decimalDigits = (hasFractions || showDecimals) ? 2 : 0;
  final formatter = NumberFormat.currency(
    symbol: '₱',
    decimalDigits: decimalDigits,
  );
  return formatter.format(amount);
}
