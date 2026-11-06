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

// Re-exported so a widget that already imports the shapes file can name a
// tier (`ShapeTier.card`) without a second import.
export '../theme/app_theme.dart' show ShapeTier;

/// Asymmetric corner radii — long on one diagonal, short on the other.
///
/// [index] alternates the direction of the "leaf" so a grid of these
/// doesn't become its own uniform pattern; pass the item's index and
/// adjacent tiles lean opposite ways.
///
/// The corners themselves come from [ShapeTier] — the app's single shape
/// vocabulary, defined in `theme/app_theme.dart` along with the reasoning
/// and the table. This function is only the *applicator*: it turns a tier
/// into four asymmetric radii, leaning per [index].
///
/// ```dart
/// editorialRadius(index, ShapeTier.card)    // 28 / 8
/// editorialRadius(index, ShapeTier.hero)    // 44 / 10
/// editorialRadius(index, ShapeTier.accent)  // 20 / 6
/// ```
BorderRadius editorialRadius(int index, ShapeTier tier) {
  final leanLeft = index.isEven;
  final b = tier.big;
  final s = tier.small;
  return BorderRadius.only(
    topLeft: Radius.circular(leanLeft ? b : s),
    topRight: Radius.circular(leanLeft ? s : b),
    bottomLeft: Radius.circular(leanLeft ? s : b),
    bottomRight: Radius.circular(leanLeft ? b : s),
  );
}

/// Escape hatch for the two shapes whose corners scale with their own
/// *measured size* rather than following a tier: the success flourish
/// (fractions of its animation size) and the profile hero's deliberately
/// oversized leaf. Prefer [editorialRadius] — if a third call site wants
/// this, it probably wants a tier, and the tier table should grow instead
/// of this list.
BorderRadius editorialRadiusRaw(int index,
    {required double big, required double small}) {
  final leanLeft = index.isEven;
  return BorderRadius.only(
    topLeft: Radius.circular(leanLeft ? big : small),
    topRight: Radius.circular(leanLeft ? small : big),
    bottomLeft: Radius.circular(leanLeft ? small : big),
    bottomRight: Radius.circular(leanLeft ? big : small),
  );
}

/// A tier as a uniform radius, for Material surfaces that take one number
/// — buttons, fields, sheets, ink splashes. Saves every call site from
/// re-deciding between 8, 12, 16 and "a big one".
BorderRadius radiusOf(ShapeTier tier) => BorderRadius.circular(tier.symmetric);

/// Same idea as a ShapeBorder, for widgets that take a `shape:` rather
/// than a `borderRadius:` (Card, Material).
RoundedRectangleBorder editorialShape(int index, ShapeTier tier) {
  return RoundedRectangleBorder(borderRadius: editorialRadius(index, tier));
}

/// Escape hatch, as [editorialRadiusRaw] — for `shape:` call sites.
RoundedRectangleBorder editorialShapeRaw(int index,
    {required double big, required double small}) {
  return RoundedRectangleBorder(
    borderRadius: editorialRadiusRaw(index, big: big, small: small),
  );
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
    AppColors.watch(context);
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
    AppColors.watch(context);
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
    AppColors.watch(context);
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
    AppColors.watch(context);
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
