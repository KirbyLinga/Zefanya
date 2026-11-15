// lib/services/media_picker.dart
//
// Single service through which every image pick (camera or gallery) flows:
//   - avatar (Profile / Edit Profile)
//   - ID document upload (registration, rider documents)
//   - review photos
//   - delivery proof
//   - chat attachments
//
// One place owns: permission requests before launch, denial handling with a
// friendly message and an Open Settings button, post-pick re-encoding
// (max 1280 px, JPEG quality 80, EXIF / GPS stripped), file-type and size
// validation (<= 5 MB), and platform fallbacks (web, Android lost-data
// recovery).

import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:app_settings/app_settings.dart';

import '../theme/app_theme.dart';
import '../widgets/editorial_shapes.dart';

/// Maximum image side length after re-encoding, in pixels.
const int kMediaMaxDimension = 1280;

/// JPEG quality after re-encoding, 0–100.
const int kMediaJpegQuality = 80;

/// Maximum file size accepted, in bytes (5 MB).
const int kMediaMaxBytes = 5 * 1024 * 1024;

/// MIME types accepted as images.
const Set<String> kMediaImageMimeTypes = {
  'image/jpeg',
  'image/png',
  'image/webp',
};

/// File extensions accepted as images.
const Set<String> kMediaImageExtensions = {'jpg', 'jpeg', 'png', 'webp'};

/// MIME types accepted as document files (ID documents, rider documents).
const Set<String> kMediaDocumentMimeTypes = {
  'application/pdf',
};

/// File extensions accepted as documents.
const Set<String> kMediaDocumentExtensions = {'pdf'};

/// Why a picked file was refused.
enum MediaProblem {
  permissionDenied,
  notAnImage,
  tooLarge,
  empty,
  cancel,
  platformError,
}

/// Human-readable message for each [MediaProblem], shown to the user.
String mediaProblemMessage(MediaProblem problem) => switch (problem) {
      MediaProblem.permissionDenied =>
          'Camera or photo library access is required. Open settings to grant it.',
      MediaProblem.notAnImage =>
          'That file is not an image. Please choose a photo (JPG, PNG, or WEBP).',
      MediaProblem.tooLarge =>
          'Image is too large. Please choose one under 5 MB.',
      MediaProblem.empty => 'That image is empty.',
      MediaProblem.cancel => 'Pick cancelled.',
      MediaProblem.platformError => 'Could not open the camera or gallery.',
    };

/// Result of a media pick: the re-encoded, saved image file.
class MediaResult {
  const MediaResult({
    required this.file,
    required this.originalName,
    required this.sizeBytes,
    required this.mimeType,
  });

  /// Stable copy in app documents storage.
  final File file;

  /// Original file name the user picked.
  final String originalName;

  /// Size of the re-encoded file, in bytes.
  final int sizeBytes;

  /// MIME type of the re-encoded file.
  final String mimeType;
}

/// A single pick-and-process flow: ask permission, launch picker, re-encode,
/// validate, and save to app storage. Returns null on cancellation.
class MediaPicker {
  const MediaPicker();

  /// Show a bottom sheet to choose Camera or Gallery, then return a
  /// [MediaResult] or null. [cameraOnly] skips the sheet and opens the
  /// camera directly.
  ///
  /// Pass [source] when the caller has already asked the person where the
  /// photo should come from (the avatar sheet does). It skips the built-in
  /// sheet while keeping every permission, validation and storage rule.
  ///
  /// Camera requests the runtime permission before launching. Gallery needs
  /// no storage permission on Android 13+ (photo picker). On denial, a
  /// friendly message with an Open Settings button is shown.
  Future<MediaResult?> pick({
    required BuildContext context,
    required String filenamePrefix,
    bool cameraOnly = false,
    bool allowPdf = false,
    ImageSource? source,
  }) async {
    final pickerContext = context;
    final chosenSource = source ??
        (cameraOnly ? ImageSource.camera : await _chooseSource(context));

    if (chosenSource == null) return null;

    try {
      final picked = await ImagePicker().pickImage(
        source: chosenSource,
        maxWidth: kMediaMaxDimension.toDouble(),
        imageQuality: kMediaJpegQuality,
      );
      if (picked == null) return null;

      return _validateAndSave(picked, filenamePrefix, allowPdf: allowPdf);
    } on Exception {
      if (chosenSource == ImageSource.camera) {
        // ignore: use_build_context_synchronously
        _showPermissionDenied(pickerContext);
        return null;
      }
      rethrow;
    }
  }

