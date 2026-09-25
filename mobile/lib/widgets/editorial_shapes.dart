// lib/widgets/editorial_shapes.dart
//
// Shape language for "break the rectangle" (critique item #3).
//
// Everything in the app used to be a rounded rectangle with four equal
// corners, and every section started with a flat horizontal edge. That
// uniformity is most of what reads as "template". This file holds the
// three cheap, vector-only ways out of it:
//
//   1. editorialRadius() — asymmetric corners (two big, two small) so a
//      card reads as a deliberate shape rather than a default box.
//   2. ArchClipper        — a top-arch silhouette for feature imagery,
//      the one shape that is unmistakably not a card.
//   3. CurvedSectionBreak / DiagonalSectionBreak — section boundaries
//      that are a curve or a slant instead of a horizontal rule.
//
// None of these depend on photography, so they hold up against the
// current placeholder images.

import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// Asymmetric corner radii — big on one diagonal, small on the other.
///
/// [index] alternates the direction of the "leaf" so a grid of these
/// doesn't become its own uniform pattern; pass the item's index and
/// adjacent tiles will lean opposite ways.
///
/// ## Named tiers — prefer these over raw numbers at call sites:
///
/// | Tier            | big  | small | Used for                              |
/// |-----------------|------|-------|---------------------------------------|
/// | Hero   (44/10)  |  44  |  10   | Full-bleed feature surfaces (profile  |
/// |                 |      |       | hero, earnings summary card)          |
/// | Card   (28/8)   |  28  |   8   | Standard content cards (order cards,  |
/// |                 |      |       | job cards, section panels)            |
/// | Accent (20/6)   |  20  |   6   | Smaller inset surfaces (review tiles, |
/// |                 |      |       | seller row, proof upload box)         |
///
/// Pass [index] so adjacent items lean opposite ways. Default values
/// match the original `editorialRadius(i)` call sites (big:34, small:6)
/// for backwards compat — prefer one of the named tiers above.
///
/// Quick usage:
/// ```dart
/// editorialRadius(index)                  // default (34/6), backwards compat
/// editorialRadius(index, tier: EditorialTier.card)   // 28/8
/// editorialRadius(index, tier: EditorialTier.hero)   // 44/10
/// editorialRadius(index, tier: EditorialTier.accent) // 20/6
/// ```
enum EditorialTier {
  /// 44/10 — full-bleed feature surfaces
  hero,
  /// 28/8 — standard content cards
  card,
  /// 20/6 — smaller inset surfaces
  accent,
}

BorderRadius editorialRadius(int index, {double? big, double? small, EditorialTier? tier}) {
  // Tier values take priority; explicit big/small override tier; defaults are 34/6.
  final b = big ?? _tierBig(tier);
  final s = small ?? _tierSmall(tier);
  final leanLeft = index.isEven;
  return BorderRadius.only(
    topLeft: Radius.circular(leanLeft ? b : s),
    topRight: Radius.circular(leanLeft ? s : b),
    bottomLeft: Radius.circular(leanLeft ? s : b),
    bottomRight: Radius.circular(leanLeft ? b : s),
  );
}

double _tierBig(EditorialTier? tier) => switch (tier) {
      EditorialTier.hero   => 44,
      EditorialTier.card   => 28,
      EditorialTier.accent => 20,
      null                 => 34,
    };

double _tierSmall(EditorialTier? tier) => switch (tier) {
      EditorialTier.hero   => 10,
      EditorialTier.card   => 8,
      EditorialTier.accent => 6,
      null                 => 6,
    };

/// Same idea as a ShapeBorder, for widgets that take a `shape:` rather
/// than a `borderRadius:` (Card, Material).
RoundedRectangleBorder editorialShape(int index, {double? big, double? small, EditorialTier? tier}) {
  return RoundedRectangleBorder(borderRadius: editorialRadius(index, big: big, small: small, tier: tier));
}

/// A "shop window" arch: semicircular top, softly-rounded feet.
///
/// Used for feature product imagery. Degrades safely — if the box is
/// shorter than the arch radius it just clamps rather than drawing an
/// inverted path.
class ArchClipper extends CustomClipper<Path> {
  const ArchClipper({this.footRadius = 14});

  final double footRadius;

