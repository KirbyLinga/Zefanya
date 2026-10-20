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
// Two beats, one lockup (Claude landing restructure, round 2):
//
//   Beat 1 (brand): centred Z mark, wordmark and tagline, large. No
//   rail, no BEGIN. A "tap to continue" hint fades in at ~1.2s. Any
//   tap anywhere advances to Beat 2.
//
//   Beat 1 → Beat 2 is ONE continuous motion, not a crossfade: the
//   same brand lockup scales down and moves up on a single 700ms
//   controller (Transform only), and the rail + BEGIN reveal after
//   it settles. Beat 2's brand is compact — Z mark plus wordmark,
//   no tagline — and the freed height goes to larger rail cards.
//
//   Reached by tap, or automatically at ~3s. The rail's 6s
//   auto-advance timer starts only on entering Beat 2. Both timers
//   (the 3s auto-transition and the rail's 6s loop) are cancelled on
//   dispose and whenever the screen goes off-stage.
//
//   Reduced motion: straight to the Beat 2 end state, no timers.
//   BEGIN is still the only thing that calls onDone.
//
//   Debug flag: a tiny "BEAT 1 / BEAT 2" tag bottom-left (debug
//   builds only) so device runs show which beat is active — and
//   whether reduced motion forced Beat 2.
//
// The background — LandingBackdrop, a full-canvas animated loop —
// runs continuously behind all of it on its own clock (see the old
// file comment this replaced for why it isn't tied to the beats).
// -------------------------------------------------------------------
//
// The rail artwork fixes ride along here (Claude's shop-card pass):
// tinted tiles with a category icon instead of the generic bag, rail
// side gutters at the shared 20dp, clipped card edges fixed, more
// space around the title and dots. See landing_preview.dart.

import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../theme/app_theme.dart';
import '../widgets/landing_backdrop.dart';
import '../widgets/motion/cloud_transition.dart';
import '../widgets/zefanya_mark.dart';
import 'landing_preview_rail.dart';

class LandingScreen extends StatefulWidget {
  const LandingScreen({
    super.key,
    required this.onDone,
    this.reduceMotion = false,
    this.isActive = true,
  });

  /// Called exactly once — when Begin is tapped. Nothing else calls this.
  final VoidCallback onDone;

  /// Whether to skip straight to the settled end state. Passed in
  /// rather than read from MediaQuery here, so this widget doesn't need
  /// its own didChangeDependencies dance — AuthGate already resolves it
  /// once, in the same place it resolves everything else about how this
  /// process should start.
  final bool reduceMotion;

  /// False while the screen is off-stage: both timers stop (the 3s
  /// auto-transition and the rail's 6s loop), restarting on return.
  final bool isActive;

  @override
  State<LandingScreen> createState() => _LandingScreenState();
}