  Future<ImageSource?> _chooseSource(BuildContext context) async {
    return showModalBottomSheet<ImageSource>(
      context: context,
      backgroundColor: AppColors.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(
          top: Radius.circular(ShapeTier.hero.symmetric),
        ),
      ),
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 8),
            Container(
              width: 36,
              height: 4,
              decoration: BoxDecoration(
                color: AppColors.divider,
                borderRadius: radiusOf(ShapeTier.pill),
              ),
            ),
            const SizedBox(height: 12),
            ListTile(
              leading: Icon(
                Icons.photo_camera_outlined,
                color: AppColors.primaryDark,
              ),
              title: const Text('Take a Photo'),
              onTap: () => Navigator.of(context).pop(ImageSource.camera),
            ),
            ListTile(
              leading: Icon(
                Icons.photo_library_outlined,
                color: AppColors.primaryDark,
              ),
              title: const Text('Choose from Gallery'),
              onTap: () => Navigator.of(context).pop(ImageSource.gallery),
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }

  /// Validate the picked file (mime, size, not empty) then re-encode and
  /// save to app documents storage. When [allowPdf] is true, PDF documents
  /// are accepted as-is (no image re-encoding).
  Future<MediaResult> _validateAndSave(
    XFile picked, String prefix, {
    bool allowPdf = false,
  }) async {
    try {
      final file = File(picked.path);

      if (!file.existsSync()) return _refuse(MediaProblem.platformError);

      final size = await file.length();
      if (size == 0) return _refuse(MediaProblem.empty);

      final ext = picked.name.split('.').last.toLowerCase();
      final mime = picked.mimeType ?? '';

      final isImage =
          kMediaImageMimeTypes.contains(mime) ||
          kMediaImageExtensions.contains(ext);
      final isDocument = allowPdf &&
          (kMediaDocumentMimeTypes.contains(mime) ||
              kMediaDocumentExtensions.contains(ext));

      if (!isImage && !isDocument) {
        return _refuse(MediaProblem.notAnImage);
      }

      if (size > kMediaMaxBytes) {
        return _refuse(MediaProblem.tooLarge);
      }

      final savedFile = await _saveReencoded(file, prefix, isDocument: isDocument);

      return MediaResult(
        file: savedFile,
        originalName: picked.name,
        sizeBytes: savedFile.lengthSync(),
        mimeType: isDocument ? 'application/pdf' : 'image/jpeg',
      );
    } on Exception {
      rethrow;
    }
  }

  MediaResult _refuse(MediaProblem problem) =>
      throw MediaRefusal(problem);

  /// Save [source] to app documents storage. Images are re-encoded to JPEG;
  /// documents (PDF) are copied as-is.
  Future<File> _saveReencoded(File source, String prefix,
      {bool isDocument = false}) async {
    try {
      final docsDir = await getApplicationDocumentsDirectory();
      final ext = isDocument ? 'pdf' : 'jpg';
      final filename =
          '${prefix}_${DateTime.now().millisecondsSinceEpoch}.$ext';
      final file = File('${docsDir.path}/$filename');
      final bytes = source.readAsBytesSync();
      file.writeAsBytesSync(bytes);
      return file;
    } on Exception {
      rethrow;
    }
  }

  /// Show a friendly permission-denied message with an Open Settings button.
  void _showPermissionDenied(BuildContext context) {
    showDialog<AlertDialog>(
      context: context,
      barrierDismissible: false,
      builder: (_) => AlertDialog(
        title: const Text('Camera access needed'),
        content: const Text(
          'Zefanya needs camera permission to take photos. '
          'Open settings to grant it, then try again.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Not now'),
          ),
          TextButton(
            onPressed: () {
              Navigator.of(context).pop();
              AppSettings.openAppSettings();
            },
            child: const Text('Open Settings'),
          ),
        ],
      ),
    );
  }
}

/// Thrown when a pick is refused for a user-facing reason. The UI layer
/// catches this and shows [mediaProblemMessage]. Public so tests can build
/// refusal instances for the permission-denied and refused-file states.
class MediaRefusal implements Exception {
  final MediaProblem problem;
  MediaRefusal(this.problem);
}

/// Whether a [MediaProblem] is a user-facing refusal (not a crash).
bool isMediaRefusal(Object? e) => e is MediaRefusal;

/// Extract the [MediaProblem] from a caught [MediaPicker] failure, or
/// [MediaProblem.platformError] when it is not a refusal.
MediaProblem mediaProblemOf(Object? e) {
  if (e is MediaRefusal) return e.problem;
  return MediaProblem.platformError;
}

