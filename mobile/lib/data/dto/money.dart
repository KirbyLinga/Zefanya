// lib/data/dto/money.dart
//
// Money crosses one boundary and this is it.
//
// ## The two representations, and why they are both right
//
// The Laravel side stores money as **integer centavos** (`*_minor` columns)
// and reports it that way, which is the correct representation for money: an
// integer cannot accumulate the rounding error that makes `0.1 + 0.2` come out
// as `0.30000000000000004`, and a payment amount is exactly the value you must
// not drift.
//
// This Flutter app holds prices as **peso doubles** (`Product.price`,
// `DeliveryJob.fee`, `CartItem.lineTotal`). That is also correct *for its
// job*: every one of those numbers is used for display, for summing a cart,
// or for comparing to a threshold, and all of them are already inside
// `toStringAsFixed(2)` at the point they're shown. Rewriting the UI layer to
// integers would be a large change touching every price in the app, with its
// own class of bug (a fixpoint divide forgotten in one place reads as a
// ₱12,000 dress).
//
// So the conversion happens here, once, in both directions, and **no other
// file in the app is allowed to divide or multiply by 100**. If you find
// yourself typing `/ 100` next to a JSON field, that is the bug this file
// exists to prevent.
//
// ## The rule for rounding, which is not obvious
//
// Rounding is a real question when going pesos -> centavos, because a double
// can hold values centavos cannot express. `.round()` is used rather than
// `.floor()`/`.truncate()` because it is the only one of the three that is
// symmetric about zero and lands on the *nearest* centavo: with `floor`, a
// computed discount of 199.99999999999997 becomes ₱1.99 instead of ₱2.00, and
// the app would report a total one centavo lower than the sum of its own
// lines. Whether the server agrees is a contract question — see the note in
// docs/api-contract.md about who computes the total (OPEN QUESTIONS).

/// How many minor units make one peso. Fixed by the currency, not by app
/// config: PHP has 100 centavos and that is not a tunable.
const int kMinorUnitsPerPeso = 100;

/// A server money value (integer centavos) as the peso double this app uses.
///
/// Reading: `12050` centavos -> `120.5` pesos.
double pesosFromMinor(int minor) => minor / kMinorUnitsPerPeso;

/// A peso double as the integer centavos a request body should carry.
int minorFromPesos(double pesos) => (pesos * kMinorUnitsPerPeso).round();

/// Formats a minor-unit value for display, e.g. `12050` -> `'₱120.50'`.
///
/// Provided so a screen showing a server value never has to reach for the
/// arithmetic itself: it can go straight from the DTO to the string.
String formatMinor(int minor) => '₱${pesosFromMinor(minor).toStringAsFixed(2)}';

/// Reads a money field out of decoded JSON, in **minor units**.
///
/// Defensive by design, and the leniency is deliberate rather than sloppy:
/// PHP's `json_encode` renders a large integer as a number, but several
/// stacks (and anything passing through a JSON column or a `DECIMAL` cast)
/// re-emit it as a *string*. Rejecting one of those shapes would fail the
/// whole response over a type difference with no meaning, so both are
/// accepted. A double is accepted too, and rounded, for the same reason.
///
/// Returns null for null/absent/unparseable input rather than throwing: a
/// missing price should surface as a specific field the UI can omit, not as
/// an exception that takes the screen down. Callers decide what a missing
/// price means for them.
int? minorFromJson(Object? value) {
  if (value == null) return null;
  if (value is int) return value;
  if (value is String) return int.tryParse(value);
  if (value is double) return value.round();
  return null;
}

/// Reads a money field out of decoded JSON, in **peso doubles**.
///
/// The convenience wrapper for a caller that wants to assign straight into an
/// existing `double` field. It routes through [minorFromJson] rather than
/// parsing the number itself, so there is still exactly one place that knows
/// the scale factor.
double? pesosFromJson(Object? value) {
  final minor = minorFromJson(value);
  return minor == null ? null : pesosFromMinor(minor);
}

/// [pesosFromJson] for a field that is required, with [fallback] when it is
/// missing or unreadable.
///
/// A zero default would be wrong here: "the server didn't say" and "this is
/// free" are different facts, and collapsing them would let a malformed
/// response render as a ₱0.00 product instead of as an error.
double pesosFromJsonOr(Object? value, double fallback) =>
    pesosFromJson(value) ?? fallback;