// lib/utils/image_picker_helper.dart
//
// Shared "pick an image" flow used by Registration's document uploads
// and Profile's avatar. Shows a bottom sheet to choose Camera vs
// Gallery, then copies the picked file into the app's own documents
// directory before returning it — image_picker's raw path can point
// into a temp/cache location the OS is free to clear, so anything
// meant to persist (a saved avatar path, in particular) needs its own
// stable copy rather than trusting the original path to still exist
// next launch.
//
// All picking now routes through [MediaPicker] (lib/services/media_picker.dart),
// which owns permissions, denial handling, re-encoding, validation and
// storage. This helper remains as a thin compatibility wrapper so existing
// call sites (registration, profile, review, rider documents, chat) keep
// working while all image logic lives in one service.

import 'dart:io';

import 'package:flutter/material.dart';

import '../services/media_picker.dart';

/// Shows a bottom sheet to pick Camera or Gallery, then returns the
/// picked image copied into permanent app storage — or null if the
/// user cancelled at any point.
///
/// All picking is handled by [MediaPicker], which:
///   - requests the camera permission before launching the camera,
///   - shows a friendly message with Open Settings on denial,
///   - re-encodes to max 1280 px, JPEG quality 80, stripping EXIF/GPS,
///   - rejects non-images and files over 5 MB.
Future<File?> pickAndSaveImage(
  BuildContext context, {
  required String filenamePrefix,
  bool cameraOnly = false,
}) async {
  final media = MediaPicker();
  final result = await media.pick(
    context: context,
    filenamePrefix: filenamePrefix,
    cameraOnly: cameraOnly,
  );
  if (result == null) return null;
  return result.file;
}

/// Re-thrown when [MediaPicker] refuses a pick for a user-facing reason.
class ImagePickRefusal implements Exception {
  final MediaProblem problem;
  ImagePickRefusal(this.problem);
}

/// Whether [e] is a user-facing image-pick refusal.
bool isImagePickRefusal(Object? e) => e is ImagePickRefusal;

/// Extract the [MediaProblem] from a caught image-pick failure, or
/// [MediaProblem.platformError] when it is not a refusal.
MediaProblem imagePickProblemOf(Object? e) {
  if (e is ImagePickRefusal) return e.problem;
  return MediaProblem.platformError;
}

