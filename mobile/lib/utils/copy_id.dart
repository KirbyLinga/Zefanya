// lib/utils/copy_id.dart
//
// Tap-to-copy for order / request IDs (Tier 1, task 4).
//
// One helper so both detail screens agree: copies [text] to the
// clipboard, fires the same light haptic the nav uses, and shows a
// short "Copied" snackbar in the existing style.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'timed_snackbar.dart';

Future<void> copyId(BuildContext context, String text) async {
  await Clipboard.setData(ClipboardData(text: text));
  HapticFeedback.selectionClick();
  if (!context.mounted) return;
  showTimedSnackBar(
    context,
    const SnackBar(content: Text('Copied')),
    duration: const Duration(seconds: 2),
  );
}
