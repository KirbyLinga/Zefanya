// lib/screens/landing_preview_rail.dart
//
// LandingPreviewRail pages the three miniature renders (shop, track,
// save) as a short horizontal sequence. The page artwork lives in
// landing_preview.dart — this file owns the clock: a slow
// auto-advance Timer, touch-pause, entrance, and dots.
//
//   * Auto-advance every 6s while mounted, visible and untouched.
//     A finger down pauses (resumes one interval after release);
//     a swipe resets the interval so the page doesn't jump away
//     mid-read.
//   * Entrance: each page fades + rises 8dp, staggered 40ms, once.
//     Transforms + opacity only; reduced motion shows the end state
//     with no auto-advance.
//   * The timer is cancelled in dispose AND whenever [isActive]
//     goes false (screen off-stage) — restarted when it returns.
//     See the widget test, which proves the page stops turning.
//
// No network, no mode branch: artwork is static sample data in both
// mock and live builds.

import 'dart:async';

import 'package:flutter/material.dart';

import 'landing_preview.dart';

/// Auto-advance interval — slow enough to read each miniature.
const Duration kPreviewAdvanceInterval = Duration(seconds: 6);

/// Entrance stagger between pages — 40ms apart per the brief.
const Duration kPreviewStaggerStep = Duration(milliseconds: 40);

/// Entrance travel per page — 8dp rise per the brief.
const double kPreviewRiseDp = 8.0;

/// The preview rail: three miniature pages, auto-advancing.
class LandingPreviewRail extends StatefulWidget {
  const LandingPreviewRail({
    super.key,
    this.isActive = true,
    this.reduceMotion = false,
    this.railHeight = 300,
  });

  /// False while the screen is off-stage: the timer stops (and never
  /// fires a setState into a hidden tree), restarting on return.
  final bool isActive;

  /// End state immediately, no auto-advance.
  final bool reduceMotion;

  /// Page box height. Beat 2 grants 360px (up from the standalone
  /// 300) now the compact lockup frees the budget — larger cards.
  final double railHeight;

  @override
  State<LandingPreviewRail> createState() => _LandingPreviewRailState();
}

class _LandingPreviewRailState extends State<LandingPreviewRail> {
  late final PageController _pages = PageController();
  Timer? _advanceTimer;
  bool _touching = false;
  double _page = 0;

  static const _eyebrows = ['SHOP', 'TRACK ORDERS', 'COURIER & SAVINGS'];
  static const _titles = [
    'Shelves worth\nbrowsing',
    'Every parcel\nin sight',
    'Earn and\nsave more'
  ];

  @override
  void initState() {
    super.initState();
    _maybeStartTimer();
  }

  @override
  void didUpdateWidget(covariant LandingPreviewRail oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.isActive != widget.isActive ||
        oldWidget.reduceMotion != widget.reduceMotion) {
      _maybeStartTimer();
    }
  }

  /// Starts the timer only when it should run; cancels otherwise, so
  /// there is never a stray callback after dispose, off-stage, a
  /// touch, or under reduced motion.
  void _maybeStartTimer() {
    _advanceTimer?.cancel();
    _advanceTimer = null;
    if (!widget.isActive || widget.reduceMotion || _touching) return;
    _advanceTimer =
        Timer.periodic(kPreviewAdvanceInterval, (_) => _advance());
  }

  void _advance() {
    if (!mounted || !_pages.hasClients) return;
    final next = (_page.round() + 1) % landingPreviewCount;
    _pages.animateToPage(
      next,
      duration: const Duration(milliseconds: 450),
      curve: Curves.easeOutCubic,
    );
  }

  @override
  void dispose() {
    _advanceTimer?.cancel();
    _pages.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox(
          height: widget.railHeight,
          child: Listener(
            onPointerDown: (_) {
              _touching = true;
              _maybeStartTimer();
            },
            onPointerUp: (_) {
              _touching = false;
              _maybeStartTimer();
            },
            onPointerCancel: (_) {
              _touching = false;
              _maybeStartTimer();
            },
            child: PageView.builder(
              controller: _pages,
              itemCount: landingPreviewCount,
              onPageChanged: (i) {
                setState(() => _page = i.toDouble());
                // A swipe resets the interval so the new page gets
                // its full reading time instead of jumping away.
                _maybeStartTimer();
              },
              itemBuilder: (context, i) => _PreviewEntrance(
                index: i,
                reduceMotion: widget.reduceMotion,
                child: LandingPreviewPage(
                  eyebrow: _eyebrows[i],
                  title: _titles[i],
                  child: _previewFor(i),
                ),
              ),
            ),
          ),
        ),
        const SizedBox(height: 10),
        // Fixed dots row height (not intrinsic): the screen reserves
        // 360 + 10 + 14 for rail + gap + dots, and that math only
        // holds if the dots row is a known number.
        SizedBox(
          height: 14,
          child: LandingDots(count: landingPreviewCount, index: _page),
        ),
      ],
    );
  }

  Widget _previewFor(int i) {
    switch (i) {
      case 0:
        return const ShopPreview();
      case 1:
        return const TrackPreview();
      default:
        return const SavePreview();
    }
  }
}

/// One-shot entrance per page: opacity + 8dp rise, starting
/// [index] * 40ms after mount. Reduced motion jumps to the end
/// state. At most two concurrent animations per page (this fade +
/// the rail's own page slide); the dots animate separately.
class _PreviewEntrance extends StatefulWidget {
  const _PreviewEntrance({
    required this.index,
    required this.reduceMotion,
    required this.child,
  });

  final int index;
  final bool reduceMotion;
  final Widget child;

  @override
  State<_PreviewEntrance> createState() => _PreviewEntranceState();
}

class _PreviewEntranceState extends State<_PreviewEntrance>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _fade;
  late final Animation<double> _rise;
  bool _started = false;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 240),
    );
    final curved =
        CurvedAnimation(parent: _controller, curve: Curves.easeOutCubic);
    _fade = curved;
    _rise = Tween<double>(begin: kPreviewRiseDp, end: 0).animate(curved);
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;
    if (widget.reduceMotion) {
      _controller.value = 1;
      return;
    }
    Future.delayed(kPreviewStaggerStep * widget.index, () {
      if (mounted) _controller.forward();
    });
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _fade,
      child: AnimatedBuilder(
        animation: _rise,
        builder: (context, child) => Transform.translate(
          offset: Offset(0, _rise.value),
          child: child,
        ),
        child: widget.child,
      ),
    );
  }
}
