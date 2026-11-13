// lib/widgets/app_page_route.dart
//
// A branded alternative to MaterialPageRoute's platform-default slide.
// Fade + a small upward settle, consistent everywhere it's used —
// small, but it's the difference between "this app has a designed feel"
// and "this is whatever the OS does by default". Drop-in replacement:
// AppPageRoute(builder: (_) => Screen()) instead of
// MaterialPageRoute(builder: (_) => Screen()).

import 'package:flutter/material.dart';
import '../utils/motion.dart';

class AppPageRoute<T> extends PageRouteBuilder<T> {
  AppPageRoute({required WidgetBuilder builder})
    : super(
        transitionDuration: const Duration(milliseconds: 270),
        reverseTransitionDuration: const Duration(milliseconds: 220),
        pageBuilder: (context, animation, secondaryAnimation) =>
            builder(context),
        transitionsBuilder: (context, animation, secondaryAnimation, child) {
          if (reduceMotion(context)) return child;
          final curved = animation.drive(
            CurveTween(curve: Curves.easeOutCubic),
          );
          return FadeTransition(
            opacity: curved,
            child: SlideTransition(
              position: Tween<Offset>(
                begin: const Offset(0, 0.03),
                end: Offset.zero,
              ).animate(curved),
              child: child,
            ),
          );
        },
      );
}
