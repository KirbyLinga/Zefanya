// lib/utils/timed_snackbar.dart
//
// showTimedSnackBar(): show a SnackBar that is GUARANTEED to go away.
//
// Flutter's own auto-dismiss is fragile for SnackBars that carry an
// `action` (its default changed in 3.29 so they stay until dismissed, and
// older versions also skip the timeout when accessibility navigation is
// on). Rather than depend on that, this schedules its own removal.

import 'dart:async';
import 'package:flutter/material.dart';

void showTimedSnackBar(
  BuildContext context,
  SnackBar snackBar, {
  Duration duration = const Duration(seconds: 3),
}) {
  final messenger = ScaffoldMessenger.of(context);

  // Replace, don't queue: drop whatever is showing (or queued) right now.
  // removeCurrentSnackBar() alone does this — it's an instant, synchronous
  // removal (no exit animation) and starts any queued bar immediately.
  // Do NOT also call clearSnackBars() here: it removes via an *animated*
  // hideCurrentSnackBar() under the hood, and stacking that with this
  // method's instant value-jump races two different removal paths against
  // each other in the same frame. That race is what was leaving the
  // messenger's internal SnackBar queue in a state where a later
  // removeCurrentSnackBar() call (from the Timer below) silently no-ops —
  // i.e. exactly the "it never times out" bug.
  messenger.removeCurrentSnackBar();

  final controller = messenger.showSnackBar(snackBar);
  debugPrint('[timed_snackbar] shown, will remove in ${duration.inMilliseconds}ms');

  // Track whether THIS SnackBar has already gone (swiped away, action
  // tapped, or replaced) so the timer below never removes a newer one.
  var closed = false;
  controller.closed.then((_) => closed = true);

  Timer(duration, () {
    debugPrint('[timed_snackbar] timer fired, alreadyClosed=$closed');
    if (!closed) {
      messenger.removeCurrentSnackBar(reason: SnackBarClosedReason.timeout);
    }
  });
}
