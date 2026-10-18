// test/landing_screen_test.dart
//
// Two-beat landing (Claude restructure): Beat 1 shows brand only
// (no BEGIN, no rail); a tap or the 3s timer reaches Beat 2; no
// timer leaks on dispose in either beat. Bounded pumps only — never
// pumpAndSettle (backdrop loop + rail timers never settle).

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:zefanya/screens/landing_preview_rail.dart';
import 'package:zefanya/screens/landing_screen.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/widgets/zefanya_mark.dart';

Future<void> _pumpLanding(
  WidgetTester tester, {
  Size surface = const Size(390, 844),
  double textScale = 1.0,
  bool reduceMotion = false,
  required VoidCallback onDone,
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = surface;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MediaQuery(
      data: MediaQueryData(
        disableAnimations: reduceMotion,
        textScaler: TextScaler.linear(textScale),
      ),
      child: MaterialApp(
        theme: AppTheme.light,
        home: Scaffold(
          body: LandingScreen(
            onDone: onDone,
            reduceMotion: reduceMotion,
          ),
        ),
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 100));
}

void main() {
  testWidgets('Beat 1 shows brand only: no BEGIN, no rail, no done',
      (tester) async {
    var done = false;
    await _pumpLanding(tester, onDone: () => done = true);

    expect(find.text('Zefanya'), findsOneWidget);
    expect(find.text('BUY. SELL. DELIVER.'), findsOneWidget);
    expect(find.text('BEGIN'), findsNothing);
    expect(find.byType(LandingPreviewRail), findsNothing);
    expect(done, isFalse);
    expect(tester.takeException(), isNull);
  });

  testWidgets('hint fades in after 1.2s', (tester) async {
    await _pumpLanding(tester, onDone: () {});

    // Hint timer hasn't fired yet at 100ms: opacity 0.
    AnimatedOpacity hint() => tester.widget<AnimatedOpacity>(
          find.ancestor(
            of: find.text('Tap to continue'),
            matching: find.byType(AnimatedOpacity),
          ),
        );
    expect(hint().opacity, 0.0);

    await tester.pump(const Duration(milliseconds: 1200));
    expect(hint().opacity, moreOrLessEquals(0.7, epsilon: 0.05));
    expect(tester.takeException(), isNull);
  });

  testWidgets('a tap reaches Beat 2 (rail + BEGIN, no onDone)',
      (tester) async {
    var done = false;
    await _pumpLanding(tester, onDone: () => done = true);

    await tester.tap(find.text('Zefanya'));
    await tester.pump(const Duration(milliseconds: 400));

    expect(find.byType(LandingPreviewRail), findsOneWidget);
    expect(find.text('BEGIN'), findsOneWidget);
    // Tap advanced the beat — it must NOT complete the screen.
    expect(done, isFalse);
    expect(tester.takeException(), isNull);

    // Park off-stage: the rail's 6s loop is cancelled, clean teardown.
    await tester.pumpWidget(
      const MediaQuery(
        data: MediaQueryData(),
        child: MaterialApp(home: Scaffold(body: SizedBox.shrink())),
      ),
    );
    await tester.pump(const Duration(milliseconds: 100));
    expect(tester.takeException(), isNull);
  });

  testWidgets('the 3s timer reaches Beat 2', (tester) async {
    var done = false;
    await _pumpLanding(tester, onDone: () => done = true);

    expect(find.byType(LandingPreviewRail), findsNothing);
    await tester.pump(const Duration(seconds: 3));
    await tester.pump(const Duration(milliseconds: 100));
    expect(find.byType(LandingPreviewRail), findsOneWidget);
    expect(find.text('BEGIN'), findsOneWidget);
    expect(done, isFalse);
    expect(tester.takeException(), isNull);

    // Park off-stage: the rail's 6s loop is cancelled, clean teardown.
    await tester.pumpWidget(
      const MediaQuery(
        data: MediaQueryData(),
        child: MaterialApp(home: Scaffold(body: SizedBox.shrink())),
      ),
    );
    await tester.pump(const Duration(milliseconds: 100));
    expect(tester.takeException(), isNull);
  });

  testWidgets('BEGIN still completes exactly once', (tester) async {
    var calls = 0;
    await _pumpLanding(tester, onDone: () => calls++);

    await tester.tap(find.text('Zefanya'));
    // Fake-async ticker rule (measured, twice): a ticker started
    // between frames measures from the NEXT pumped frame, not from
    // the forward() call. So: one bare pump to plant the start
    // timestamp, then elapse past the 700ms hand-off — the frame in
    // that second pump ticks it to completion and enables BEGIN.
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 2000));
    await tester.tap(find.text('BEGIN'));
    await tester.pump(const Duration(milliseconds: 100));
    // Second tap after the guard latched — still exactly one onDone.
    await tester.tap(find.text('BEGIN'));
    await tester.pump(const Duration(milliseconds: 100));
    expect(calls, 1);
    expect(tester.takeException(), isNull);

    // Park off-stage: the rail's 6s loop is cancelled, clean teardown.
    await tester.pumpWidget(
      const MediaQuery(
        data: MediaQueryData(),
        child: MaterialApp(home: Scaffold(body: SizedBox.shrink())),
      ),
    );
    await tester.pump(const Duration(milliseconds: 100));
    expect(tester.takeException(), isNull);
  });

  testWidgets('no timer leak on dispose from Beat 1', (tester) async {
    var done = false;
    await _pumpLanding(tester, onDone: () => done = true);

    // Unmount mid-Beat-1 (both timers pending): dispose must cancel
    // them — pump past both deadlines, expect silence.
    await tester.pumpWidget(
      const MediaQuery(
        data: MediaQueryData(),
        child: MaterialApp(home: Scaffold(body: SizedBox.shrink())),
      ),
    );
    await tester.pump(const Duration(seconds: 10));
    expect(done, isFalse);
    expect(tester.takeException(), isNull);
  });

  testWidgets('no timer leak on dispose from Beat 2', (tester) async {
    var done = false;
    await _pumpLanding(tester, onDone: () => done = true);

    await tester.tap(find.text('Zefanya'));
    await tester.pump(const Duration(milliseconds: 100));
    expect(find.byType(LandingPreviewRail), findsOneWidget);

    // Unmount with the rail's 6s loop running: everything cancelled.
    await tester.pumpWidget(
      const MediaQuery(
        data: MediaQueryData(),
        child: MaterialApp(home: Scaffold(body: SizedBox.shrink())),
      ),
    );
    await tester.pump(const Duration(seconds: 20));
    expect(done, isFalse);
    expect(tester.takeException(), isNull);
  });

  testWidgets('reduced motion lands on the Beat 2 end state',
      (tester) async {
    var done = false;
    await _pumpLanding(tester, onDone: () => done = true, reduceMotion: true);

    // Straight to Beat 2, no timers: rail + BEGIN visible at once.
    expect(find.byType(LandingPreviewRail), findsOneWidget);
    expect(find.text('BEGIN'), findsOneWidget);
    // 20s of fake time: nothing advances (no rail auto-advance).
    await tester.pump(const Duration(seconds: 20));
    expect(done, isFalse);
    expect(tester.takeException(), isNull);
  });

  testWidgets('no overflow at 360dp, both beats fit', (tester) async {
    await _pumpLanding(
      tester,
      onDone: () {},
      surface: const Size(360, 740),
    );

    // Beat 1 fits.
    expect(find.text('Zefanya'), findsOneWidget);

    // Beat 2 fits too (rail + BEGIN on a short screen).
    await tester.tap(find.text('Zefanya'));
    await tester.pump(const Duration(milliseconds: 800));
    expect(find.byType(LandingPreviewRail), findsOneWidget);
    expect(find.text('BEGIN'), findsOneWidget);
    expect(tester.takeException(), isNull);

    // Park off-stage: the rail's 6s loop is cancelled, clean teardown.
    await tester.pumpWidget(
      const MediaQuery(
        data: MediaQueryData(),
        child: MaterialApp(home: Scaffold(body: SizedBox.shrink())),
      ),
    );
    await tester.pump(const Duration(milliseconds: 100));
    expect(tester.takeException(), isNull);
  });

  testWidgets('lockup morphs: size and position lerp across the hand-off',
      (tester) async {
    // One continuous motion, not a crossfade: the SAME mark + wordmark
    // scale down and travel up on the 700ms hand-off. Sample the
    // lockup at hand-off start (Beat 1 geometry) and end (Beat 2
    // geometry) and assert every number moved the right way.
    var done = false;
    await _pumpLanding(tester, onDone: () => done = true);

    Size markSize() =>
        tester.getSize(find.byType(ZefanyaMarkTrace).first);
    double wordSize() => (tester.widget<Text>(find.text('Zefanya'))
            .style
            ?.fontSize ??
        0);
    double markTop() =>
        tester.getTopLeft(find.byType(ZefanyaMarkTrace).first).dy;

    // Beat 1 resting: large lockup, centred. Sampled at 2s —
    // before the 3s auto-transition fires, so the hand-off hasn't
    // started and every number is still Beat 1 geometry.
    await tester.pump(const Duration(seconds: 2));
    final markStart = markSize().width;
    final wordStart = wordSize();
    final topStart = markTop();
    expect(markStart, moreOrLessEquals(128, epsilon: 1));
    expect(wordStart, moreOrLessEquals(72, epsilon: 1));

    // Hand-off end: well past the 3s timer + 700ms motion. Same
    // ticker rule as the BEGIN test: the 3s timer fires mid-elapse,
    // so plant a frame (ticker start), then elapse past the motion —
    // the frame in the last pump ticks it to completion.
    await tester.pump(const Duration(seconds: 3));
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    final markEnd = markSize().width;
    final wordEnd = wordSize();
    final topEnd = markTop();
    expect(markEnd, moreOrLessEquals(64, epsilon: 1));
    expect(wordEnd, moreOrLessEquals(44, epsilon: 1));
    expect(topEnd, lessThan(topStart - 50));

    // Rail + BEGIN revealed after the settle; nothing completed.
    expect(find.byType(LandingPreviewRail), findsOneWidget);
    expect(find.text('BEGIN'), findsOneWidget);
    expect(done, isFalse);

    // Park off-stage: the rail's 6s loop is cancelled, clean teardown.
    await tester.pumpWidget(
      const MediaQuery(
        data: MediaQueryData(),
        child: MaterialApp(home: Scaffold(body: SizedBox.shrink())),
      ),
    );
    await tester.pump(const Duration(milliseconds: 100));
    expect(tester.takeException(), isNull);
  });

  testWidgets('no overflow at 1.3x text scale, both beats fit',
      (tester) async {
    // 1.3x, not 1.5x: main.dart clamps the app's textScaler to
    // [0.85, 1.3], so 1.5x can never render on device — testing the
    // real ceiling instead of a configuration the app forbids.
    await _pumpLanding(
      tester,
      onDone: () {},
      textScale: 1.3,
    );

    expect(find.text('Zefanya'), findsOneWidget);

    await tester.tap(find.text('Zefanya'));
    await tester.pump(const Duration(milliseconds: 800));
    expect(find.byType(LandingPreviewRail), findsOneWidget);
    expect(find.text('BEGIN'), findsOneWidget);
    expect(tester.takeException(), isNull);

    // Park off-stage: the rail's 6s loop is cancelled, clean teardown.
    await tester.pumpWidget(
      const MediaQuery(
        data: MediaQueryData(),
        child: MaterialApp(home: Scaffold(body: SizedBox.shrink())),
      ),
    );
    await tester.pump(const Duration(milliseconds: 100));
    expect(tester.takeException(), isNull);
  });
}
