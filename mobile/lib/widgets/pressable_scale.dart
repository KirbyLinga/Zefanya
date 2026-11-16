// lib/widgets/pressable_scale.dart
//
// Tap target with a physical press: the child eases down to ~97% while a
// finger is on it and springs back on release, with a light haptic on a
// completed tap. Replaces the bare GestureDetector the editorial tiles
// used, which acknowledged a tap only by navigating away — no feedback at
// all during the press itself.
//
// It also gives those tiles a real accessibility identity. A bare
// GestureDetector around a Stack of Text widgets reads to a screen reader
// as a pile of unrelated fragments, with no hint that any of it is
// tappable. Pass [semanticLabel] to announce the whole tile as one button
// ("Linen Blend Wrap Dress, 1,250 pesos") instead.
//
// Reduced motion: the tap and haptic still work, the scale just never
// changes. (Press feedback has no "end state" to jump to — the resting
// scale IS the end state.)

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../utils/motion.dart';

class PressableScale extends StatefulWidget {
  const PressableScale({
    super.key,
    required this.onTap,
    required this.child,
    this.semanticLabel,
    this.pressedScale = 0.97,
    this.haptic = true,
    this.behavior = HitTestBehavior.opaque,
  });

  final VoidCallback onTap;
  final Widget child;

  /// Spoken as the tile's single label. When null, the child's own text is
  /// read instead (fine for a tile that is already just a caption).
  final String? semanticLabel;

  final double pressedScale;
  final bool haptic;
  final HitTestBehavior behavior;

  @override
  State<PressableScale> createState() => _PressableScaleState();
}

class _PressableScaleState extends State<PressableScale> {
  bool _pressed = false;

  void _setPressed(bool value) {
    if (_pressed != value) setState(() => _pressed = value);
  }

  void _handleTap() {
    if (widget.haptic) HapticFeedback.selectionClick();
    widget.onTap();
  }

  @override
  Widget build(BuildContext context) {
    final animate = !reduceMotion(context);

    return Semantics(
      button: true,
      label: widget.semanticLabel,
      onTap: _handleTap,
      // With an explicit label, the descendants' own semantics would be
      // read a second time; drop them. The tap action is supplied above
      // rather than left to the GestureDetector inside, because excluding
      // descendants would otherwise drop that too.
      excludeSemantics: widget.semanticLabel != null,
      child: GestureDetector(
        behavior: widget.behavior,
        onTapDown: (_) => _setPressed(true),
        onTapUp: (_) => _setPressed(false),
        onTapCancel: () => _setPressed(false),
        onTap: _handleTap,
        child: AnimatedScale(
          scale: (_pressed && animate) ? widget.pressedScale : 1.0,
          duration: const Duration(milliseconds: 110),
          curve: Curves.easeOut,
          child: widget.child,
        ),
      ),
    );
  }
}
