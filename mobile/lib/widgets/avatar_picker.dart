// lib/widgets/avatar_picker.dart
//
// One avatar control, used by Edit Profile (where the photo can change) and
// by Profile (display only, opening Edit Profile).
//
// The picker itself is deliberately thin: [MediaPicker] owns permissions,
// re-encoding, validation and storage. This widget owns the three choices a
// person actually makes (camera, gallery, remove), the initials fallback, and
// the optimistic write with rollback described below.

import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import '../services/media_picker.dart';
import '../state/auth_state.dart';
import '../theme/app_theme.dart';
import 'editorial_shapes.dart';
import 'zefanya_mark.dart';

/// Injectable pick function so widget tests can exercise the optimistic
/// update without a platform image picker.
typedef AvatarPick = Future<MediaResult?> Function(
  BuildContext context,
  ImageSource source,
);

enum _AvatarAction { camera, gallery, remove }

/// Editable avatar: tap opens Take photo / Choose from gallery / Remove.
///
/// The new path is shown immediately and then persisted. If the write fails
/// the previous path (or the initials fallback) is restored, so the screen
/// never claims a photo the next launch would not have.
class AvatarPicker extends StatefulWidget {
  const AvatarPicker({
    super.key,
    this.width = 76,
    this.height = 94,
    this.onChanged,
    this.pickImage,
  });

  final double width;
  final double height;

  /// Called after a successful change, for the form's dirty guard.
  final VoidCallback? onChanged;

  final AvatarPick? pickImage;

  @override
  State<AvatarPicker> createState() => _AvatarPickerState();
}

class _AvatarPickerState extends State<AvatarPicker> {
  bool _busy = false;

  Future<MediaResult?> _defaultPick(
    BuildContext context,
    ImageSource source,
  ) {
    return const MediaPicker().pick(
      context: context,
      filenamePrefix: 'avatar',
      source: source,
    );
  }

  Future<void> _openMenu(bool hasPhoto) async {
    if (_busy) return;
    final action = await showModalBottomSheet<_AvatarAction>(
      context: context,
      backgroundColor: AppColors.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(
          top: Radius.circular(ShapeTier.hero.symmetric),
        ),
      ),
      builder: (sheetContext) {
        AppColors.watch(sheetContext);
        return SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const SizedBox(height: AppSpacing.s8),
              Container(
                width: 36,
                height: 4,
                decoration: BoxDecoration(
                  color: AppColors.divider,
                  borderRadius: radiusOf(ShapeTier.pill),
                ),
              ),
              const SizedBox(height: AppSpacing.s12),
              ListTile(
                key: const ValueKey('avatar-take-photo'),
                leading: Icon(
                  Icons.photo_camera_outlined,
                  color: AppColors.primaryDark,
                ),
                title: const Text('Take photo'),
                onTap: () =>
                    Navigator.of(sheetContext).pop(_AvatarAction.camera),
              ),
              ListTile(
                key: const ValueKey('avatar-choose-gallery'),
                leading: Icon(
                  Icons.photo_library_outlined,
                  color: AppColors.primaryDark,
                ),
                title: const Text('Choose from gallery'),
                onTap: () =>
                    Navigator.of(sheetContext).pop(_AvatarAction.gallery),
              ),
              if (hasPhoto)
                ListTile(
                  key: const ValueKey('avatar-remove'),
                  leading: Icon(
                    Icons.delete_outline,
                    color: AppColors.danger,
                  ),
                  title: Text(
                    'Remove photo',
                    style: TextStyle(color: AppColors.danger),
                  ),
                  onTap: () =>
                      Navigator.of(sheetContext).pop(_AvatarAction.remove),
                ),
              const SizedBox(height: AppSpacing.s8),
            ],
          ),
        );
      },
    );

    if (action == null || !mounted) return;
    await _run(action);
  }

  Future<void> _run(_AvatarAction action) async {
    final auth = context.read<AuthState>();
    final previous = auth.avatarPath;

    if (action == _AvatarAction.remove) {
      auth.updateProfile(removeAvatar: true);
      widget.onChanged?.call();
      return;
    }

    setState(() => _busy = true);
    try {
      final result = await (widget.pickImage ?? _defaultPick)(
        context,
        action == _AvatarAction.camera
            ? ImageSource.camera
            : ImageSource.gallery,
      );
      if (result == null) return;

      // Optimistic: show the new photo now, then persist. The rollback below
      // is the reason this is safe rather than merely fast.
      auth.updateProfile(avatarPath: result.file.path);
      try {
        await auth.persistProfile();
      } catch (_) {
        if (previous == null) {
          auth.updateProfile(removeAvatar: true);
        } else {
          auth.updateProfile(avatarPath: previous);
        }
        rethrow;
      }
      widget.onChanged?.call();
    } catch (error) {
      if (!mounted) return;
      final message = isMediaRefusal(error)
          ? mediaProblemMessage(mediaProblemOf(error))
          : 'Could not update your photo. Try again.';
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(SnackBar(content: Text(message)));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();
    final path = auth.avatarPath;
    final hasPhoto = path != null && File(path).existsSync();

    return Semantics(
      button: true,
      label: hasPhoto ? 'Change profile photo' : 'Add profile photo',
      child: InkWell(
        onTap: () => _openMenu(hasPhoto),
        customBorder: const CircleBorder(),
        child: Stack(
          clipBehavior: Clip.none,
          children: [
            AvatarArt(
              path: hasPhoto ? path : null,
              name: auth.name,
              email: auth.email,
              width: widget.width,
              height: widget.height,
            ),
            _cameraBadge(busy: _busy),
          ],
        ),
      ),
    );
  }

  Widget _cameraBadge({required bool busy}) {
    return Positioned(
      right: -4,
      bottom: -4,
      child: Container(
        padding: const EdgeInsets.all(AppSpacing.s4),
        decoration: BoxDecoration(
          color: AppColors.surface,
          shape: BoxShape.circle,
        ),
        child: busy
            ? const ZefanyaLoader(size: 14, strokeWidth: 2.4)
            : Icon(
                Icons.camera_alt,
                size: 14,
                color: AppColors.primaryDark,
              ),
      ),
    );
  }
}

