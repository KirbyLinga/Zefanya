import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/screens/landing_screen.dart';
import 'package:zefanya/theme/app_theme.dart';

void main() {
  testWidgets('editorial motion loops, pauses, and respects reduced motion', (
    tester,
  ) async {
    final navigator = GlobalKey<NavigatorState>();
    final reduced = ValueNotifier(false);
    addTearDown(reduced.dispose);
    await tester.pumpWidget(
      MaterialApp(
        navigatorKey: navigator,
        builder: (context, child) => ValueListenableBuilder(
          valueListenable: reduced,
          builder: (context, value, _) => MediaQuery(
            data: MediaQuery.of(context).copyWith(disableAnimations: value),
            child: child!,
          ),
        ),
        home: LandingScreen(
          animateEditorial: true,
          onDone: () {},
          onCreateAccount: () {},
        ),
      ),
    );
    final sweep = find.byKey(
      const ValueKey('landing-light-sweep'),
      skipOffstage: false,
    );
    Future<List<int>> pixels() async {
      final boundary = tester.renderObject<RenderRepaintBoundary>(sweep);
      final image = boundary.toImageSync(pixelRatio: .15);
      try {
        final bytes = await tester.runAsync(image.toByteData);
        return bytes!.buffer.asUint8List();
      } finally {
        image.dispose();
      }
    }

    final content = [
      for (final image in find.byType(Image).evaluate())
        find.byElementPredicate((element) => identical(element, image)),
      find.text('Zefanya'),
      find.textContaining('Distinctly local.', findRichText: true),
      find.text('Sign in'),
      find.text('Create Buyer account'),
    ];
    List<Rect> bounds() => [
      for (final element in content) tester.getRect(element),
    ];
    expect(find.byType(Image), findsNWidgets(3));
    final initialBounds = bounds();
    final start = await pixels();
    await tester.pump(const Duration(seconds: 1));
    final moving = await pixels();
    expect(moving, isNot(start));
    await tester.pump(const Duration(seconds: 12));
    expect(await pixels(), moving);
    expect(bounds(), initialBounds);

    navigator.currentState!.push<void>(
      MaterialPageRoute(builder: (_) => const Scaffold(body: Text('Cover'))),
    );
    await tester.pumpAndSettle();
    final covered = await pixels();
    await tester.pump(const Duration(seconds: 4));
    expect(await pixels(), covered);
    navigator.currentState!.pop();
    await tester.pump();
    await tester.pump(const Duration(seconds: 2));
    expect(await pixels(), isNot(covered));

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    await tester.pump();
    final paused = await pixels();
    await tester.pump(const Duration(seconds: 2));
    expect(await pixels(), paused);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    expect(await pixels(), isNot(paused));

    reduced.value = true;
    await tester.pumpAndSettle();
    final reducedFrame = await pixels();
    await tester.pump(const Duration(seconds: 3));
    expect(await pixels(), reducedFrame);
    expect(bounds(), initialBounds);
    await tester.pumpWidget(const SizedBox.shrink());
    expect(tester.takeException(), isNull);
  });

  for (final size in [
    const Size(360, 640),
    const Size(360, 740),
    const Size(411, 890),
  ]) {
    for (final brightness in Brightness.values) {
      testWidgets(
        'welcome actions are immediate at $size in ${brightness.name}',
        (tester) async {
          tester.view.physicalSize = size;
          tester.view.devicePixelRatio = 1;
          addTearDown(tester.view.reset);
          addTearDown(() => AppColors.apply(Brightness.light));
          AppColors.apply(brightness);
          var signIns = 0;
          var registrations = 0;
          Future<void> mount({
            required bool reduced,
            double scale = 1.3,
            EdgeInsets insets = const EdgeInsets.only(top: 26, bottom: 20),
          }) => tester.pumpWidget(
            MaterialApp(
              theme: brightness == Brightness.dark
                  ? AppTheme.dark
                  : AppTheme.light,
              builder: (context, child) => MediaQuery(
                data: MediaQuery.of(context).copyWith(
                  textScaler: TextScaler.linear(scale),
                  padding: insets,
                ),
                child: child!,
              ),
              home: LandingScreen(
                key: ValueKey((reduced, scale)),
                reduceMotion: reduced,
                animateEditorial: true,
                onDone: () => signIns++,
                onCreateAccount: () => registrations++,
              ),
            ),
          );

          await mount(reduced: false);
          await tester.pump(const Duration(seconds: 7));
          final signIn = find.text('Sign in').hitTestable();
          final register = find.text('Create Buyer account').hitTestable();
          expect(signIn, findsOneWidget);
          expect(register, findsOneWidget);
          await tester.tap(signIn);
          await tester.tap(signIn);
          expect(signIns, 1);
          expect(registrations, 0);

          await mount(reduced: true);
          expect(find.byType(Image), findsNWidgets(3));
          expect(signIn, findsOneWidget);
          expect(register, findsOneWidget);
          await tester.tap(register);
          expect(signIns, 1);
          expect(registrations, 1);
          expect(tester.takeException(), isNull);

          const tallInsets = EdgeInsets.fromLTRB(9, 44, 13, 34);
          await mount(reduced: true, scale: 2, insets: tallInsets);
          expect(signIn, findsOneWidget);
          expect(register, findsOneWidget);
          final signInBounds = tester.getRect(find.byType(FilledButton));
          final registerBounds = tester.getRect(find.byType(OutlinedButton));
          expect(signInBounds.height, greaterThanOrEqualTo(48));
          expect(registerBounds.height, greaterThanOrEqualTo(48));
          expect(
            registerBounds.bottom,
            lessThan(size.height - tallInsets.bottom),
          );
          final description = find.text(
            'Discover fashion, accessories and pieces for home from local shops.',
          );
          await tester.ensureVisible(description);
          await tester.pumpAndSettle();
          expect(description.hitTestable(), findsOneWidget);
          expect(
            tester.getBottomRight(description).dy,
            lessThan(signInBounds.top),
          );
          expect(
            tester.getSize(description).width,
            lessThan(size.width - tallInsets.horizontal),
          );
          expect(signIn, findsOneWidget);
          expect(register, findsOneWidget);
          expect(tester.takeException(), isNull);
        },
      );
    }
  }
}
