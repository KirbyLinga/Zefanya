// lib/screens/courier/pod/signature_pad.dart
//
// A finger-drawn recipient signature, with no extra package: strokes are kept
// as offsets, painted with a CustomPaint, and exported as a PNG when the rider
// accepts the signature.

import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

class SignatureController extends ChangeNotifier {
  final List<List<Offset>> _strokes = [];

  List<List<Offset>> get strokes => List.unmodifiable(_strokes);

  /// True when nothing has been drawn.
  bool get isEmpty => _strokes.every((stroke) => stroke.length < 2);

  void startStroke(Offset point) {
    _strokes.add([point]);
    notifyListeners();
  }

  void extendStroke(Offset point) {
    if (_strokes.isEmpty) return;
    _strokes.last.add(point);
    notifyListeners();
  }

  void clear() {
    _strokes.clear();
    notifyListeners();
  }

  /// Renders the signature as a PNG on a white background.
  ///
  /// [size] is the drawing area in logical pixels; the image is scaled by
  /// [pixelRatio] so it stays sharp. Returns null for an empty signature.
  Future<Uint8List?> toPngBytes({
    required Size size,
    double pixelRatio = 2,
    double strokeWidth = 3,
  }) async {
    if (isEmpty || size.isEmpty) return null;
    final recorder = ui.PictureRecorder();
    final canvas = Canvas(recorder)..scale(pixelRatio);
    canvas.drawRect(
      Offset.zero & size,
      Paint()..color = const Color(0xFFFFFFFF),
    );
    paintStrokes(canvas, _strokes, const Color(0xFF111111), strokeWidth);
    final picture = recorder.endRecording();
    final image = await picture.toImage(
      (size.width * pixelRatio).round(),
      (size.height * pixelRatio).round(),
    );
    final data = await image.toByteData(format: ui.ImageByteFormat.png);
    image.dispose();
    return data?.buffer.asUint8List();
  }
}

/// Paints [strokes]; shared by the on-screen pad and the PNG export so what is
/// saved is exactly what was drawn.
void paintStrokes(
  Canvas canvas,
  List<List<Offset>> strokes,
  Color color,
  double width,
) {
  final paint = Paint()
    ..color = color
    ..strokeWidth = width
    ..strokeCap = StrokeCap.round
    ..strokeJoin = StrokeJoin.round
    ..style = PaintingStyle.stroke;
  for (final stroke in strokes) {
    if (stroke.length < 2) continue;
    final path = Path()..moveTo(stroke.first.dx, stroke.first.dy);
    for (final point in stroke.skip(1)) {
      path.lineTo(point.dx, point.dy);
    }
    canvas.drawPath(path, paint);
  }
}

class SignaturePad extends StatelessWidget {
  const SignaturePad({
    super.key,
    required this.controller,
    this.height = 180,
    this.enabled = true,
  });

  final SignatureController controller;
  final double height;
  final bool enabled;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) => Container(
      height: height,
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: Theme.of(context).dividerColor),
        borderRadius: BorderRadius.circular(12),
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: GestureDetector(
          key: const ValueKey('signature-pad-surface'),
          behavior: HitTestBehavior.opaque,
          onPanStart: enabled
              ? (d) => controller.startStroke(d.localPosition)
              : null,
          onPanUpdate: enabled
              ? (d) => controller.extendStroke(d.localPosition)
              : null,
          child: ListenableBuilder(
            listenable: controller,
            builder: (context, _) => CustomPaint(
              size: Size.infinite,
              painter: _SignaturePainter(controller.strokes),
            ),
          ),
        ),
      ),
    ),
  );
}

class _SignaturePainter extends CustomPainter {
  _SignaturePainter(this.strokes);

  final List<List<Offset>> strokes;

  @override
  void paint(Canvas canvas, Size size) =>
      paintStrokes(canvas, strokes, const Color(0xFF111111), 3);

  @override
  bool shouldRepaint(covariant _SignaturePainter oldDelegate) => true;
}
