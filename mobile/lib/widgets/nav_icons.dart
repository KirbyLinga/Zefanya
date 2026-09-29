// lib/widgets/nav_icons.dart
//
// Custom stroke-drawn icons for the bottom nav — the single most-
// repeated icon surface in the app, seen on every screen, every
// session. Everywhere else still uses Material Icons; this is scoped
// deliberately narrow (8 icons, all geometrically simple and forgiving
// to hand-code without a live render to check against) rather than
// attempting a full icon set blind.
//
// The courier set (parcel / scooter / peso) was added late, and that
// lateness was the tell: the buyer's four tabs had hand-drawn glyphs and
// the courier's four were still stock Material outlines, so the two roles
// were visibly two different apps on the most-seen surface in either of
// them. Profile is shared — it's the same person, from two sides.
//
// Same drawing language as widgets/zefanya_mark.dart: stroke only, no
// fill, round caps/joins, paths normalized to a 100x100 box. "Active"
// state is color + a small stroke-weight bump (handled by the caller),
// not a second filled glyph — there's no filled variant of this style,
// on purpose, so it stays visually one family with the monogram.

import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

enum NavGlyph { home, search, categories, orders, profile, parcel, scooter, peso }

List<Path> _pathsFor(NavGlyph glyph, Size size) {
  final s = size.shortestSide / 100;
  double x(double v) => v * s + (size.width - 100 * s) / 2;
  double y(double v) => v * s + (size.height - 100 * s) / 2;
  Offset p(double vx, double vy) => Offset(x(vx), y(vy));

  switch (glyph) {
    case NavGlyph.home:
      // Roof and base are two separate strokes on purpose — a home
      // icon reads as "roof over a box", not one continuous outline,
      // and forcing it into a single subpath would need an extra
      // diagonal line that isn't part of the actual silhouette.
      final roof = Path()
        ..moveTo(p(18, 52).dx, p(18, 52).dy)
        ..lineTo(p(50, 22).dx, p(50, 22).dy)
        ..lineTo(p(82, 52).dx, p(82, 52).dy);
      final base = Path()
        ..moveTo(p(28, 48).dx, p(28, 48).dy)
        ..lineTo(p(28, 82).dx, p(28, 82).dy)
        ..lineTo(p(72, 82).dx, p(72, 82).dy)
        ..lineTo(p(72, 48).dx, p(72, 48).dy);
      return [roof, base];

    case NavGlyph.search:
      final glass = Path()..addOval(Rect.fromCircle(center: p(42, 42), radius: 22 * s));
      final handle = Path()
        ..moveTo(p(59, 59).dx, p(59, 59).dy)
        ..lineTo(p(80, 80).dx, p(80, 80).dy);
      return [glass, handle];

    case NavGlyph.categories:
      // Four rounded squares rather than a literal grid line — reads
      // less like a spreadsheet icon, more like a stack of cards,
      // which fits a shop's categories better.
      Path square(double left, double top) => Path()
        ..addRRect(RRect.fromRectAndRadius(
          Rect.fromLTWH(p(left, top).dx, p(left, top).dy, 22 * s, 22 * s),
          Radius.circular(5 * s),
        ));
      return [square(20, 20), square(58, 20), square(20, 58), square(58, 58)];

    case NavGlyph.orders:
      // The same torn/perforated edge language as the voucher tickets
      // and the order-status cards elsewhere in the app — this icon
      // is a receipt, and it's built the same way the app already
      // draws receipts, not a generic document glyph.
      final body = Path()
        ..moveTo(p(25, 18).dx, p(25, 18).dy)
        ..lineTo(p(75, 18).dx, p(75, 18).dy)
        ..lineTo(p(75, 74).dx, p(75, 74).dy)
        ..lineTo(p(65, 66).dx, p(65, 66).dy)
        ..lineTo(p(55, 74).dx, p(55, 74).dy)
        ..lineTo(p(45, 66).dx, p(45, 66).dy)
        ..lineTo(p(35, 74).dx, p(35, 74).dy)
        ..lineTo(p(25, 66).dx, p(25, 66).dy)
        ..close();
      final lines = Path()
        ..moveTo(p(35, 34).dx, p(35, 34).dy)
        ..lineTo(p(65, 34).dx, p(65, 34).dy)
        ..moveTo(p(35, 46).dx, p(35, 46).dy)
        ..lineTo(p(65, 46).dx, p(65, 46).dy)
        ..moveTo(p(35, 58).dx, p(35, 58).dy)
        ..lineTo(p(56, 58).dx, p(56, 58).dy);
      return [body, lines];

    case NavGlyph.profile:
      final head = Path()..addOval(Rect.fromCircle(center: p(50, 32), radius: 15 * s));
      final shoulders = Path()
        ..moveTo(p(22, 84).dx, p(22, 84).dy)
        ..quadraticBezierTo(p(50, 46).dx, p(50, 46).dy, p(78, 84).dx, p(78, 84).dy);
      return [head, shoulders];

    // ── Courier set ───────────────────────────────────────────────────
    //
    // Both of these went through a rendered comparison before being kept —
    // see test/nav_glyphs_golden_test.dart, which exists so the next person
    // can do the same rather than taking the path data on faith.

    case NavGlyph.parcel:
      // A box with its lid folded open.
      //
      // The first version was a box with a lid line *and* a tape line, and
      // at 20px it read as a window: two crossing lines inside a rectangle
      // is the universal "layout/table" icon, and it sat one tab away from
      // categories' four squares. The fold is the thing that makes it a box
      // rather than a pane, and it also happens to mean "waiting to be
      // collected", which is what this tab is.
      final box = Path()
        ..addRRect(RRect.fromRectAndRadius(
          Rect.fromLTWH(p(20, 32).dx, p(20, 32).dy, 60 * s, 50 * s),
          Radius.circular(5 * s),
        ));
      final lid = Path()
        ..moveTo(p(20, 48).dx, p(20, 48).dy)
        ..lineTo(p(50, 36).dx, p(50, 36).dy)
        ..lineTo(p(80, 48).dx, p(80, 48).dy);
      return [box, lid];

    case NavGlyph.scooter:
      // Two wheels, a rising deck and a steering column with a bar. Same
      // two-wheeler motif the promo carousel already uses for this
      // delivery story, drawn in the app's own stroke language. This one
      // read correctly first time in the render, so it's unchanged.
      final rearWheel = Path()..addOval(Rect.fromCircle(center: p(28, 72), radius: 11 * s));
      final frontWheel = Path()..addOval(Rect.fromCircle(center: p(68, 72), radius: 11 * s));
      final deck = Path()
        ..moveTo(p(28, 72).dx, p(28, 72).dy)
        ..lineTo(p(50, 60).dx, p(50, 60).dy)
        ..lineTo(p(68, 60).dx, p(68, 60).dy);
      final column = Path()
        ..moveTo(p(68, 60).dx, p(68, 60).dy)
        ..lineTo(p(60, 30).dx, p(60, 30).dy);
      final bar = Path()
        ..moveTo(p(50, 30).dx, p(50, 30).dy)
        ..lineTo(p(70, 30).dx, p(70, 30).dy);
      return [rearWheel, frontWheel, deck, column, bar];

    case NavGlyph.peso:
      // The peso sign itself, drawn large: stem, bowl, and the crossbar that
      // is the only difference between ₱ and P.
      //
      // The first version was a coin stack, on the theory that a ₱ collapses
      // at nav size. Rendered, it was the coin stack that failed — it read
      // as a can, or a database icon, at every size — while the ₱ held at
      // 20px because it's drawn out to ~72% of the box with a single
      // crossbar (two bars would merge into one thick line at that size).
      final stem = Path()
        ..moveTo(p(34, 88).dx, p(34, 88).dy)
        ..lineTo(p(34, 16).dx, p(34, 16).dy);
      final bowl = Path()
        ..moveTo(p(34, 18).dx, p(34, 18).dy)
        ..quadraticBezierTo(p(70, 18).dx, p(70, 18).dy, p(70, 38).dx, p(70, 38).dy)
        ..quadraticBezierTo(p(70, 56).dx, p(70, 56).dy, p(34, 56).dx, p(34, 56).dy);
      final crossbar = Path()
        ..moveTo(p(20, 62).dx, p(20, 62).dy)
        ..lineTo(p(80, 62).dx, p(80, 62).dy);
      return [stem, bowl, crossbar];
  }
}

class NavIcon extends StatelessWidget {
  const NavIcon({
    super.key,
    required this.glyph,
    this.size = 20,
    this.color,
    this.strokeWidth = 2.0,
  });

  final NavGlyph glyph;
  final double size;

  /// Nullable rather than defaulted: the palette resolves at runtime now,
  /// and a default argument has to be const. Falls back to
  /// [AppColors.textSecondary] at paint time.
  final Color? color;
  final double strokeWidth;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: CustomPaint(
        painter: _NavIconPainter(glyph: glyph, color: color ?? AppColors.textSecondary, strokeWidth: strokeWidth),
      ),
    );
  }
}

class _NavIconPainter extends CustomPainter {
  const _NavIconPainter({required this.glyph, required this.color, required this.strokeWidth});

  final NavGlyph glyph;
  final Color color;
  final double strokeWidth;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth * (size.shortestSide / 100) * 3.4
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;

    for (final path in _pathsFor(glyph, size)) {
      canvas.drawPath(path, paint);
    }
  }

  @override
  bool shouldRepaint(covariant _NavIconPainter oldDelegate) =>
      oldDelegate.glyph != glyph || oldDelegate.color != color || oldDelegate.strokeWidth != strokeWidth;
}
