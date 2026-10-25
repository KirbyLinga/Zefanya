// test/delivery_completion_view_test.dart
//
// The courier's completion screen, rendered and stress-tested.
//
// Two kinds of check, because they catch different things:
//
//  1. Goldens (light + dark, both outcomes) — so the one screen that is
//     entirely new UI gets *looked at* rather than assumed. Update with
//     `flutter test test/delivery_completion_view_test.dart --update-goldens`
//     and then actually open the PNGs. The goldens render each outcome at
//     phone size (380x560) instead of the 800x600 test canvas, because that
//     is the width the layout actually has to survive.
//
//  2. Overflow tests at a short viewport with the app's *maximum* system
//     text scale (1.3x — see the MediaQuery clamp in main.dart). This screen
//     is the longest fixed-height stack in the courier flow, so it is the
//     one screen that would overflow, and a layout regression here is
//     invisible in code review and loud on a device. That's why the body is
//     scrollable rather than a centred Column.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:zefanya/screens/courier/widgets/delivery_completion_view.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/widgets/success_flourish.dart';

DeliveryCompletionView _view({
  required bool returned,
  bool celebrate = false,
}) => DeliveryCompletionView(
  fee: 120,
  todayTotal: 480,
  todayCount: 4,
  returned: returned,
  celebrate: celebrate,
  onDone: () {},
);

Future<void> _pump(
  WidgetTester tester, {
  required Brightness brightness,
  // A builder, not a widget: `child: _sheet()` would be evaluated as an
  // argument *before* the palette is applied below, so anything that reads
  // AppColors while constructing it (the sheet's background) would capture
  // the previous mode while the widgets built later got the new one. That
  // exact bug produced a "dark" golden with a light background.
  required Widget Function() child,
  Size size = const Size(380, 560),
  double textScale = 1.0,
}) async {
  AppColors.apply(brightness);
  GoogleFonts.config.allowRuntimeFetching = false;

  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: brightness == Brightness.dark ? AppTheme.dark : AppTheme.light,
      home: MediaQuery(
        data: MediaQueryData(
          size: size,
          textScaler: TextScaler.linear(textScale),
        ),
        child: child(),
      ),
    ),
  );
  await tester.pump();
}

Widget _sheet() => RepaintBoundary(
  key: const ValueKey('sheet'),
  child: ColoredBox(
    color: AppColors.background,
    child: Row(
      children: [
        SizedBox(width: 380, height: 560, child: _view(returned: false)),
        SizedBox(width: 380, height: 560, child: _view(returned: true)),
      ],
    ),
  ),
);

void main() {
  testWidgets('completion screen — light', (tester) async {
    await _pump(
      tester,
      brightness: Brightness.light,
      child: _sheet,
      size: const Size(760, 560),
    );
    await expectLater(
      find.byKey(const ValueKey('sheet')),
      matchesGoldenFile('completion_light.png'),
    );
  });

  testWidgets('completion screen — dark', (tester) async {
    await _pump(
      tester,
      brightness: Brightness.dark,
      child: _sheet,
      size: const Size(760, 560),
    );
    await expectLater(
      find.byKey(const ValueKey('sheet')),
      matchesGoldenFile('completion_dark.png'),
    );
  });

  testWidgets('does not overflow on a short screen at 1.3x text', (
    tester,
  ) async {
    // Landscape-ish height, maximum supported text scale: the worst case the
    // app clamps itself to.
    await _pump(
      tester,
      brightness: Brightness.light,
      child: () => _view(returned: false),
      size: const Size(360, 300),
      textScale: 1.3,
    );
    expect(tester.takeException(), isNull);

    // Same, for the return copy, which is a longer sentence.
    await _pump(
      tester,
      brightness: Brightness.light,
      child: () => _view(returned: true),
      size: const Size(360, 300),
      textScale: 1.3,
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'the celebrating path uses the flourish, the fast path does not',
    (tester) async {
      await _pump(
        tester,
        brightness: Brightness.light,
        child: () => _view(returned: false),
      );
      expect(find.byType(SuccessFlourish), findsNothing);

      await _pump(
        tester,
        brightness: Brightness.light,
        child: () => _view(returned: false, celebrate: true),
      );
      expect(find.byType(SuccessFlourish), findsOneWidget);
    },
  );

  testWidgets('the fee and the day total are both stated', (tester) async {
    await _pump(
      tester,
      brightness: Brightness.light,
      child: () => _view(returned: false),
    );

    expect(find.textContaining('120'), findsOneWidget);
    expect(find.textContaining('480'), findsOneWidget);
    expect(find.text('Delivered'), findsOneWidget);
  });

  testWidgets('a return never claims the fee was earned', (tester) async {
    await _pump(
      tester,
      brightness: Brightness.light,
      child: () => _view(returned: true),
    );

    expect(find.text('Returned to seller'), findsOneWidget);
    expect(find.textContaining('added to your earnings'), findsNothing);
  });
}
