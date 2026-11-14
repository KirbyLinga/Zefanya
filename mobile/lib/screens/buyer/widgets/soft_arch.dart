// lib/screens/buyer/widgets/soft_arch.dart
//
// A shallower "shop window" arch for small cards: same silhouette
// family as ArchClipper (arced top, softly-rounded feet), but the
// crown depth is a fraction of the width instead of a full
// semicircle — so center-composed product photos keep their subject.
// The card fill and the photo share this clipper: one silhouette for
// the whole card, no rectangular frame behind the arch.

import 'package:flutter/material.dart';

/// The arch crown depth shared by the deck's card fill, photo and shadow.
/// 0.5 is the full semicircle; 0.43 reads as an arch while sparing the
/// photo's top-center subject.
const double kSoftArchDepth = 0.43;

class SoftArchClipper extends CustomClipper<Path> {
  const SoftArchClipper(
      {this.archDepth = kSoftArchDepth, this.footRadius = 14});

  /// Crown depth as a fraction of the card width. 0.5 is the full
  /// semicircle; 0.43 keeps the arch readable while sparing the
  /// photo's top-center subject.
  final double archDepth;
  final double footRadius;

  @override
  Path getClip(Size size) => clipFor(size, archDepth, footRadius);

  /// Shared path builder so the ShapeBorder twin draws the same arch.
  static Path clipFor(Size size, double archDepth, double footRadius) {
    final double w = size.width;
    final double h = size.height;
    final double crown = (w * archDepth).clamp(0.0, h * 0.6);
    final double rest = h - crown;
    final double foot = footRadius < rest ? footRadius : (rest < 0 ? 0.0 : rest);

    final Path path = Path()
      ..moveTo(0, h - foot)
      ..lineTo(0, crown)
      ..quadraticBezierTo(0, 0, w / 2, 0)
      ..quadraticBezierTo(w, 0, w, crown)
      ..lineTo(w, h - foot);

    if (foot > 0) {
      path
        ..arcToPoint(
          Offset(w - foot, h),
          radius: Radius.circular(foot),
          clockwise: true,
        )
        ..lineTo(foot, h)
        ..arcToPoint(
          Offset(0, h - foot),
          radius: Radius.circular(foot),
          clockwise: true,
        );
    } else {
      path.lineTo(0, h);
    }

    return path..close();
  }

  @override
  bool shouldReclip(covariant SoftArchClipper oldClipper) =>
      oldClipper.archDepth != archDepth ||
      oldClipper.footRadius != footRadius;
}

/// ShapeBorder twin of [SoftArchClipper] so the card Material shadow
/// follows the same arch instead of a rectangle.
class SoftArchBorder extends ShapeBorder {
  const SoftArchBorder(
      {this.archDepth = kSoftArchDepth, this.footRadius = 14});

  final double archDepth;
  final double footRadius;

  @override
  EdgeInsetsGeometry get dimensions => EdgeInsets.zero;

  @override
  Path getInnerPath(Rect rect, {TextDirection? textDirection}) =>
      getOuterPath(rect, textDirection: textDirection);

  @override
  Path getOuterPath(Rect rect, {TextDirection? textDirection}) =>
      SoftArchClipper.clipFor(rect.size, archDepth, footRadius);

  @override
  void paint(Canvas canvas, Rect rect, {TextDirection? textDirection}) {}

  @override
  ShapeBorder scale(double t) => this;
}
