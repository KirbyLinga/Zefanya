// test/landing_preview_rail_test.dart
//
// Widget tests for LandingPreviewRail (Claude Round B): the timer
// advances pages, stops off-stage, and never leaks; reduced motion
// shows the end state with no auto-advance; no overflow at 360dp or
// 1.5x text scale. Bounded pumps only — never pumpAndSettle (the
// entrance controllers + image fades never settle).

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:zefanya/screens/landing_preview.dart';
import 'package:zefanya/screens/landing_preview_rail.dart';
import 'package:zefanya/theme/app_theme.dart';

Future<void> _pumpRail(
  WidgetTester tester, {
  Size surface = const Size(390, 844),
  double textScale = 1.0,
  bool reduceMotion = false,
  bool isActive = true,
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
          body: LandingPreviewRail(
            isActive: isActive,
            reduceMotion: reduceMotion,
          ),
        ),
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 100));
}

void main() {
  testWidgets('renders the first page with dots', (tester) async {
    await _pumpRail(tester);

    // PageView builds lazily: only page 0 exists before any swipe.
    expect(find.text('SHOP'), findsOneWidget);
    expect(find.byType(LandingDots), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('swipe reveals the other pages', (tester) async {
    await _pumpRail(tester);

    await tester.fling(find.byType(PageView), const Offset(-300, 0), 800);
    await tester.pump(const Duration(milliseconds: 100));
    expect(find.text('TRACK ORDERS'), findsWidgets);

    await tester.fling(find.byType(PageView), const Offset(-300, 0), 800);
    await tester.pump(const Duration(milliseconds: 100));
    expect(find.text('COURIER & SAVINGS'), findsWidgets);
    expect(tester.takeException(), isNull);
  });

  testWidgets('auto-advance turns the page after 6s', (tester) async {
    await _pumpRail(tester);

    Finder scrollableFinder = find.descendant(
      of: find.byType(PageView),
      matching: find.byType(Scrollable),
    );
    double pixels() =>
        tester.state<ScrollableState>(scrollableFinder).position.pixels;
    expect(pixels(), 0.0);

    // Flush the entrance delays first (40/80ms), then step to the
    // 6s interval in bounded pumps: the periodic timer fires and
    // the 450ms page flight completes.
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pump(kPreviewAdvanceInterval);
    await tester.pump(const Duration(milliseconds: 500));
    expect(pixels(), greaterThan(0.0));

    // Park off-stage so the periodic timer is cancelled: nothing
    // pending at teardown.
    await tester.pumpWidget(
      const MediaQuery(
        data: MediaQueryData(),
        child: MaterialApp(home: Scaffold(body: SizedBox.shrink())),
      ),
    );
    await tester.pump(const Duration(milliseconds: 100));
    expect(tester.takeException(), isNull);
  });

  testWidgets('timer stops when off-stage, restarts on return',
      (tester) async {
    await _pumpRail(tester);

    double pixels() => tester
        .state<ScrollableState>(find.descendant(
          of: find.byType(PageView),
          matching: find.byType(Scrollable),
        ))
        .position
        .pixels;

    // Rebuild off-stage: the timer is cancelled, so two full
    // intervals move nothing.
    await tester.pumpWidget(
      MediaQuery(
        data: const MediaQueryData(disableAnimations: true),
        child: MaterialApp(
          theme: AppTheme.light,
          home: const Scaffold(
            body: LandingPreviewRail(isActive: false),
          ),
        ),
      ),
    );
    // Theme ext resolved via MaterialApp theme above.
    await tester.pump(kPreviewAdvanceInterval * 2);
    expect(pixels(), 0.0);

    // Back on-stage: the timer restarts and the page turns.
    await tester.pumpWidget(
      MediaQuery(
        data: const MediaQueryData(disableAnimations: true),
        child: MaterialApp(
          theme: AppTheme.light,
          home: const Scaffold(
            body: LandingPreviewRail(isActive: true),
          ),
        ),
      ),
    );
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pump(kPreviewAdvanceInterval);
    await tester.pump(const Duration(milliseconds: 500));
    expect(
      tester
          .state<ScrollableState>(find.descendant(
            of: find.byType(PageView),
            matching: find.byType(Scrollable),
          ))
          .position
          .pixels,
      greaterThan(0.0),
    );

    // Park off-stage again: timer cancelled, clean teardown.
    await tester.pumpWidget(
      const MediaQuery(
        data: MediaQueryData(),
        child: MaterialApp(home: Scaffold(body: SizedBox.shrink())),
      ),
    );
    await tester.pump(const Duration(milliseconds: 100));
    expect(tester.takeException(), isNull);
  });

  testWidgets('reduced motion: end state, no auto-advance',
      (tester) async {
    await _pumpRail(tester, reduceMotion: true);

    // First page visible immediately (entrance jumped to 1).
    expect(find.text('SHOP'), findsWidgets);

    final position = tester
        .state<ScrollableState>(find.descendant(
          of: find.byType(PageView),
          matching: find.byType(Scrollable),
        ))
        .position;
    await tester.pump(kPreviewAdvanceInterval * 2);
    expect(position.pixels, 0.0);
    expect(tester.takeException(), isNull);
  });

  testWidgets('no overflow at 360dp', (tester) async {
    await _pumpRail(tester, surface: const Size(360, 740));
    expect(find.text('SHOP'), findsWidgets);
    expect(tester.takeException(), isNull);
  });

  testWidgets('no overflow at 1.5x text scale', (tester) async {
    await _pumpRail(tester, textScale: 1.5);
    expect(find.text('SHOP'), findsWidgets);
    expect(tester.takeException(), isNull);
  });
}
