// lib/widgets/dirty_guard.dart
//
// Back-button protection for dirty forms (Claude polish list).
//
// Wrap a form screen's Scaffold in [DirtyGuard] with a [hasUnsavedInput]
// callback: when the user tries to leave with unsaved input, they get a
// quiet confirm ("Discard changes?" / Stay / Discard) instead of losing
// what they typed. Clean forms pop normally with no interruption.

import 'package:flutter/material.dart';
import '../theme/app_theme.dart';
import 'editorial_shapes.dart';

class DirtyGuard extends StatelessWidget {
  const DirtyGuard({
    super.key,
    required this.hasUnsavedInput,
    required this.child,
    this.message = 'You have unsaved changes.',
  });

  /// True when leaving should ask first (e.g. any field non-empty).
  final ValueGetter<bool> hasUnsavedInput;

  final Widget child;
  final String message;

  Future<bool> _confirm(BuildContext context) async {
    // Editorial dialog, matching the logout confirm — same shape,
    // same quiet-Stay / clear-Discard pair.
    final discard = await showDialog<bool>(
      context: context,
      builder: (dialogContext) {
        AppColors.watch(dialogContext);
        final dialogTheme = Theme.of(dialogContext);
        return Dialog(
          shape: editorialShape(0, ShapeTier.card),
          backgroundColor: AppColors.surface,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(22, 22, 22, 18),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('UNSAVED CHANGES', style: AppType.eyebrow),
                const SizedBox(height: 10),
                Text('Discard changes?',
                    style: dialogTheme.textTheme.titleMedium),
                const SizedBox(height: 8),
                Text(
                  message,
                  style: dialogTheme.textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
                ),
                const SizedBox(height: 20),
                Row(
                  children: [
                    Expanded(
                      child: TextButton(
                        onPressed: () =>
                            Navigator.of(dialogContext).pop(false),
                        child: const Text('Stay'),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: ElevatedButton(
                        onPressed: () =>
                            Navigator.of(dialogContext).pop(true),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AppColors.danger,
                          foregroundColor: AppColors.textOnDark,
                        ),
                        child: const Text('Discard'),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
    return discard ?? false;
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: !hasUnsavedInput(),
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        if (!context.mounted) return;
        if (await _confirm(context) && context.mounted) {
          Navigator.of(context).pop();
        }
      },
      child: child,
    );
  }
}
