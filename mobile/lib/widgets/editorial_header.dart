// lib/widgets/editorial_header.dart
//
// Page-level header for every screen that isn't Home, so those pages
// stop defaulting to a plain AppBar title and instead speak in the
// same voice Home does: a tiny all-caps eyebrow paired with one big
// Playfair line.
//
// Deliberately reaches for AppType.sectionDisplay, never
// AppType.masthead — the masthead is reserved for Home's wordmark
// only (see the "one per screen, maximum" rule in app_theme.dart).
// sectionDisplay is the same style Home's own "Style Edit" section
// heading uses, so every screen in the app still only shows one
// masthead-scale element, ever, and it's Home's.

import 'package:flutter/material.dart';
import '../theme/app_theme.dart';

class EditorialHeader extends StatelessWidget {
  const EditorialHeader({
    super.key,
    required this.eyebrow,
    required this.title,
    this.trailing,
    this.padding = const EdgeInsets.fromLTRB(20, 18, 20, 4),
  });

  final String eyebrow;
  final String title;
  final Widget? trailing;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: padding,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(eyebrow, style: AppType.eyebrow),
                const SizedBox(height: 8),
                Text(title, style: AppType.sectionDisplay),
              ],
            ),
          ),
          if (trailing != null) trailing!,
        ],
      ),
    );
  }
}
