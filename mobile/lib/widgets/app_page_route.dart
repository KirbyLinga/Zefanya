// lib/widgets/app_page_route.dart
//
// A branded alternative to MaterialPageRoute's platform-default slide.
// Fade + a small upward settle, consistent everywhere it's used —
// small, but it's the difference between "this app has a designed feel"
// and "this is whatever the OS does by default". Drop-in replacement:
// AppPageRoute(builder: (_) => Screen()) instead of
// MaterialPageRoute(builder: (_) => Screen()).

import 'package:flutter/material.dart';

class AppPageRoute<T> extends PageRouteBuilder<T> {
  AppPageRoute({required WidgetBuilder builder})
      : super(
          transitionDuration: const Duration(milliseconds: 320),
          reverseTransitionDuration: const Duration(milliseconds: 260),
          pageBuilder: (context, animation, secondaryAnimation) => builder(context),
          transitionsBuilder: (context, animation, secondaryAnimation, child) {
            final curved = CurvedAnimation(parent: animation, curve: Curves.easeOutCubic);
            // Outgoing screen drifts slightly down as the new one arrives,
            // so push and pop feel like two sides of the same motion.
            final exitCurved = CurvedAnimation(parent: secondaryAnimation, curve: Curves.easeInCubic);
            return FadeTransition(
              opacity: curved,
              child: SlideTransition(
                position: Tween<Offset>(begin: const Offset(0, 0.03), end: Offset.zero).animate(curved),
                child: FadeTransition(
                  opacity: Tween<double>(begin: 1.0, end: 0.92).animate(exitCurved),
                  child: SlideTransition(
                    position: Tween<Offset>(begin: Offset.zero, end: const Offset(0, 0.015)).animate(exitCurved),
                    child: child,
                  ),
                ),
              ),
            );
          },
        );
}