/// Display-only avatar with an edit badge. Tapping it is the caller's job, so
/// Profile can route to Edit Profile instead of opening a picker in place.
class AvatarDisplay extends StatelessWidget {
  const AvatarDisplay({
    super.key,
    required this.onTap,
    this.width = 68,
    this.height = 84,
  });

  final VoidCallback onTap;
  final double width;
  final double height;

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthState>();
    final path = auth.avatarPath;
    final hasPhoto = path != null && File(path).existsSync();

    return Semantics(
      button: true,
      label: 'Edit profile photo',
      child: InkWell(
        onTap: onTap,
        customBorder: const CircleBorder(),
        child: Stack(
          clipBehavior: Clip.none,
          children: [
            AvatarArt(
              path: hasPhoto ? path : null,
              name: auth.name,
              email: auth.email,
              width: width,
              height: height,
            ),
            Positioned(
              right: -4,
              bottom: -4,
              child: Container(
                padding: const EdgeInsets.all(AppSpacing.s4),
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  shape: BoxShape.circle,
                ),
                child: Icon(
                  Icons.edit,
                  size: 13,
                  color: AppColors.primaryDark,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The arch itself: a photo when [path] resolves to a file, otherwise the
/// person's initial. Shared so the display and editable forms cannot drift
/// apart.
class AvatarArt extends StatelessWidget {
  const AvatarArt({
    super.key,
    required this.path,
    required this.name,
    required this.email,
    required this.width,
    required this.height,
  });

  final String? path;
  final String? name;
  final String? email;
  final double width;
  final double height;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Arch(
      footRadius: 10,
      child: SizedBox(
        width: width,
        height: height,
        child: path != null
            ? Image.file(File(path!), fit: BoxFit.cover)
            : _placeholder(),
      ),
    );
  }

  Widget _placeholder() {
    final source = (name ?? email ?? '').trim();
    final initial = source.isEmpty
        ? 'Z'
        : String.fromCharCode(source.runes.first).toUpperCase();
    return Semantics(
      image: true,
      label: 'Profile photo placeholder',
      excludeSemantics: true,
      child: Container(
        color: AppColors.secondary,
        alignment: Alignment.center,
        child: Text(
          initial,
          style: AppType.masthead.copyWith(
            fontSize: 34,
            height: 1,
            color: AppColors.textPrimary,
          ),
        ),
      ),
    );
  }
}
