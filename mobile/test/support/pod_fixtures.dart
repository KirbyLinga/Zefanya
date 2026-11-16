// test/support/pod_fixtures.dart
//
// Real image bytes for the Proof of Delivery tests: a genuine PNG produced by
// the engine, one with an extra metadata chunk (to prove normalization drops
// it), and helpers to inspect PNG chunks.

import 'dart:typed_data';
import 'dart:ui' as ui;

/// A valid PNG of [width] x [height] with a diagonal line drawn on it.
Future<Uint8List> makePng({int width = 16, int height = 12}) async {
  final recorder = ui.PictureRecorder();
  final canvas = ui.Canvas(recorder);
  canvas.drawRect(
    ui.Rect.fromLTWH(0, 0, width.toDouble(), height.toDouble()),
    ui.Paint()..color = const ui.Color(0xFFFFFFFF),
  );
  canvas.drawLine(
    ui.Offset.zero,
    ui.Offset(width.toDouble(), height.toDouble()),
    ui.Paint()
      ..color = const ui.Color(0xFF000000)
      ..strokeWidth = 2,
  );
  final image = await recorder.endRecording().toImage(width, height);
  final data = await image.toByteData(format: ui.ImageByteFormat.png);
  image.dispose();
  return data!.buffer.asUint8List();
}

/// [png] with a `tEXt` metadata chunk (keyword + text) inserted before IEND,
/// standing in for the EXIF/GPS blocks a camera capture can carry.
Uint8List withTextChunk(Uint8List png, String keyword, String text) {
  final iend = png.length - 12; // length(4) + type(4) + crc(4), no data
  final body = Uint8List.fromList([
    ...'tEXt'.codeUnits,
    ...keyword.codeUnits,
    0,
    ...text.codeUnits,
  ]);
  final chunk = BytesBuilder()
    ..add(_u32(body.length - 4))
    ..add(body)
    ..add(_u32(_crc32(body)));
  return Uint8List.fromList([
    ...png.sublist(0, iend),
    ...chunk.toBytes(),
    ...png.sublist(iend),
  ]);
}

/// The chunk type names of a PNG, in order.
List<String> pngChunks(Uint8List png) {
  final types = <String>[];
  var offset = 8;
  while (offset + 8 <= png.length) {
    final length = ByteData.sublistView(png, offset, offset + 4).getUint32(0);
    types.add(String.fromCharCodes(png.sublist(offset + 4, offset + 8)));
    offset += 12 + length;
  }
  return types;
}

/// `(width, height)` from the IHDR chunk.
(int, int) pngSize(Uint8List png) {
  final view = ByteData.sublistView(png, 16, 24);
  return (view.getUint32(0), view.getUint32(4));
}

Uint8List _u32(int v) =>
    Uint8List(4)..buffer.asByteData().setUint32(0, v & 0xFFFFFFFF);

int _crc32(List<int> bytes) {
  var crc = 0xFFFFFFFF;
  for (final b in bytes) {
    crc ^= b;
    for (var i = 0; i < 8; i++) {
      crc = (crc & 1) != 0 ? (crc >> 1) ^ 0xEDB88320 : crc >> 1;
    }
  }
  return (crc ^ 0xFFFFFFFF) & 0xFFFFFFFF;
}
