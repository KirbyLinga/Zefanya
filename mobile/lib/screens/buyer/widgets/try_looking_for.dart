// lib/screens/buyer/widgets/try_looking_for.dart
//
// The "Try looking for" chips (Claude Round A): popular searches as
// loosely rotated stamp tags with motion.
//
//   * Entrance: opacity + an 8dp rise, 40ms apart, once per mount.
//     A private stagger (not StaggeredReveal — that one slides 6%
//     with a 25ms step; the brief pins 8dp/40ms here), same shape:
//     one short controller per chip, disposed with it.
//   * Press: scale to 0.96 on touch-down via AnimatedScale;
//     touch only, no hover states anywhere.
//   * Query reaction: chips whose text matches the query keep full
//     opacity; the rest fade to 60% (AnimatedOpacity). Order comes
//     from rankSearchChips (matches rise to the front).
//
// Transforms and opacity only. Reduced motion shows every end state
// immediately (visible, undisplaced, unscaled, undimmed).

import 'package:flutter/material.dart';

import '../../../theme/app_theme.dart';
import '../../../utils/motion.dart';
import '../../../utils/search_chips.dart';

/// Stagger step between chips — 40ms apart per the brief.
const Duration kChipStaggerStep = Duration(milliseconds: 40);

/// Entrance travel per chip — 8dp rise per the brief.
const double kChipRiseDp = 8.0;

/// Opacity for chips that don't match the query.
const double kChipDimmedOpacity = 0.6;

/// Touch-down scale for chips.
const double kChipPressedScale = 0.96;

/// Popular-search chips with entrance, press and query reaction.
///
/// [query] drives ranking/dimming; pass the screen's live query (or
/// '' for the idle view, where nothing ever reshuffles). [reorder]
/// selects the contract: true reorders matches-first (idle row),
/// false keeps authored order and only dims (dead-end retry row —
/// a Wrap reorder per keystroke would jump). [onSelect] fills and
/// submits the term.
class TryLookingFor extends StatelessWidget {
  const TryLookingFor({
    super.key,
    required this.terms,
    required this.query,
    required this.onSelect,
    this.reorder = true,
  });

  final List<String> terms;
  final String query;
  final ValueChanged<String> onSelect;
  final bool reorder;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final ranked =
        reorder ? rankSearchChips(terms, query) : dimSearchChips(terms, query);
    return Wrap(
      spacing: 14,
      runSpacing: 18,
      children: [
        for (var i = 0; i < ranked.length; i++)
          _ChipEntrance(
            index: i,
            child: _PressableChip(
              term: ranked[i].term,
              dimmed: ranked[i].dimmed,
              tintIndex: i,
              angle: i.isEven ? -0.05 : 0.045,
              onSelect: onSelect,
            ),
          ),
      ],
    );
  }
}

/// One-shot entrance: opacity + 8dp rise, starting [index] * 40ms
/// after mount. Reduced motion jumps to the end state.
class _ChipEntrance extends StatefulWidget {
  const _ChipEntrance({required this.index, required this.child});

  final int index;
  final Widget child;

  @override
  State<_ChipEntrance> createState() => _ChipEntranceState();
}

class _ChipEntranceState extends State<_ChipEntrance>
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
      duration: const Duration(milliseconds: 220),
    );
    final curved =
        CurvedAnimation(parent: _controller, curve: Curves.easeOutCubic);
    _fade = curved;
    // 8dp rise decaying to zero — transform only.
    _rise = Tween<double>(begin: kChipRiseDp, end: 0).animate(curved);
  }

  // MediaQuery isn't reachable in initState; this runs before the
  // first build either way, so reduced motion never flashes the
  // pre-animation state (same shape as StaggeredReveal).
  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;
    if (reduceMotion(context)) {
      _controller.value = 1;
      return;
    }
    Future.delayed(kChipStaggerStep * widget.index, () {
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

/// Stamp tag with touch-down scale and query dimming. At most two
/// concurrent animations per chip (entrance above + this scale /
/// opacity pair); reduced motion pins the end state.
class _PressableChip extends StatefulWidget {
  const _PressableChip({
    required this.term,
    required this.dimmed,
    required this.tintIndex,
    required this.angle,
    required this.onSelect,
  });

  final String term;
  final bool dimmed;
  final int tintIndex;
  final double angle;
  final ValueChanged<String> onSelect;

  static final _tints = [
    AppColors.primary,
    AppColors.tertiary,
    AppColors.secondary,
  ];

  @override
  State<_PressableChip> createState() => _PressableChipState();
}

class _PressableChipState extends State<_PressableChip> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    final reduced = reduceMotion(context);
    return AnimatedOpacity(
      opacity: widget.dimmed ? kChipDimmedOpacity : 1.0,
      duration: reduced ? Duration.zero : const Duration(milliseconds: 180),
      child: GestureDetector(
        onTapDown: (_) => setState(() => _pressed = true),
        onTapUp: (_) => setState(() => _pressed = false),
        onTapCancel: () => setState(() => _pressed = false),
        onTap: () => widget.onSelect(widget.term),
        behavior: HitTestBehavior.opaque,
        child: AnimatedScale(
          scale: _pressed ? kChipPressedScale : 1.0,
          duration: reduced ? Duration.zero : const Duration(milliseconds: 120),
          curve: Curves.easeOutCubic,
          child: Transform.rotate(
            angle: widget.angle,
            child: ActionChip(
              label: Text(widget.term),
              backgroundColor: _PressableChip._tints[widget.tintIndex %
                      _PressableChip._tints.length]
                  .withValues(alpha: 0.28),
              side: BorderSide.none,
              onPressed: () => widget.onSelect(widget.term),
            ),
          ),
        ),
      ),
    );
  }
}
