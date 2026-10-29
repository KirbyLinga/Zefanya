// lib/state/theme_state.dart
//
// Which mode the app is in — light or dark — and the one place that
// decision lives.
//
// Context: this app shipped as a light app, was converted to a dark one
// in a single pass with no way back, and now supports both. The choice is
// persisted the same way every other piece of state in this app is (see
// AuthState, CartState, WishlistState), so it survives a restart instead
// of resetting on every cold start.
//
// ## Why light is the default (a product decision, recorded)
//
// Light is the default because roughly half this app is a *courier tool
// used outdoors in daylight*, and dark UI on a phone in sunlight is
// materially harder to read — the ground has lower luminance, so
// reflections and glare eat the type. The role that uses this app most
// often, for the longest stretches, and in the worst light is the one
// that would pay for a dark default. Light-as-default also happens to be
// the app's original identity, but that's a coincidence, not the reason.
//
// Dark mode is a real choice a user can make, and it is deliberately NOT
// forced per role: overriding someone's explicit preference because of
// which account they're on would be the app deciding it knows better.
// Making the *default* right is the fix; making dark unavailable to
// couriers is not.

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

class ThemeState extends ChangeNotifier {
  static const _storageKey = 'themeMode';

  /// Held as [ThemeMode] rather than a bool so a third state ("follow the
  /// system") is a UI change later, not a data-shape change.
  ThemeMode _mode = ThemeMode.light;
  ThemeMode get mode => _mode;

  bool get isDark => _mode == ThemeMode.dark;

  Brightness get brightness => isDark ? Brightness.dark : Brightness.light;

  /// Call once before runApp (see main.dart) to restore the saved choice.
  Future<void> load() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_storageKey);
      if (raw == null) return;
      _mode = raw == 'dark' ? ThemeMode.dark : ThemeMode.light;
      notifyListeners();
    } catch (_) {
      // Unreadable or unrecognised value — stay on the default rather
      // than crash on a preference.
      _mode = ThemeMode.light;
    }
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_storageKey, isDark ? 'dark' : 'light');
  }

  void setMode(ThemeMode mode) {
    if (mode == _mode) return;
    _mode = mode;
    notifyListeners();
    _save();
  }

  /// Flips to the other mode. Used by the Appearance control.
  void toggle() => setMode(isDark ? ThemeMode.light : ThemeMode.dark);
}
