// lib/utils/scroll_to_top.dart
//
// Re-tap-active-tab scroll-to-top (Tier 1, task 5).
//
// Finds the tab subtree's currently attached vertical ScrollPositions
// directly, rather than handing every tab a shared controller: the
// Orders tab is a TabBarView of four lists, and mid-swipe two of them
// can be mounted at once — a single shared PrimaryScrollController
// would then throw "attached to multiple scroll views". Querying the
// live positions sidesteps that entirely, and switching tabs is
// untouched (this only ever scrolls, never changes the index).

import 'package:flutter/material.dart';

/// Scrolls every attached vertical scrollable under [context] to offset 0.
///
/// No-op when nothing is attached. Animates each position so a mid-swipe
/// Orders sub-tab settles rather than jumps.
void scrollTabToTop(BuildContext context) {
  final positions = <ScrollPosition>[];
  void collect(Element element) {
    if (element is StatefulElement && element.state is ScrollableState) {
      final state = element.state as ScrollableState;
      final position = state.position;
      if (position.axis == Axis.vertical) positions.add(position);
    }
    element.visitChildren(collect);
  }

  (context as Element).visitChildren(collect);
  for (final position in positions) {
    position.animateTo(
      0,
      duration: const Duration(milliseconds: 350),
      curve: Curves.easeOutCubic,
    );
  }
}
