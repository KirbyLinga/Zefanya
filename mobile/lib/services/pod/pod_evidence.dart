// lib/services/pod/pod_evidence.dart
//
// Checking and normalizing the evidence a Proof of Delivery preview holds.
//
//   * [PodEvidenceValidator] answers "is this file real evidence?": it exists,
//     is not empty, is within the size limit, has the signature of a supported
//     image format for its method and actually decodes. A deleted temp file or
//     a malformed image is therefore refused instead of producing a success
//     receipt. No signature-complexity or GPS-accuracy rule is invented here.
//   * [normalizeToPng] re-encodes a photo through the engine's codec, which
//     writes a fresh PNG from the pixels only. EXIF / GPS / XMP and every other
//     metadata block of the original capture are not carried over, and the
//     image is bounded to [kPodMaxImageSide].

import 'dart:io';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'pod_models.dart';

/// Longest edge, in pixels, of a stored photo.
const int kPodMaxImageSide = 1280;

/// Largest evidence file accepted (matches the server's 5 MB proof limit).
const int kPodMaxEvidenceBytes = 5 * 1024 * 1024;

enum EvidenceProblem {
  missing,
  empty,
  tooLarge,
  unsupportedFormat,
  unreadable;

  String get message => switch (this) {
    EvidenceProblem.missing =>
      'The evidence file is missing. Capture it again.',
    EvidenceProblem.empty => 'The evidence file is empty. Capture it again.',
    EvidenceProblem.tooLarge =>
      'The evidence file is larger than 5 MB. Capture it again.',
    EvidenceProblem.unsupportedFormat =>
      'The evidence is not a supported image. Capture it again.',
    EvidenceProblem.unreadable =>
      'The evidence image could not be read. Capture it again.',
  };
}

/// Thrown when a capture cannot be turned into usable evidence.
class PodEvidenceException implements Exception {
  const PodEvidenceException(this.problem);

  final EvidenceProblem problem;

  @override
  String toString() => problem.message;
}

abstract class PodEvidenceValidator {
  /// Null when [evidence] is readable, supported evidence.
  Future<EvidenceProblem?> validate(PodEvidence evidence);
}

class FilePodEvidenceValidator implements PodEvidenceValidator {
  const FilePodEvidenceValidator();

  @override
  Future<EvidenceProblem?> validate(PodEvidence evidence) async {
    final file = File(evidence.path);
    try {
      if (!await file.exists()) return EvidenceProblem.missing;
      final length = await file.length();
      if (length == 0) return EvidenceProblem.empty;
      if (length > kPodMaxEvidenceBytes) return EvidenceProblem.tooLarge;
      final bytes = await file.readAsBytes();
      final format = sniffImageFormat(bytes);
      final allowed = evidence.method == PodEvidenceMethod.signature
          ? const {PodImageFormat.png}
          : const {PodImageFormat.png, PodImageFormat.jpeg, PodImageFormat.webp};
      if (format == null || !allowed.contains(format)) {
        return EvidenceProblem.unsupportedFormat;
      }
      return await _decodes(bytes) ? null : EvidenceProblem.unreadable;
    } on FileSystemException {
      return EvidenceProblem.unreadable;
    }
  }
}

enum PodImageFormat { jpeg, png, webp }

/// The format named by the file's leading bytes, or null.
PodImageFormat? sniffImageFormat(Uint8List b) {
  if (b.length >= 3 && b[0] == 0xFF && b[1] == 0xD8 && b[2] == 0xFF) {
    return PodImageFormat.jpeg;
  }
  if (b.length >= 8 &&
      b[0] == 0x89 &&
      b[1] == 0x50 &&
      b[2] == 0x4E &&
      b[3] == 0x47 &&
      b[4] == 0x0D &&
      b[5] == 0x0A &&
      b[6] == 0x1A &&
      b[7] == 0x0A) {
    return PodImageFormat.png;
  }
  if (b.length >= 12 &&
      b[0] == 0x52 &&
      b[1] == 0x49 &&
      b[2] == 0x46 &&
      b[3] == 0x46 &&
      b[8] == 0x57 &&
      b[9] == 0x45 &&
      b[10] == 0x42 &&
      b[11] == 0x50) {
    return PodImageFormat.webp;
  }
  return null;
}

Future<bool> _decodes(Uint8List bytes) async {
  try {
    final codec = await ui.instantiateImageCodec(bytes);
    try {
      final frame = await codec.getNextFrame();
      final ok = frame.image.width > 0 && frame.image.height > 0;
      frame.image.dispose();
      return ok;
    } finally {
      codec.dispose();
    }
  } on Object {
    return false;
  }
}

/// Decodes [bytes], bounds the longest edge to [maxSide], and returns a freshly
/// encoded PNG. Throws [PodEvidenceException] when [bytes] is not a decodable
/// image or the result is over the size limit.
Future<Uint8List> normalizeToPng(
  Uint8List bytes, {
  int maxSide = kPodMaxImageSide,
}) async {
  if (bytes.isEmpty) throw const PodEvidenceException(EvidenceProblem.empty);
  if (sniffImageFormat(bytes) == null) {
    throw const PodEvidenceException(EvidenceProblem.unsupportedFormat);
  }
  try {
    final probe = await ui.instantiateImageCodec(bytes);
    final first = await probe.getNextFrame();
    final width = first.image.width;
    final height = first.image.height;
    first.image.dispose();
    probe.dispose();

    final codec = await ui.instantiateImageCodec(
      bytes,
      targetWidth: width >= height && width > maxSide ? maxSide : null,
      targetHeight: height > width && height > maxSide ? maxSide : null,
    );
    final frame = await codec.getNextFrame();
    final data = await frame.image.toByteData(format: ui.ImageByteFormat.png);
    frame.image.dispose();
    codec.dispose();
    if (data == null) {
      throw const PodEvidenceException(EvidenceProblem.unreadable);
    }
    final png = data.buffer.asUint8List();
    if (png.length > kPodMaxEvidenceBytes) {
      throw const PodEvidenceException(EvidenceProblem.tooLarge);
    }
    return png;
  } on PodEvidenceException {
    rethrow;
  } on Object {
    throw const PodEvidenceException(EvidenceProblem.unreadable);
  }
}
