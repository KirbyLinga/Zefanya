// lib/widgets/motion/fade_through.dart
//
// Fast (<=200ms) fade-through transition used for:
//   - Bottom tab switches in BuyerShell and CourierShell
//   - In-screen state changes (e.g. chips/filtering/results toggle)
//
// Motion rules:
//   - Quick, unobtrusive: default 180ms (<= 200ms ceiling).
//   - Reduced motion: immediate swap (0ms).
//   - Preserves underlying tab state: FadeThroughIndexedStack keeps children
//     mounted just like standard IndexedStack.

import 'package:flutter/material.dart';
import '../../utils/motion.dart';

const Duration kFadeThroughDuration = Duration(milliseconds: 180);

/// An IndexedStack that smoothly fades between children on index switch
/// without unmounting any child (preserving scroll positions and state).
class FadeThroughIndexedStack extends StatefulWidget {
  const FadeThroughIndexedStack({
    super.key,
    required this.index,
    required this.children,
    this.duration = kFadeThroughDuration,
  });

  final int index;
  final List<Widget> children;
  final Duration duration;

  @override
  State<FadeThroughIndexedStack> createState() => _FadeThroughIndexedStackState();
}

class _FadeThroughIndexedStackState extends State<FadeThroughIndexedStack>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _fade;
  late int _currentIndex;

  @override
  void initState() {
    super.initState();
    _currentIndex = widget.index;
    _controller = AnimationController(
      vsync: this,
      duration: widget.duration,
      value: 1.0,
    );
    _fade = CurvedAnimation(
      parent: _controller,
      curve: Curves.easeOutCubic,
    );
  }

  @override
  void didUpdateWidget(covariant FadeThroughIndexedStack oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.index != widget.index) {
      if (reduceMotion(context)) {
        _currentIndex = widget.index;
        _controller.value = 1.0;
      } else {
        // Quick fade-out -> swap index -> fade-in
        final halfDuration = Duration(
          milliseconds: (widget.duration.inMilliseconds / 2).round(),
        );
        _controller.duration = halfDuration;
        _controller.reverse().then((_) {
          if (!mounted) return;
          setState(() {
            _currentIndex = widget.index;
          });
          _controller.forward();
        });
      }
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (reduceMotion(context)) {
      return IndexedStack(
        index: widget.index,
        children: widget.children,
      );
    }

    return FadeTransition(
      opacity: _fade,
      child: IndexedStack(
        index: _currentIndex,
        children: widget.children,
      ),
    );
  }
}

/// A lightweight animated switcher that crossfades a single child.
class FadeThroughSwitch extends StatelessWidget {
  const FadeThroughSwitch({
    super.key,
    required this.child,
    this.duration = kFadeThroughDuration,
  });

  final Widget child;
  final Duration duration;

  @override
  Widget build(BuildContext context) {
    if (reduceMotion(context)) {
      return child;
    }

    return AnimatedSwitcher(
      duration: duration,
      switchInCurve: Curves.easeOutCubic,
      switchOutCurve: Curves.easeInCubic,
      transitionBuilder: (widgetChild, animation) {
        return FadeTransition(
          opacity: animation,
          child: widgetChild,
        );
      },
      child: child,
    );
  }
}