  @override
  Path getClip(Size size) {
    final double arch = math.min(size.width / 2, size.height);
    // 0.0, not 0: math.max(double, int) infers num, which then can't be
    // passed to Offset/lineTo. Types annotated so a future edit that
    // reintroduces an int literal fails here instead of downstream.
    final double foot = math.min(footRadius, math.max(size.height - arch, 0.0));

    final path = Path()
      ..moveTo(0, size.height - foot)
      ..lineTo(0, arch)
      ..arcToPoint(
        Offset(size.width, arch),
        radius: Radius.circular(arch),
        clockwise: true,
      )
      ..lineTo(size.width, size.height - foot);

    if (foot > 0) {
      path
        ..arcToPoint(
          Offset(size.width - foot, size.height),
          radius: Radius.circular(foot),
          clockwise: true,
        )
        ..lineTo(foot, size.height)
        ..arcToPoint(
          Offset(0, size.height - foot),
          radius: Radius.circular(foot),
          clockwise: true,
        );
    } else {
      path.lineTo(0, size.height);
    }

    return path..close();
  }

  @override
  bool shouldReclip(covariant ArchClipper oldClipper) => oldClipper.footRadius != footRadius;
}

/// Convenience wrapper so call sites read as a shape, not a clip.
class Arch extends StatelessWidget {
  const Arch({super.key, required this.child, this.footRadius = 14});

  final Widget child;
  final double footRadius;

  @override
  Widget build(BuildContext context) {
    return ClipPath(
      clipper: ArchClipper(footRadius: footRadius),
      child: child,
    );
  }
}

/// A torn-ticket silhouette: rounded card with two semicircular
/// notches genuinely CUT OUT of its outline, top and bottom, at
/// [notchFromLeft] pixels in from the left edge.
///
/// The previous voucher tile faked this by stacking small circles
/// filled with AppColors.background over the card edge. That worked
/// while the scaffold was a flat solid fill — it stopped working the
/// moment main.dart started painting a gradient behind everything,
/// because a flat swatch over a gradient is visibly the wrong color.
/// Clipping the path means whatever is actually behind the card shows
/// through the notch, so it can never drift out of sync again.
///
/// A voucher is conceptually a ticket, not a rounded rectangle; this
/// is the one object in the app where the silhouette itself carries
/// the meaning.
class TicketClipper extends CustomClipper<Path> {
  const TicketClipper({
    required this.notchFromLeft,
    this.notchRadius = 9,
    this.cornerRadius = 16,
  });

  final double notchFromLeft;
  final double notchRadius;
  final double cornerRadius;

  @override
  Path getClip(Size size) {
    final body = Path()
      ..addRRect(RRect.fromRectAndRadius(
        Offset.zero & size,
        Radius.circular(cornerRadius),
      ));

    // Clamped so a very narrow tile can't put the notch outside the
    // card and silently produce an un-cut rectangle.
    final x = notchFromLeft.clamp(notchRadius, size.width - notchRadius);

    final notches = Path()
      ..addOval(Rect.fromCircle(center: Offset(x, 0), radius: notchRadius))
      ..addOval(Rect.fromCircle(center: Offset(x, size.height), radius: notchRadius));

    return Path.combine(PathOperation.difference, body, notches);
  }

  @override
  bool shouldReclip(covariant TicketClipper oldClipper) =>
      oldClipper.notchFromLeft != notchFromLeft ||
      oldClipper.notchRadius != notchRadius ||
      oldClipper.cornerRadius != cornerRadius;
}

/// Convenience wrapper so call sites read as a shape, not a clip.
class Ticket extends StatelessWidget {
  const Ticket({
    super.key,
    required this.child,
    required this.notchFromLeft,
    this.notchRadius = 9,
    this.cornerRadius = 16,
  });

  final Widget child;
  final double notchFromLeft;
  final double notchRadius;
  final double cornerRadius;

  @override
  Widget build(BuildContext context) {
    return ClipPath(
      clipper: TicketClipper(
        notchFromLeft: notchFromLeft,
        notchRadius: notchRadius,
        cornerRadius: cornerRadius,
      ),
      child: child,
    );
  }
}

/// A shallow wave between sections, instead of a flat horizontal edge.
///
/// Sits immediately above or below a colored band and paints the half
/// of itself that belongs to that band:
///   • entering a band (band is BELOW)  -> fillTop: false
///   • leaving a band  (band is ABOVE)  -> fillTop: true
///
/// The two curves aren't mirror images of each other on purpose — a
/// section that's entered and left through the identical curve reads
/// as a decorative frame, which is just the rectangle problem again in
/// a softer outline.
class CurvedSectionBreak extends StatelessWidget {
  const CurvedSectionBreak({
    super.key,
    required this.color,
    this.height = 56,
    this.fillTop = false,
  });

