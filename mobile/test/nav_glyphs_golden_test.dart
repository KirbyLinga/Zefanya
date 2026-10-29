// test/nav_glyphs_golden_test.dart
//
// The nav glyphs, rendered — so that hand-authored path data gets *looked at*
// instead of trusted.
//
// Why this exists: these are eight icons drawn as raw `Path` data with no
// design tool behind them. Path data that's wrong doesn't throw; it just
// looks like a blob at 20px on a real device, and nothing in a normal test
// run would ever say so. The courier set in particular was written blind and
// two of its three glyphs failed on first render (the parcel read as a
// window, the coin stack read as a can) — see the notes in nav_icons.dart.
//
// So: two goldens, light and dark, showing every glyph at nav size, 2x and
// 2.7x. They are *assertions*, deliberately — if the artwork changes, this
// fails and somebody has to look at the diff:
//
//     flutter test test/nav_glyphs_golden_test.dart --update-goldens
//
// then open test/nav_glyphs_light.png and test/nav_glyphs_dark.png and check
// the glyph still reads. (Goldens also fail on a Flutter/Skia upgrade that
// changes rasterisation; that's the same remedy — regenerate, then look.)

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/widgets/nav_icons.dart';

/// One row per glyph: nav size, 2x, and just under 3x.
Widget _sheet(Color ground, Color ink) {
  return RepaintBoundary(
    child: ColoredBox(
      color: ground,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (final glyph in NavGlyph.values)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(
                  children: [
                    NavIcon(glyph: glyph, size: 20, color: ink),
                    const SizedBox(width: 22),
                    NavIcon(glyph: glyph, size: 40, color: ink),
                    const SizedBox(width: 22),
                    NavIcon(glyph: glyph, size: 54, color: ink),
                  ],
                ),
              ),
          ],
        ),
      ),
    ),
  );
}

void main() {
  testWidgets('nav glyphs — light', (tester) async {
    await tester.pumpWidget(Directionality(
      textDirection: TextDirection.ltr,
      // The real palette values, so the goldens also show the contrast the
      // nav actually gets: ink-on-paper and warm-white-on-ink.
      child: _sheet(const Color(0xFFFFFFFF), const Color(0xFF2E2A2A)),
    ));
    await expectLater(find.byType(RepaintBoundary).first,
        matchesGoldenFile('nav_glyphs_light.png'));
  });

  testWidgets('nav glyphs — dark', (tester) async {
    await tester.pumpWidget(Directionality(
      textDirection: TextDirection.ltr,
      child: _sheet(const Color(0xFF2C2018), const Color(0xFFF5EDE8)),
    ));
    await expectLater(find.byType(RepaintBoundary).first,
        matchesGoldenFile('nav_glyphs_dark.png'));
  });
}
