// lib/widgets/google_mark.dart
//
// The small four-colour mark on the Google sign-in button.
//
// Drawn rather than imported: the app bundles its own fonts and icons, and a
// raster logo would need @2x/@3x variants for every density. The arcs follow
// Google's mark closely enough to read at button size; the button's text says
// what the action is, so the mark is decoration rather than the only label.

import 'dart:math' as math;

import 'package:flutter/material.dart';

class GoogleMark extends StatelessWidget {
  const GoogleMark({super.key, this.size = 18});

  final double size;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: CustomPaint(painter: _GoogleMarkPainter()),
    );
  }
}

class _GoogleMarkPainter extends CustomPainter {
  static const _blue = Color(0xFF4285F4);
  static const _red = Color(0xFFEA4335);
  static const _yellow = Color(0xFFFBBC05);
  static const _green = Color(0xFF34A853);

  @override
  void paint(Canvas canvas, Size size) {
    final stroke = size.width * 0.19;
    final radius = (size.width - stroke) / 2;
    final rect = Rect.fromCircle(
      center: Offset(size.width / 2, size.height / 2),
      radius: radius,
    );

    final arc = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.butt;

    void draw(Color color, double startDegrees, double sweepDegrees) {
      arc.color = color;
      canvas.drawArc(
        rect,
        startDegrees * math.pi / 180,
        sweepDegrees * math.pi / 180,
        false,
        arc,
      );
    }

    // Clockwise from the left: red (top-left), blue (top-right), then the
    // right-side gap the bar sits in, green (bottom-right), yellow
    // (bottom-left).
    draw(_red, 180, 75);
    draw(_blue, 285, 75);
    draw(_green, 30, 75);
    draw(_yellow, 105, 75);

    // The crossbar that turns the ring into a G.
    final bar = Paint()
      ..color = _blue
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.butt;
    canvas.drawLine(
      Offset(size.width * 0.52, size.height * 0.5),
      Offset(size.width * 0.95, size.height * 0.5),
      bar,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
