// lib/utils/search_chips.dart
//
// Dimming + ranking for the "Try looking for" chips (Claude Round A).
//
// Two call sites, two contracts:
//   * Idle row: [rankSearchChips] — matches rise to the front in
//     stable order, the rest dim. The idle query is always '' (any
//     keystroke swaps to results), so in practice this is the
//     authored order, undimmed — but the helper still owns the rule.
//   * Dead-end retry row: [dimSearchChips] — authored order is kept
//     (reordering a Wrap mid-typing jumps the layout); only the
//     dim flag reacts to the query.
// Pure list-in/list-out like sortProducts / filterProducts, so both
// can be unit-tested without pumping widgets.

/// One chip: its term and whether the widget should dim it.
class RankedChip {
  const RankedChip(this.term, {this.dimmed = false});

  final String term;
  final bool dimmed;
}

bool _matches(String term, String needle) =>
    term.toLowerCase().contains(needle);

/// Matches-first ranking for the idle row. Never mutates the input.
List<RankedChip> rankSearchChips(List<String> terms, String query) {
  final needle = query.trim().toLowerCase();
  if (needle.isEmpty) {
    return [for (final t in terms) RankedChip(t)];
  }
  final hits = <RankedChip>[];
  final rest = <RankedChip>[];
  for (final t in terms) {
    if (_matches(t, needle)) {
      hits.add(RankedChip(t));
    } else {
      rest.add(RankedChip(t, dimmed: true));
    }
  }
  return [...hits, ...rest];
}

/// Dim-only ranking for the dead-end retry row: authored order is
/// preserved (a Wrap reorder per keystroke would jump); non-matches
/// are flagged dimmed. Never mutates the input.
List<RankedChip> dimSearchChips(List<String> terms, String query) {
  final needle = query.trim().toLowerCase();
  if (needle.isEmpty) {
    return [for (final t in terms) RankedChip(t)];
  }
  return [
    for (final t in terms)
      RankedChip(t, dimmed: !_matches(t, needle)),
  ];
}
