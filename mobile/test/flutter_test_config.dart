// test/flutter_test_config.dart
//
// The app ships Playfair Display and Montserrat in assets/fonts and names
// those families directly in the theme. `flutter_test` starts every test file
// with only the Ahem test font, so a golden captured before any font loading
// is a picture of rectangles. Load the bundled files once, before the suite,
// so widget assertions and goldens measure the same typography a phone does.

import 'dart:async';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

Future<void> _loadFamily(String family, List<String> assets) async {
  final loader = FontLoader(family);
  for (final asset in assets) {
    loader.addFont(rootBundle.load(asset));
  }
  await loader.load();
}

Future<void> testExecutable(FutureOr<void> Function() testMain) async {
  TestWidgetsFlutterBinding.ensureInitialized();

  await _loadFamily('PlayfairDisplay', const [
    'assets/fonts/PlayfairDisplay-Regular.ttf',
    'assets/fonts/PlayfairDisplay-Medium.ttf',
    'assets/fonts/PlayfairDisplay-SemiBold.ttf',
    'assets/fonts/PlayfairDisplay-Bold.ttf',
    'assets/fonts/PlayfairDisplay-Italic.ttf',
  ]);
  await _loadFamily('Montserrat', const [
    'assets/fonts/Montserrat-Regular.ttf',
    'assets/fonts/Montserrat-Medium.ttf',
    'assets/fonts/Montserrat-SemiBold.ttf',
    'assets/fonts/Montserrat-Bold.ttf',
  ]);

  await testMain();
}
