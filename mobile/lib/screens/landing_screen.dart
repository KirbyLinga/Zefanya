// lib/screens/landing_screen.dart
//
// The brand arrival, shown once per app process — and only when the
// process starts with no active session (see AuthGate in main.dart for
// that gating; this file doesn't know or care about AccountStatus).
//
// It is NOT a route. Read the comment on AuthGate before changing that:
// four separate files call `Navigator.popUntil((route) => route.isFirst)`
// on the assumption that the first route is whatever AuthGate currently
// shows. Giving this screen its own route makes IT the first route, and
// every one of those four calls starts unwinding to the intro instead of
// back to Login/Shell.
//
// -------------------------------------------------------------------
// Two independent things are happening on this screen, on purpose:
//
//   1. The foreground entrance — the Z tracing on, the wordmark and
//      tagline fading in, the Begin button arriving last. One-shot,
//      driven by _entrance, plays once and holds. No auto-dismiss:
//      nothing calls widget.onDone except the button. A screen that
//      could dismiss itself out from under you isn't offering a
//      choice, it's offering a suggestion with a deadline — which is
//      wrong the moment there's an action on the screen.
//
//   2. The background — LandingBackdrop, a full-canvas animated loop
//      that runs continuously behind all of it, entirely on its own
//      clock. It doesn't wait for the entrance and doesn't ease in
//      with it; it's just running, the whole time this screen is up.
//      This is the one screen in the app where that's appropriate —
//      nothing here is a task competing with it, unlike a login form.
//      See landing_backdrop.dart for the shape/orbit details.
//
// These two used to share one clock (an early version ramped the
// background's motion in against the entrance's own progress). That
// undersold the background — it never reached full presence until the
// entrance was basically over, which read as "barely animated" rather
// than as an actual moving background. Decoupling them fixes that: the
// backdrop is doing its own full thing from the moment this screen
// mounts, independent of whatever the foreground is up to.
// -------------------------------------------------------------------
//
// Reduced motion applies to both halves independently: _entrance jumps
// straight to its end value (mark drawn, text and button visible, no
// animation played), and LandingBackdrop is told to never start its
// loop (see that file). Begin still requires a tap either way.

import 'package:flutter/material.dart';

import '../theme/app_theme.dart';
import '../widgets/landing_backdrop.dart';
import '../widgets/zefanya_mark.dart';

class LandingScreen extends StatefulWidget {
  const LandingScreen({
    super.key,
    required this.onDone,
    this.reduceMotion = false,
  });

  /// Called exactly once — when Begin is tapped. Nothing else calls this.
  final VoidCallback onDone;

  /// Whether to skip straight to the settled end state. Passed in
  /// rather than read from MediaQuery here, so this widget doesn't need
  /// its own didChangeDependencies dance — AuthGate already resolves it
  /// once, in the same place it resolves everything else about how this
  /// process should start.
  final bool reduceMotion;

  @override
  State<LandingScreen> createState() => _LandingScreenState();
}

class _LandingScreenState extends State<LandingScreen>
    with SingleTickerProviderStateMixin {
  static const _entranceCycle = Duration(milliseconds: 1900);

  late final AnimationController _entrance =
      AnimationController(vsync: this, duration: _entranceCycle);

  // Guards against a double-tap on Begin firing onDone twice before the
  // parent's setState takes effect. Also disables the button visually
  // on first tap, which reads as "got it" rather than nothing happening.
  bool _begun = false;

  @override
  void initState() {
    super.initState();
    if (widget.reduceMotion) {
      _entrance.value = 1;
    } else {
      _entrance.forward();
    }
  }

  @override
  void dispose() {
    _entrance.dispose();
    super.dispose();
  }

  void _begin() {
    if (_begun) return;
    setState(() => _begun = true);
    widget.onDone();
  }

  /// The Z traces over the first 55% of the cycle, then holds for the
  /// remainder — fully formed and sitting there, rather than the app
  /// cutting in behind it the instant it finishes drawing.
  double get _traceProgress =>
      Curves.easeInOutCubic.transform((_entrance.value / 0.55).clamp(0.0, 1.0));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.transparent,
      body: Stack(
        fit: StackFit.expand,
        children: [
          // Runs entirely on its own clock — see the file-level comment
          // above for why this isn't tied to _entrance.
          LandingBackdrop(reduceMotion: widget.reduceMotion),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 40),
              child: AnimatedBuilder(
                animation: _entrance,
                builder: (context, _) {
                  final t = _entrance.value;
                  return Column(
                    children: [
                      const Spacer(flex: 3),
                      ZefanyaMarkTrace(
                        progress: _traceProgress,
                        size: 104,
                        // Slightly finer than the 5 used at loader
                        // scale — the painter scales stroke weight with
                        // size, so 5 at 104px is proportionally correct
                        // but optically heavy for a mark this large.
                        strokeWidth: 4,
                        color: AppColors.primaryDark,
                      ),
                      const SizedBox(height: 28),
                      _Fade(
                        t: t,
                        from: 0.38,
                        to: 0.78,
                        child: Text(
                          'Zefanya',
                          // Hero scale — above the 54pt masthead, which
                          // is fine precisely because nothing else in
                          // the app is ever on this screen to compete
                          // with it. Same face, same tracking, so it's
                          // the same lockup at a different size, not a
                          // different treatment.
                          style: AppType.masthead.copyWith(fontSize: 64),
                        ),
                      ),
                      const SizedBox(height: 14),
                      _Fade(
                        t: t,
                        from: 0.55,
                        to: 0.9,
                        child: Text('BUY. SELL. DELIVER.', style: AppType.eyebrow),
                      ),
                      const Spacer(flex: 4),
                      // Comes in last and stays — the one element on
                      // this screen with a job to do, rather than
                      // something to look at. Wider padding and a pill
                      // shape so it reads as a considered action, not
                      // a default form submit.
                      _Fade(
                        t: t,
                        from: 0.68,
                        to: 0.96,
                        child: SizedBox(
                          width: double.infinity,
                          child: ElevatedButton(
                            onPressed: _begun ? null : _begin,
                            style: ElevatedButton.styleFrom(
                              padding: const EdgeInsets.symmetric(vertical: 18),
                              shape: const StadiumBorder(),
                              textStyle: AppType.eyebrow.copyWith(
                                fontSize: 13,
                                letterSpacing: 3.0,
                                color: AppColors.textOnDark,
                              ),
                            ),
                            child: const Text('BEGIN'),
                          ),
                        ),
                      ),
                      const SizedBox(height: 8),
                    ],
                  );
                },
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Fade + a few pixels of rise, over an explicit slice of the parent
/// clock. Not StaggeredReveal: that widget owns its own controller and
/// its own delay, which would put each element on a second timeline
/// running alongside the trace instead of sequenced against it.
class _Fade extends StatelessWidget {
  const _Fade({
    required this.t,
    required this.from,
    required this.to,
    required this.child,
  });

  final double t;
  final double from;
  final double to;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final raw = ((t - from) / (to - from)).clamp(0.0, 1.0);
    final v = Curves.easeOutCubic.transform(raw);
    return Opacity(
      opacity: v,
      child: Transform.translate(offset: Offset(0, 10 * (1 - v)), child: child),
    );
  }
}
