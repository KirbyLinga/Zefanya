// lib/widgets/empty_state.dart
//
// Shared empty-state widget. Uses the Arch silhouette instead of the
// generic circle+icon pattern — the arch is this app's "important
// visual moment" shape, and an empty state is the moment a screen
// most needs to feel like itself rather than like a default.
//
// Used by: Cart, Saved, Pickup dashboard, Delivery dashboard,
//           Category Products, Buyer Home (no products).

import 'package:flutter/material.dart';

import '../theme/app_theme.dart';
import 'editorial_shapes.dart';

class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.icon,
    required this.title,
    this.subtitle,
    this.action,
    this.tint,
  });

  final IconData icon;
  final String title;
  final String? subtitle;

  /// Optional button/widget below the copy.
  final Widget? action;

  /// Arch background tint. Defaults to AppColors.secondary (blush).
  final Color? tint;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final color = tint ?? AppColors.secondary;

    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 40),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Arch(
              footRadius: 14,
              child: Container(
                width: 96,
                height: 116,
                color: color.withValues(alpha: 0.55),
                alignment: Alignment.center,
                child: Icon(
                  icon,
                  size: 40,
                  color: AppColors.primaryDark.withValues(alpha: 0.7),
                ),
              ),
            ),
            const SizedBox(height: 20),
            Text(title, style: theme.textTheme.titleSmall, textAlign: TextAlign.center),
            if (subtitle != null) ...[
              const SizedBox(height: 6),
              Text(
                subtitle!,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: AppColors.textSecondary,
                  height: 1.5,
                ),
                textAlign: TextAlign.center,
              ),
            ],
            if (action != null) ...[
              const SizedBox(height: 20),
              action!,
            ],
          ],
        ),
      ),
    );
  }
}
