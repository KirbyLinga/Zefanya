// lib/widgets/cart_flight.dart
//
// The signature interaction (critique item #4): adding to the cart
// visibly throws the product across the screen and into the cart icon,
// which catches it with a pop.
//
// Why this and not another tasteful transition: page transitions,
// shimmer and haptics are what a well-built app does by default — the
// user doesn't consciously notice any of them. A physical object
// arcing across the screen is the one moment they'll actually see,
// and it's doing real work too (it tells you *where* the cart lives).
//
// How it's wired:
//   • Any cart icon wraps itself in [CartAnchor]. Anchors register
//     themselves in a list rather than through one shared GlobalKey —
//     Home stays mounted underneath pushed routes (IndexedStack), so a
//     single global key would be in the tree twice the moment Product
//     Details added its own cart button, which is a hard crash. The
//     most recently mounted anchor wins, i.e. the one on the route the
//     user is actually looking at.
//   • Call [CartFlight.send] from anywhere with the tapped widget's
//     key; it measures both ends and runs the arc in an Overlay, so it
//     flies *above* the current route and keeps going even if the
//     screen underneath rebuilds.
//   • If no cart icon is on screen, it silently no-ops rather than
//     throwing the item into the void.
//
// The flying thumbnail is passed in as a plain Widget so this file
// stays in widgets/ and doesn't need to know about products, images,
// or anything in screens/.
//
// Reduced motion: [send] no-ops before building the overlay at all —
// there's no partial or instant version of "an object arcs across the
// screen" that isn't itself the motion being turned off, unlike a
// fade or a shimmer, which have a legible "already finished" frame.
// The actual cart-state update happens in the caller before send() is
// ever invoked, so nothing about the add itself is skipped — only the
// flourish is. A single light haptic stands in as the one non-visual
// confirmation this widget can still offer; the catch-pop on
// [CartAnchor] is skipped too, since it only ever runs in response to
// a flight landing, and no flight is sent.

import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../utils/motion.dart';

class CartFlight {
  CartFlight._();

  /// Every mounted [CartAnchor], in mount order. Last one wins.
  static final List<_CartAnchorState> _anchors = <_CartAnchorState>[];

  static _CartAnchorState? _activeAnchor() {
    for (final anchor in _anchors.reversed) {
      if (!anchor.mounted) continue;
      final box = anchor.context.findRenderObject();
      if (box is RenderBox && box.attached && box.hasSize) return anchor;
    }
    return null;
  }

  /// Throws [thumbnail] from the widget identified by [originKey] into
  /// the cart icon. Safe to call when either end is missing.
  static void send(
    BuildContext context, {
    required GlobalKey originKey,
    required Widget thumbnail,
  }) {
    if (reduceMotion(context)) {
      // Still worth a tap of feedback -- the item was added, this is
      // just skipping the arc that shows it happening.
      HapticFeedback.lightImpact();
      return;
    }

    final anchor = _activeAnchor();
    if (anchor == null) return;

    final originBox = originKey.currentContext?.findRenderObject();
    final targetBox = anchor.context.findRenderObject();
    if (originBox is! RenderBox || targetBox is! RenderBox) return;
    if (!originBox.hasSize || !targetBox.hasSize) return;

    final overlay = Overlay.maybeOf(context);
    if (overlay == null) return;

    final from = originBox.localToGlobal(Offset.zero) & originBox.size;
    final to = targetBox.localToGlobal(Offset.zero) & targetBox.size;

    late final OverlayEntry entry;
    entry = OverlayEntry(
      builder: (_) => _FlightLayer(
        from: from,
        to: to,
        thumbnail: thumbnail,
        onDone: () {
          entry.remove();
          anchor.playCatch();
        },
      ),
    );

    overlay.insert(entry);
  }
}

class _FlightLayer extends StatefulWidget {
  const _FlightLayer({
    required this.from,
    required this.to,
    required this.thumbnail,
    required this.onDone,
  });

  final Rect from;
  final Rect to;
  final Widget thumbnail;
  final VoidCallback onDone;

  @override
  State<_FlightLayer> createState() => _FlightLayerState();
}

class _FlightLayerState extends State<_FlightLayer> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 620),
  );

  @override
  void initState() {
    super.initState();
    HapticFeedback.selectionClick();
    _controller.addStatusListener((status) {
      if (status != AnimationStatus.completed) return;
      HapticFeedback.mediumImpact();
      // Deferred: onDone removes the OverlayEntry, which disposes this
      // State — doing that synchronously from inside the controller's
      // own status callback tears the ticker down mid-notification.
      WidgetsBinding.instance.addPostFrameCallback((_) => widget.onDone());
    });
    _controller.forward();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  /// Quadratic bezier, with the control point lifted above both ends so
  /// the item arcs like something thrown rather than sliding on a rail.
  Offset _pointAt(double t) {
    final p0 = widget.from.center;
    final p2 = widget.to.center;
    final p1 = Offset(
      (p0.dx + p2.dx) / 2,
      math.min(p0.dy, p2.dy) - (widget.from.height * 0.8 + 90),
    );
    final u = 1 - t;
    return Offset(
      u * u * p0.dx + 2 * u * t * p1.dx + t * t * p2.dx,
      u * u * p0.dy + 2 * u * t * p1.dy + t * t * p2.dy,
    );
  }

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, child) {
          final t = Curves.easeInOutCubic.transform(_controller.value);
          final centre = _pointAt(t);

          // Shrinks toward the icon, and fades only at the very end so
          // it reads as arriving rather than dissolving on the way.
          final scale = 1 - 0.78 * t;
          final opacity = t < 0.85 ? 1.0 : 1 - (t - 0.85) / 0.15;
          final width = widget.from.width;
          final height = widget.from.height;

          return Stack(
            children: [
              Positioned(
                left: centre.dx - width / 2,
                top: centre.dy - height / 2,
                width: width,
                height: height,
                child: Opacity(
                  opacity: opacity.clamp(0.0, 1.0),
                  child: Transform.rotate(
                    angle: t * 0.5,
                    child: Transform.scale(scale: scale, child: child),
                  ),
                ),
              ),
            ],
          );
        },
        child: widget.thumbnail,
      ),
    );
  }
}

/// Wraps the cart icon. Registers the landing point and plays a catch
/// pop — an overshoot scale plus a soft ring — whenever a flight lands.
class CartAnchor extends StatefulWidget {
  const CartAnchor({super.key, required this.child});

  final Widget child;

  @override
  State<CartAnchor> createState() => _CartAnchorState();
}

class _CartAnchorState extends State<CartAnchor> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 460),
  );

  @override
  void initState() {
    super.initState();
    CartFlight._anchors.add(this);
  }

  /// Called by the flight when the item arrives. Never called under
  /// reduced motion, since no flight is ever sent -- see CartFlight.send.
  void playCatch() {
    if (!mounted) return;
    _controller.forward(from: 0);
  }

  @override
  void dispose() {
    CartFlight._anchors.remove(this);
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // The anchor measures its own element's render box, which is this
    // SizedBox — deliberately outside the Transform, so a flight that
    // starts mid-pop doesn't aim at a displaced target.
    return SizedBox(
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, child) {
          // 1 -> 1.32 -> 1, with a little elastic settle on the way back.
          final t = _controller.value;
          final scale = t == 0 ? 1.0 : 1 + 0.32 * math.sin(t * math.pi) * (1 - t * 0.35);
          return Transform.scale(scale: scale, child: child);
        },
        child: widget.child,
      ),
    );
  }
}
