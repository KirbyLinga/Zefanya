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

import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import '../theme/app_theme.dart';

/// Shows a bottom sheet to pick Camera or Gallery, then returns the
/// picked image copied into permanent app storage — or null if the
/// user cancelled at any point.
Future<File?> pickAndSaveImage(BuildContext context, {required String filenamePrefix}) async {
  final source = await showModalBottomSheet<ImageSource>(
    context: context,
    backgroundColor: AppColors.surface,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
    ),
    builder: (context) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const SizedBox(height: 8),
          Container(width: 36, height: 4, decoration: BoxDecoration(color: AppColors.divider, borderRadius: BorderRadius.circular(999))),
          const SizedBox(height: 12),
          ListTile(
            leading: const Icon(Icons.photo_camera_outlined, color: AppColors.primaryDark),
            title: const Text('Take a Photo'),
            onTap: () => Navigator.of(context).pop(ImageSource.camera),
          ),
          ListTile(
            leading: const Icon(Icons.photo_library_outlined, color: AppColors.primaryDark),
            title: const Text('Choose from Gallery'),
            onTap: () => Navigator.of(context).pop(ImageSource.gallery),
          ),
          const SizedBox(height: 8),
        ],
      ),
    ),
  );

  if (source == null) return null;

  final picked = await ImagePicker().pickImage(source: source, maxWidth: 1600, imageQuality: 85);
  if (picked == null) return null;

  final docsDir = await getApplicationDocumentsDirectory();
  final ext = picked.path.split('.').last;
  final filename = '${filenamePrefix}_${DateTime.now().millisecondsSinceEpoch}.$ext';
  final savedFile = await File(picked.path).copy('${docsDir.path}/$filename');
  return savedFile;
}
