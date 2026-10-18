// lib/screens/courier/widgets/delivery_completion_view.dart
//
// The screen a courier gets the instant a delivery lands — the moment this
// whole app exists to produce.
//
// Extracted from delivery_tracker_screen.dart for two reasons. It was a
// whole screen's worth of layout sitting inside a State class that also owns
// the milestone machine; and a pure, data-in widget can be *rendered* in a
// test, which is how its two outcomes, both palettes and its behaviour on a
// short screen are checked rather than assumed (see
// test/delivery_completion_golden_test.dart).
//
// ## Tempo: this is deliberately NOT Checkout's flourish
//
// A buyer completes a purchase once, so that screen can afford ~1.5s of
// monogram-trace-then-checkmark. A courier completes 20–40 deliveries a
// shift — standing up, in daylight, holding a parcel — so a multi-second
// animation between every one of those would tax the exact person whose time
// this app exists to save.
//
// The rule here is **confirm the many, celebrate the few**: an instant
// confirmation always (the button's haptic plus a static mark, nothing to sit
// through), and the app's celebration asset spent only on the rare beats.
// [celebrate] is the caller's decision, because the caller is the only place
// that knows how many deliveries came before this one.
//
// ## Everything on this screen is checkable
//
// The fee, the day's total and the day's count. What it replaced — a SnackBar
// reading "₱X added to your earnings" that the next completion wiped — made a
// claim the courier could not verify without switching tabs, which is how a
// confirmation becomes something you learn to ignore.
//
// ## Two outcomes, one layout
//
// `returnToSender` closes a job exactly like `delivered` does, so a return
// gets the same structure with completely different words: it never
// celebrates, and it never claims a fee was earned. Before this, a failed
// delivery inherited the success copy — and would have inherited the
// confetti.

import 'package:flutter/material.dart';

import '../../../theme/app_theme.dart';
import '../../../widgets/editorial_shapes.dart';
import '../../../widgets/success_flourish.dart';

class DeliveryCompletionView extends StatelessWidget {
  const DeliveryCompletionView({
    super.key,
    required this.fee,
    required this.todayTotal,
    required this.todayCount,
    required this.returned,
    required this.celebrate,
    required this.onDone,
  });

  /// What this job just paid.
  final double fee;

  /// The day so far, so the claim above it can be checked in place.
  final double todayTotal;
  final int todayCount;

  /// A return rather than a delivery.
  final bool returned;

  /// Whether this completion is one of the rare beats that earns the
  /// animation.
  final bool celebrate;

  /// Leaves this screen — "Back to my jobs".
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);

    return Scaffold(
      backgroundColor: Colors.transparent,
      // No back arrow: "Back to my jobs" is the only way out, so the
      // confirmation is read rather than swiped past — and the system back
      // still pops, so nobody is trapped.
      appBar: AppBar(automaticallyImplyLeading: false),
      body: SafeArea(
        // Scrollable rather than a fixed Column. Measured: as a plain centred
        // Column this screen overflows by 204px on a 360x300 viewport at the
        // app's maximum 1.3x text scale (landscape on a short phone) — and
        // it's the longest fixed-height stack in the courier flow, so it's
        // the one screen that would. Centring still works: the ConstrainedBox
        // gives the Column a minimum of the viewport height to centre inside.
        // See test/delivery_completion_view_test.dart, which asserts this.
        child: LayoutBuilder(
          builder: (context, constraints) => SingleChildScrollView(
            child: ConstrainedBox(
              constraints: BoxConstraints(minHeight: constraints.maxHeight),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Center(
                      child: celebrate
                          ? const SuccessFlourish(size: 96)
                          : _ConfirmationMark(
                              hue: returned ? AppColors.danger : AppColors.tertiary,
                              ink: returned ? AppColors.textOnDark : AppColors.tintInk,
                              icon: returned ? Icons.undo_rounded : Icons.check_rounded,
                            ),
                    ),
                    const SizedBox(height: 22),
                    Text(
                      returned ? 'Returned to seller' : 'Delivered',
                      textAlign: TextAlign.center,
                      style: AppType.sectionDisplay,
                    ),
                    const SizedBox(height: 10),
                    Text(
                      returned
                          ? 'This parcel is on its way back to the seller.'
                          : '₱${fee.toStringAsFixed(2)} added to your earnings',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.bodyMedium
                          ?.copyWith(color: AppColors.textSecondary),
                    ),
                    // A return is paid, but not the full fee (see
                    // kReturnFeeShare) — and until this line existed the screen
                    // said nothing about money at all on that path, which was
                    // fine while a return paid the same as a delivery and is
                    // not fine now. Same rule as the delivery branch above it:
                    // state the number rather than a feeling about it, so the
                    // courier can reconcile it against the earnings tab.
                    if (returned) ...[
                      const SizedBox(height: 6),
                      Text(
                        '₱${fee.toStringAsFixed(2)} trip fee credited for this one',
                        textAlign: TextAlign.center,
                        style: theme.textTheme.bodySmall
                            ?.copyWith(color: AppColors.textSecondary),
                      ),
                    ],
                    const SizedBox(height: 20),
                    Center(
                      child: Text(
                        'TODAY  ₱${todayTotal.toStringAsFixed(0)}  ·  $todayCount closed',
                        style: AppType.eyebrow.copyWith(color: AppColors.accent),
                      ),
                    ),
                    const SizedBox(height: 34),
                    ElevatedButton(
                      onPressed: onDone,
                      child: const Text('Back to my jobs'),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The instant counterpart to [SuccessFlourish]: same 96px footprint (so the
/// layout doesn't jump between the two paths), a static mark on a tinted
/// arch, no animation and no haptic of its own — the completing button
/// already fired one. This is what most completions get, on purpose.
class _ConfirmationMark extends StatelessWidget {
  const _ConfirmationMark({required this.hue, required this.ink, required this.icon});

  /// The hue leaned onto the arch — success sage for a delivery, danger for
  /// a return.
  final Color hue;

  /// Ink for the mark, passed alongside [hue] for the same reason the
  /// palette carries textOnDark *and* textOnLight: the two fills take
  /// opposite inks. (Dark ink on a red-tinged fill is ~1.6:1 — that's how
  /// the sage-avatar bug happened.)
  final Color ink;

  final IconData icon;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return SizedBox(
      width: 96,
      height: 96,
      child: Arch(
        footRadius: 11,
        child: ColoredBox(
          // Same arch treatment as EmptyState, so the app's small
          // "confirmed" shape and its small "nothing here" shape are
          // visibly the same family.
          color: AppColors.hueOnSurface(hue),
          child: Center(
            child: Icon(icon, size: 42, color: ink),
          ),
        ),
      ),
    );
  }
}
