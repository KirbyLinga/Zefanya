// lib/utils/weekly_buckets.dart
//
// Seven-day payout bucketing for the courier earnings chart.
//
// Pure data-in/data-out: (payout, day) pairs in, 7 day-totals out
// (oldest first). Days are calendar days in local time — constructed
// as DateTime(y, m, d - i), never as today.subtract(Duration(days: i)),
// which can land an hour off across a daylight-saving change and break
// the day-equality check for users in such zones.

/// One credited payout on its calendar day.
class DatedPayout {
  const DatedPayout({required this.amount, required this.when});

  final double amount;
  final DateTime when;
}

/// Totals for the last 7 calendar days ending today, oldest first.
///
/// [now] is a parameter (defaulting to DateTime.now) so tests can pin
/// the week boundary deterministically.
List<double> last7DayTotals(List<DatedPayout> payouts, {DateTime? now}) {
  final current = now ?? DateTime.now();
  final today = DateTime(current.year, current.month, current.day);
  return [
    for (var i = 6; i >= 0; i--)
      payouts
          .where((p) {
            final local = p.when.toLocal();
            final day = DateTime(local.year, local.month, local.day);
            return day ==
                DateTime(today.year, today.month, today.day - i);
          })
          .fold(0.0, (sum, p) => sum + p.amount),
  ];
}

/// Weekday labels (Mon-first single letters) aligned with [last7DayTotals]:
/// index 6 is today, index 0 six days ago.
List<String> last7DayLabels({DateTime? now}) {
  const letters = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
  final weekday = (now ?? DateTime.now()).weekday; // Mon=1..Sun=7
  return [
    // (weekday - 1 - i) can go negative mid-week; Dart's % keeps the
    // sign, so add 7 first to keep the index in range.
    for (var i = 6; i >= 0; i--) letters[(weekday - 1 - i + 7) % 7],
  ];
}
