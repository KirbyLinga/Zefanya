// lib/widgets/motion/ease_in_frame.dart
//
// The canonical component entrance for Zefanya.
//
// Motion rules:
//   - Transforms and opacity only (no layout animation, no blur/backdrop).
//   - 8dp rise, 40ms stagger per index, 320ms duration, Curves.easeOutCubic.
//   - Entrances play once per widget lifetime; never re-play on rebuilds.
//   - Reduced motion: immediately renders settled state with 0 timers.
//   - Safe cancellation: cancels any pending stagger timer on dispose.

import 'dart:async';
import 'package:flutter/material.dart';
import '../../utils/motion.dart';

/// Standard entrance parameters per the design brief.
const double kEaseInRiseDp = 8.0;
const Duration kEaseInStaggerStep = Duration(milliseconds: 40);
const Duration kEaseInDuration = Duration(milliseconds: 320);
const Curve kEaseInCurve = Curves.easeOutCubic;

class EaseInFrame extends StatefulWidget {
  const EaseInFrame({
    super.key,
    required this.index,
    required this.child,
    this.rise = kEaseInRiseDp,
    this.stagger = kEaseInStaggerStep,
    this.duration = kEaseInDuration,
    this.curve = kEaseInCurve,
    this.enabled = true,
  });

  /// Stagger sequence index (0-based). Stagger delay = index * stagger.
  final int index;

  /// Child widget entering the frame.
  final Widget child;

  /// Vertical entrance rise in logical pixels (default 8dp).
  final double rise;

  /// Delay step between items (default 40ms).
  final Duration stagger;

  /// Animation duration (default 320ms).
  final Duration duration;

  /// Easing curve (default Curves.easeOutCubic).
  final Curve curve;

  /// When false, skips animation and displays child immediately.
  final bool enabled;

  @override
  State<EaseInFrame> createState() => _EaseInFrameState();
}

class _EaseInFrameState extends State<EaseInFrame>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _opacity;
  late final Animation<double> _translateY;

  Timer? _delayTimer;
  bool _started = false;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: widget.duration,
    );
    final curved = CurvedAnimation(
      parent: _controller,
      curve: widget.curve,
    );
    _opacity = Tween<double>(begin: 0.0, end: 1.0).animate(curved);
    _translateY = Tween<double>(begin: widget.rise, end: 0.0).animate(curved);
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;

    if (!widget.enabled || reduceMotion(context)) {
      _controller.value = 1.0;
      return;
    }

    final delay = widget.stagger * widget.index;
    if (delay == Duration.zero) {
      _controller.forward();
    } else {
      _delayTimer = Timer(delay, () {
        if (mounted) _controller.forward();
      });
    }
  }

  @override
  void dispose() {
    _delayTimer?.cancel();
    _delayTimer = null;
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.enabled || reduceMotion(context) || _controller.value == 1.0) {
      return widget.child;
    }

    return AnimatedBuilder(
      animation: _controller,
      builder: (context, child) {
        return Opacity(
          opacity: _opacity.value,
          child: Transform.translate(
            offset: Offset(0, _translateY.value),
            child: child,
          ),
        );
      },
      child: widget.child,
    );
  }
}