  final Color color;
  final double height;
  final bool fillTop;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: height,
      width: double.infinity,
      child: CustomPaint(painter: _CurvePainter(color: color, fillBottom: !fillTop)),
    );
  }
}

class _CurvePainter extends CustomPainter {
  const _CurvePainter({required this.color, required this.fillBottom});

  final Color color;
  final bool fillBottom;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()..color = color;
    final path = Path();

    if (fillBottom) {
      path
        ..moveTo(0, size.height)
        ..lineTo(0, size.height * 0.35)
        ..cubicTo(
          size.width * 0.30, size.height * 1.05,
          size.width * 0.68, -size.height * 0.30,
          size.width, size.height * 0.55,
        )
        ..lineTo(size.width, size.height)
        ..close();
    } else {
      path
        ..moveTo(0, 0)
        ..lineTo(0, size.height * 0.55)
        ..cubicTo(
          size.width * 0.32, size.height * 1.30,
          size.width * 0.70, -size.height * 0.05,
          size.width, size.height * 0.45,
        )
        ..lineTo(size.width, 0)
        ..close();
    }

    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant _CurvePainter oldDelegate) =>
      oldDelegate.color != color || oldDelegate.fillBottom != fillBottom;
}

/// A slanted section boundary — the hardest-edged of the three, used
/// where a curve would read too soft (e.g. entering the product grid).
class DiagonalSectionBreak extends StatelessWidget {
  const DiagonalSectionBreak({
    super.key,
    required this.color,
    this.height = 40,
    this.rise = 0.55,
  });

  final Color color;
  final double height;

  /// How far up the left edge the slant starts, as a fraction of height.
  final double rise;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: height,
      width: double.infinity,
      child: CustomPaint(painter: _DiagonalPainter(color: color, rise: rise)),
    );
  }
}

class _DiagonalPainter extends CustomPainter {
  const _DiagonalPainter({required this.color, required this.rise});

  final Color color;
  final double rise;

  @override
  void paint(Canvas canvas, Size size) {
    final path = Path()
      ..moveTo(0, 0)
      ..lineTo(size.width, 0)
      ..lineTo(size.width, size.height * (1 - rise))
      ..lineTo(0, size.height)
      ..close();
    canvas.drawPath(path, Paint()..color = color);
  }

  @override
  bool shouldRepaint(covariant _DiagonalPainter oldDelegate) =>
      oldDelegate.color != color || oldDelegate.rise != rise;
}

/// A linen-weave texture band — a horizontal strip with a fine crosshatch
/// pattern drawn in code rather than a bitmap, so it scales cleanly at
/// any DPI and never loads an asset. Used as a section divider to add
/// material variation between content bands without color contrast.
///
/// The weave density is deliberately coarse (every [gap] pixels) so it
/// reads as texture from a normal viewing distance without looking like
/// a grid at close range.
class LinenDivider extends StatelessWidget {
  const LinenDivider({super.key, this.height = 32, this.color, this.gap = 6.0});

  final double height;
  final Color? color;
  final double gap;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: height,
      width: double.infinity,
      child: CustomPaint(
        painter: _LinenPainter(
          color: color ?? AppColors.neutral.withValues(alpha: 0.09),
          gap: gap,
        ),
      ),
    );
  }
}

class _LinenPainter extends CustomPainter {
  const _LinenPainter({required this.color, required this.gap});

  final Color color;
  final double gap;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..strokeWidth = 0.8
      ..strokeCap = StrokeCap.round;

    // Horizontal threads
    for (double y = gap / 2; y < size.height; y += gap) {
      canvas.drawLine(Offset(0, y), Offset(size.width, y), paint);
    }

    // Vertical threads — slightly more transparent so the weave feels
    // woven, not like a plain grid.
    final vertPaint = Paint()
      ..color = color.withValues(alpha: color.a * 0.6)
      ..strokeWidth = 0.8
      ..strokeCap = StrokeCap.round;

    for (double x = gap / 2; x < size.width; x += gap) {
      canvas.drawLine(Offset(x, 0), Offset(x, size.height), vertPaint);
    }
  }

  @override
  bool shouldRepaint(covariant _LinenPainter old) =>
      old.color != color || old.gap != gap;
}
