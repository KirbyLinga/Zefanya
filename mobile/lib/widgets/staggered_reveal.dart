// lib/widgets/staggered_reveal.dart
//
// Wrap a grid/list item in this to have it fade + slide up into place
// on first build, with a small delay per index — so a screen's content
// arrives rather than just appearing all at once. Purely presentational,
// runs once per widget lifetime (doesn't replay on rebuild/scroll-back
// into view), and costs nothing beyond a single AnimationController per
// item — safe for grids in the tens, not intended for very long lists.
//
// Reduced motion: jumps straight to the settled state. The start of the
// animation is 6% down and fully transparent, so "just don't run the
// controller" would leave every wrapped item permanently invisible —
// the controller has to be forced to 1, not left at 0.

import 'package:flutter/material.dart';

import '../utils/motion.dart';

class StaggeredReveal extends StatefulWidget {
  const StaggeredReveal({
    super.key,
    required this.index,
    required this.child,
    this.baseDelay = const Duration(milliseconds: 25),
    this.duration = const Duration(milliseconds: 280),
  });

  final int index;
  final Widget child;

  /// Delay between one item's start and the next — keep this small so a
  /// 12-item grid finishes settling in well under a second.
  final Duration baseDelay;
  final Duration duration;

  @override
  State<StaggeredReveal> createState() => _StaggeredRevealState();
}

class _StaggeredRevealState extends State<StaggeredReveal> with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _fade;
  late final Animation<Offset> _slide;

  bool _started = false;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(vsync: this, duration: widget.duration);
    final curved = CurvedAnimation(parent: _controller, curve: Curves.easeOutCubic);
    _fade = curved;
    _slide = Tween<Offset>(begin: const Offset(0, 0.06), end: Offset.zero).animate(curved);
  }

  // The start moved out of initState because MediaQuery isn't reachable
  // there. This runs before the first build either way, so the reduced
  // case never shows a frame of the pre-animation state.
  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;

    if (reduceMotion(context)) {
      _controller.value = 1;
      return;
    }

    final delay = widget.baseDelay * widget.index;
    Future.delayed(delay, () {
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
      child: SlideTransition(position: _slide, child: widget.child),
    );
  }
}