class _LandingScreenState extends State<LandingScreen>
    with TickerProviderStateMixin {
  static const _entranceCycle = Duration(milliseconds: 1900);

  /// Beat 2 delay: tap anywhere, or this timer, advances the show.
  static const _beatDelay = Duration(milliseconds: 3000);

  /// Hint delay: "tap to continue" fades in ~1.2s into Beat 1.
  static const _hintDelay = Duration(milliseconds: 1200);

  /// Minimum Beat 1 hold so it cannot be missed on device.
  static const _minBeat1Hold = Duration(milliseconds: 1800);

  /// Reduced motion static Beat 1 display time before advancing.
  static const _reducedBeat1Hold = Duration(milliseconds: 1200);

  /// Beat 1 → Beat 2 hand-off: one 700ms motion, lockup scale +
  /// travel. Rail + BEGIN reveal after it settles.
  static const _handoffLength = Duration(milliseconds: 700);

  /// Lockup geometry, both ends of the hand-off. Beat 1: centred,
  /// mark 128 / wordmark 72. Beat 2: compact, mark 64 / wordmark
  /// 44, no tagline. One widget, two parameter sets — the hand-off
  /// lerps every number, so the lockup never unmounts.
  static const _markBeat1 = 128.0;
  static const _markBeat2 = 64.0;
  static const _wordBeat1 = 72.0;
  static const _wordBeat2 = 44.0;

  /// Beat 2 lockup travel: up from centre to its resting slot.
  static const _lockupTravelDp = 120.0;

  late final AnimationController _entrance =
      AnimationController(vsync: this, duration: _entranceCycle);

  /// 0 = Beat 1 resting, 1 = Beat 2 resting. Drives lockup scale,
  /// travel, tagline fade, and gates the rail + BEGIN reveal.
  late final AnimationController _handoff =
      AnimationController(vsync: this, duration: _handoffLength);

  /// Which beat is showing. Set alongside the hand-off so the rail
  /// (and its 6s timer) mount exactly when the lockup starts moving
  /// — the reveal animations inside Beat 2 sequence against the
  /// hand-off's own progress.
  bool _beat2 = false;

  /// Hint visibility: set by the hint timer, reset on dispose path.
  bool _hintVisible = false;

  bool _minHoldElapsed = false;
  bool _tappedEarly = false;

  Timer? _beatTimer;
  Timer? _hintTimer;
  Timer? _minHoldTimer;
  Timer? _reducedTimer;

  // Guards against a double-tap on Begin firing onDone twice before the
  // parent's setState takes effect. Also disables the button visually
  // on first tap, which reads as "got it" rather than nothing happening.
  bool _begun = false;

  @override
  void initState() {
    super.initState();
    if (widget.reduceMotion) {
      _entrance.value = 1.0;
      _handoff.value = 0.0;
      _beat2 = false;
      _hintVisible = true;
      _reducedTimer = Timer(_reducedBeat1Hold, () {
        if (mounted) {
          setState(() {
            _beat2 = true;
            _handoff.value = 1.0;
          });
        }
      });
    } else {
      _entrance.forward();
      _hintTimer = Timer(_hintDelay, () {
        if (mounted) setState(() => _hintVisible = true);
      });
      _minHoldTimer = Timer(_minBeat1Hold, () {
        _minHoldElapsed = true;
        if (_tappedEarly && mounted) {
          _advanceToBeat2();
        }
      });
      _maybeStartBeatTimer();
    }
  }

  @override
  void didUpdateWidget(covariant LandingScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.isActive != widget.isActive) _maybeStartBeatTimer();
  }

  /// Starts the 3s auto-transition only while Beat 1 is showing, the
  /// screen is on-stage, and motion is allowed. Any other state
  /// cancels it — never a stray callback into a hidden tree.
  void _maybeStartBeatTimer() {
    _beatTimer?.cancel();
    _beatTimer = null;
    if (_beat2 || !widget.isActive || widget.reduceMotion) return;
    _beatTimer = Timer(_beatDelay, () {
      if (mounted) _advanceToBeat2();
    });
  }

  void _advanceToBeat2() {
    if (_beat2 || !mounted) return;
    _beatTimer?.cancel();
    _beatTimer = null;
    _minHoldTimer?.cancel();
    _minHoldTimer = null;
    _reducedTimer?.cancel();
    _reducedTimer = null;
    setState(() => _beat2 = true);
    if (widget.reduceMotion) {
      _handoff.value = 1.0;
    } else {
      _handoff.forward(from: 0);
    }
  }

  @override
  void dispose() {
    _beatTimer?.cancel();
    _hintTimer?.cancel();
    _minHoldTimer?.cancel();
    _reducedTimer?.cancel();
    _entrance.dispose();
    _handoff.dispose();
    super.dispose();
  }

  /// Any tap in Beat 1 advances the show: the hand-off runs, and the
  /// beat flag flips with it so the rail mounts (and its 6s timer
  /// starts) exactly as the lockup starts moving. Minimum 1.8s hold is enforced.
  void _continue() {
    if (_beat2) return;
    if (widget.reduceMotion) {
      _advanceToBeat2();
      return;
    }
    if (!_minHoldElapsed) {
      _tappedEarly = true;
      return;
    }
    _advanceToBeat2();
  }

  void _begin() {
    if (_begun) return;
    setState(() => _begun = true);
    CloudTransition.run(context, swap: widget.onDone);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.transparent,
      body: Stack(
        fit: StackFit.expand,
        children: [
          // Runs entirely on its own clock — see the file-level comment
          // above for why this isn't tied to the beats.
          LandingBackdrop(reduceMotion: widget.reduceMotion),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 40),
              child: AnimatedBuilder(
                animation: Listenable.merge([_entrance, _handoff]),
                builder: (context, _) {
                  final t = _entrance.value;
                  // The hand-off lerps 0 → 1; before it starts the
                  // lockup rests at Beat 1 geometry.
                  final h = Curves.easeInOutCubic
                      .transform(_handoff.value.clamp(0.0, 1.0));
                  return _beat2
                      ? _Beat2Content(
                          handoff: h,
                          begun: _begun,
                          onBegin: _begin,
                          reduceMotion: widget.reduceMotion,
                        )
                      : _Beat1(
                          t: t,
                          hintVisible: _hintVisible,
                          onTap: _continue,
                        );
                },
              ),
            ),
          ),
          // Debug flag (debug builds only): which beat is showing, so
          // a device run answers "are we in Beat 2 because reduced
          // motion forced it?" at a glance.
          if (kDebugMode)
            Positioned(
              left: 8,
              bottom: 8,
              child: Container(
                padding: const EdgeInsets.symmetric(
                    horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: Colors.black.withValues(alpha: 0.55),
                  borderRadius: BorderRadius.circular(4),
                ),
                child: Text(
                  _beat2 ? 'BEAT 2' : 'BEAT 1',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 1.0,
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// Beat 1 (brand): centred Z mark, wordmark and tagline, larger
/// than the old arrival. No rail, no BEGIN. Tapping anywhere
/// advances (the GestureDetector covers the column); the hint fades
/// in ~1.2s after mount via [hintVisible].
class _Beat1 extends StatelessWidget {
  const _Beat1({
    required this.t,
    required this.hintVisible,
    required this.onTap,
  });

  final double t;
  final bool hintVisible;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: Column(
        children: [
          const Spacer(flex: 3),
          ZefanyaMarkTrace(
            progress: _traceSlice(t),
            size: 128,
            strokeWidth: 4,
            color: AppColors.textPrimary,
          ),
          const SizedBox(height: 32),
          _Fade(
            t: t,
            from: 0.38,
            to: 0.78,
            child: Text(
              'Zefanya',
              style: AppType.masthead.copyWith(fontSize: 72),
            ),
          ),
          const SizedBox(height: 16),
          _Fade(
            t: t,
            from: 0.55,
            to: 0.9,
            child: Text('BUY. SELL. DELIVER.', style: AppType.eyebrow),
          ),
          const Spacer(flex: 3),
          AnimatedOpacity(
            opacity: hintVisible ? 0.7 : 0.0,
            duration: const Duration(milliseconds: 400),
            child: Text(
              'Tap to continue',
              style: AppType.eyebrow.copyWith(
                color: AppColors.textPrimary,
              ),
            ),
          ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }
}

/// Beat 2 content: the continuing lockup plus the rail and BEGIN.
///
/// This is NOT a second brand widget cross-fading against Beat 1.
/// The hand-off value [handoff] (0 → 1 on one 700ms controller)
/// lerps the SAME lockup's size and travel: mark 128 → 64, wordmark
/// 72 → 44, tagline fading out, whole block rising 120dp. Because
/// the numbers interpolate, the lockup never unmounts — one
/// continuous motion. Rail fades in at h > 0.45, BEGIN at h > 0.7,
/// each with the 8dp rise; both gated so nothing is tappable (or
/// timing) before the lockup settles.
///
/// Beat 2's brand is compact — Z mark plus wordmark, no tagline —
/// and the freed height goes to the rail cards (360px, up from 300).
/// Reduced motion: hand-off jumps to 1, so this builds directly at
/// the end state with the rail's own timer also stilled (the rail
/// gets reduceMotion too).
class _Beat2Content extends StatelessWidget {
  const _Beat2Content({
    required this.handoff,
    required this.begun,
    required this.onBegin,
    required this.reduceMotion,
  });

  /// Eased hand-off progress, 0 (Beat 1 resting) → 1 (Beat 2 resting).
  final double handoff;
  final bool begun;
  final VoidCallback onBegin;
  final bool reduceMotion;

  /// Lockup lerp helpers — every number below interpolates Beat 1 →
  /// Beat 2 geometry against [handoff].
  double _lerp(double a, double b) => a + (b - a) * handoff;

  @override
  Widget build(BuildContext context) {
    final markSize = _lerp(
        _LandingScreenState._markBeat1, _LandingScreenState._markBeat2);
    final wordSize = _lerp(
        _LandingScreenState._wordBeat1, _LandingScreenState._wordBeat2);
    final travel =
        _LandingScreenState._lockupTravelDp * handoff;
    // Tagline dissolves across the first half of the hand-off.
    final tagline = (1 - handoff * 2).clamp(0.0, 1.0);
    // Rail + BEGIN reveal after the lockup settles.
    final rail = ((handoff - 0.45) / 0.3).clamp(0.0, 1.0);
    final begin = ((handoff - 0.7) / 0.3).clamp(0.0, 1.0);
    final railV = Curves.easeOutCubic.transform(rail);
    final beginV = Curves.easeOutCubic.transform(begin);

    return Column(
      children: [
        Spacer(flex: (_lerp(30.0, 10.0)).round()),
        // The continuing lockup: same mark + wordmark widgets as
        // Beat 1, lerped smaller and travelling up. A single
        // Transform (translate + scale about the top centre) — one
        // motion, not two widgets cross-fading.
        Transform.translate(
          offset: Offset(0, -travel),
          child: Transform.scale(
            scale: _lerp(1.0, markSize / _LandingScreenState._markBeat1),
            alignment: Alignment.topCenter,
            child: Column(
              children: [
                ZefanyaMarkTrace(
                  progress: 1,
                  size: markSize,
                  strokeWidth: 4,
                  color: AppColors.textPrimary,
                ),
                SizedBox(height: _lerp(32.0, 14.0)),
                Text(
                  'Zefanya',
                  style: AppType.masthead.copyWith(fontSize: wordSize),
                ),
                // Tagline slot collapses as it fades: fixed-height
                // box lerped to zero, so the lockup's footprint
                // shrinks smoothly instead of jumping.
                SizedBox(
                  height: _lerp(30.0, 0.0),
                  child: Opacity(
                    opacity: tagline,
                    child: Text('BUY. SELL. DELIVER.',
                        style: AppType.eyebrow),
                  ),
                ),
              ],
            ),
          ),
        ),
        SizedBox(height: _lerp(0.0, 14.0)),
        // Rail slot grows with the reveal (0 → full across its
        // slice): the Column's total height stays within budget at
        // EVERY hand-off value, so no mid-transition stripe — the
        // early overflow the first version of this motion showed on
        // a 740px screen. PageView clips its interior while short;
        // spacers absorb the growth smoothly, no jumps.
        // Full height = 360 rail + 10 gap + 14 dots row: exact,
        // because the dots row is a fixed 14 (see the rail).
        SizedBox(
          height: 384 * railV,
          child: Opacity(
            opacity: railV,
            child: Transform.translate(
              offset: Offset(0, 8 * (1 - railV)),
              child: LandingPreviewRail(
                reduceMotion: reduceMotion,
                railHeight: 360,
              ),
            ),
          ),
        ),
        Spacer(flex: (_lerp(40.0, 20.0)).round()),
        // BEGIN slot grows the same way (button + 8px gap ≈ 57px).
        // ClipRect: the button paints full-size inside a short box
        // mid-reveal and would stripe without it. Gated until the
        // reveal completes, so a mid-motion tap can't fire onDone.
        SizedBox(
          height: 57 * beginV,
          child: ClipRect(
            child: Opacity(
              opacity: beginV,
              child: SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: begun || beginV < 1 ? null : onBegin,
                  style: ElevatedButton.styleFrom(
                    padding: const EdgeInsets.symmetric(vertical: 18),
                    shape: const StadiumBorder(),
                    textStyle: AppType.eyebrow.copyWith(
                      fontSize: 13,
                      letterSpacing: 1.2,
                      color: AppColors.textOnDark,
                    ),
                  ),
                  child: const Text('BEGIN'),
                ),
              ),
            ),
          ),
        ),
        const SizedBox(height: 8),
      ],
    );
  }
}

/// The Z traces over the first 55% of the cycle, then holds.
double _traceSlice(double t) =>
    Curves.easeInOutCubic.transform((t / 0.55).clamp(0.0, 1.0));

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
