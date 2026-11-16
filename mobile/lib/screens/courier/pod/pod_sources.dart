// lib/screens/courier/pod/pod_sources.dart
//
// The device-facing seams of the Proof of Delivery screen, injectable so the
// screen can be tested without a camera or a file system.
//
// **Privacy and lifetime.** The preview collects real recipient photos and
// signatures, so it owns its files: they are written to a temporary preview
// folder, only those files are ever deleted (by this store, never another
// feature's), and they are removed when the evidence is replaced, discarded or
// the screen is closed. Photos are re-encoded from pixels, which drops the
// capture's EXIF / GPS metadata (see `pod_evidence.dart`).

import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/widgets.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';

/// A raw capture straight from the camera, before normalization.
class PodRawCapture {
  const PodRawCapture(this.path, {this.deleteAfterUse = false});

  final String path;

  /// True when the capture created [path] only for this use (the picker's cache
  /// copy), so it may be deleted once it has been normalized. A caller-supplied
  /// fixture must leave this false so it is never removed.
  final bool deleteAfterUse;
}

/// Captures the delivery photograph.
abstract class PodPhotoSource {
  /// The raw capture, or null when the rider cancelled. Throws for a
  /// permission or hardware problem.
  Future<PodRawCapture?> capture(BuildContext context, {required String jobId});
}

/// The camera, through `image_picker` (which asks for the camera permission).
class CameraPodPhotoSource implements PodPhotoSource {
  const CameraPodPhotoSource();

  @override
  Future<PodRawCapture?> capture(
    BuildContext context, {
    required String jobId,
  }) async {
    final picked = await ImagePicker().pickImage(
      source: ImageSource.camera,
      maxWidth: 1600,
      imageQuality: 85,
    );
    return picked == null ? null : PodRawCapture(picked.path, deleteAfterUse: true);
  }
}

/// Stores the preview's own evidence files and removes them again.
abstract class PodFileStore {
  /// Writes a normalized photo PNG and returns its path.
  Future<String> savePhotoPng(Uint8List png, {required String jobId});

  /// Writes a signature PNG and returns its path.
  Future<String> saveSignaturePng(Uint8List png, {required String jobId});

  /// Deletes [path] **only if this store created it**; any other path is left
  /// untouched. Safe to call twice.
  Future<void> delete(String path);

  /// True when this store created [path] and has not yet deleted it.
  bool owns(String path);

  /// Deletes every file this store still owns.
  Future<void> deleteAll();

  /// Same as [deleteAll], synchronously, for a screen that is being disposed
  /// (a handful of small temp files; it must not depend on the event loop).
  void deleteAllSync();
}

/// Writes under the app's temporary directory (`pod_preview/`).
class TempPodFileStore implements PodFileStore {
  TempPodFileStore({Future<Directory> Function()? directory})
    : _directory = directory ?? getTemporaryDirectory;

  final Future<Directory> Function() _directory;
  final Set<String> _owned = {};
  int _counter = 0;

  Future<String> _write(Uint8List png, String kind, String jobId) async {
    final base = await _directory();
    final folder = Directory('${base.path}/pod_preview');
    await folder.create(recursive: true);
    final file = File(
      '${folder.path}/${kind}_${jobId}_${DateTime.now().microsecondsSinceEpoch}_${_counter++}.png',
    );
    await file.writeAsBytes(png, flush: true);
    _owned.add(file.path);
    return file.path;
  }

  @override
  Future<String> savePhotoPng(Uint8List png, {required String jobId}) =>
      _write(png, 'photo', jobId);

  @override
  Future<String> saveSignaturePng(Uint8List png, {required String jobId}) =>
      _write(png, 'signature', jobId);

  @override
  bool owns(String path) => _owned.contains(path);

  @override
  Future<void> delete(String path) async {
    if (!_owned.remove(path)) return;
    try {
      final file = File(path);
      if (await file.exists()) await file.delete();
    } on FileSystemException {
      // Nothing more can be done; the OS clears the temp folder.
    }
  }

  @override
  Future<void> deleteAll() async {
    for (final path in List.of(_owned)) {
      await delete(path);
    }
  }

  @override
  void deleteAllSync() {
    for (final path in List.of(_owned)) {
      _owned.remove(path);
      try {
        final file = File(path);
        if (file.existsSync()) file.deleteSync();
      } on FileSystemException {
        // The OS clears the temp folder eventually.
      }
    }
  }
}
