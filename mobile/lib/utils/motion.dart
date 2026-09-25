// lib/utils/motion.dart
//
// One place to ask "should this animate?".
//
// Until the landing screen, nothing in this codebase checked
// MediaQuery.disableAnimations — not StaggeredReveal, not ZefanyaLoader,
// not the cart flight, not the typing indicator. That setting is how a
// user with vestibular sensitivity, or anyone who just finds motion
// distracting, tells the OS to stop. Ignoring it isn't a missing
// polish item, it's the app overriding an accessibility preference the
// person deliberately set.
//
// Usage rule, so this stays consistent as more motion gets added:
// reduced motion means the animation's END STATE, immediately. Not a
// faster version, not a fade instead of a slide, and never nothing at
// all — a StaggeredReveal that respects the setting by staying
// invisible has turned an accessibility preference into a blank screen.

import 'package:flutter/material.dart';

/// True when the platform asks for reduced/disabled animation.
///
/// Uses the `maybe` variant so a widget built outside a MediaQuery
/// (tests, some overlay contexts) degrades to "animate normally"
/// instead of throwing.
bool reduceMotion(BuildContext context) =>
    MediaQuery.maybeDisableAnimationsOf(context) ?? false;
