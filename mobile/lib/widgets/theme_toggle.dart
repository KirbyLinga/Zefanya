// lib/widgets/theme_toggle.dart
//
// The light/dark switch.
//
// A settings-shaped decision belongs with the other settings, so this
// lives in Profile's "Appearance" section rather than floating as an icon
// over the content — the app's own nav vocabulary (eyebrow + a labelled
// control) carries it.
//
// Built from SegmentedButton so it inherits the same selected/primary
// treatment as the "Login as Buyer/Courier" control and the Courier
// step selector: there is one way this app shows "picker", and this is
// not a third one.
//
// It reads ThemeState directly rather than taking a callback, because
// there is exactly one thing a theme toggle can do.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../state/theme_state.dart';

class ThemeToggle extends StatelessWidget {
  const ThemeToggle({super.key});

  @override
  Widget build(BuildContext context) {
    final themeState = context.watch<ThemeState>();

    return SizedBox(
      width: double.infinity,
      child: SegmentedButton<ThemeMode>(
        segments: const [
          ButtonSegment(
            value: ThemeMode.light,
            icon: Icon(Icons.light_mode_outlined, size: 17),
            label: Text('Light'),
          ),
          ButtonSegment(
            value: ThemeMode.dark,
            icon: Icon(Icons.dark_mode_outlined, size: 17),
            label: Text('Dark'),
          ),
        ],
        selected: {themeState.mode},
        showSelectedIcon: false,
        onSelectionChanged: (selection) {
          HapticFeedback.selectionClick();
          themeState.setMode(selection.first);
        },
      ),
    );
  }
}
