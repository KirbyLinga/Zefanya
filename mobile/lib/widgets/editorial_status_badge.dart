import 'package:flutter/material.dart';

import '../theme/app_theme.dart';
import 'editorial_shapes.dart';

/// Keeps the status label readable over tinted surfaces in both themes.
class EditorialStatusBadge extends StatelessWidget {
  const EditorialStatusBadge({
    super.key,
    required this.label,
    required this.tint,
  });

  final String label;
  final Color tint;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: tint.withValues(alpha: .18),
        borderRadius: radiusOf(ShapeTier.pill),
      ),
      child: Text(
        label,
        style: theme.textTheme.labelMedium?.copyWith(
          color: AppPalette.of(theme.brightness).textPrimary,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}
