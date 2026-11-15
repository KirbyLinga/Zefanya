// test/motion_primitives_test.dart
//
// Unit and widget tests for the motion primitives:
//   - EaseInFrame
//   - FadeThroughIndexedStack
//   - CloudTransition

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';

import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/widgets/motion/cloud_transition.dart';
import 'package:zefanya/widgets/motion/ease_in_frame.dart';
import 'package:zefanya/widgets/motion/fade_through.dart';

void main() {
  setUp(() {
    AppColors.apply(Brightness.light);
    GoogleFonts.config.allowRuntimeFetching = false;
  });

  group('EaseInFrame', () {
    testWidgets('renders child and completes entrance', (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: EaseInFrame(
              index: 0,
              child: Text('Entering text'),
            ),
          ),
        ),
      );

      // Frame 0: starting entrance
      await tester.pump();
      expect(find.text('Entering text'), findsOneWidget);

      // Advance through duration
      await tester.pump(const Duration(milliseconds: 350));
      expect(find.text('Entering text'), findsOneWidget);

      // Clean unmount
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(milliseconds: 50));
    });

    testWidgets('stagger delay respects index', (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: Column(
              children: [
                EaseInFrame(index: 0, child: Text('Item 0')),
                EaseInFrame(index: 2, child: Text('Item 2')),
              ],
            ),
          ),
        ),
      );

      await tester.pump();
      // At 20ms: Item 0 is animating, Item 2 is waiting for 80ms stagger
      await tester.pump(const Duration(milliseconds: 20));
      // At 100ms: Item 2 has started animating
      await tester.pump(const Duration(milliseconds: 100));
      // At 450ms: both have settled
      await tester.pump(const Duration(milliseconds: 350));

      expect(find.text('Item 0'), findsOneWidget);
      expect(find.text('Item 2'), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(milliseconds: 50));
    });

    testWidgets('reduced motion shows end state immediately without timer',
        (tester) async {
      await tester.pumpWidget(
        const MediaQuery(
          data: MediaQueryData(disableAnimations: true),
          child: MaterialApp(
            home: Scaffold(
              body: EaseInFrame(
                index: 3,
                child: Text('Instant Item'),
              ),
            ),
          ),
        ),
      );

      await tester.pump();
      expect(find.text('Instant Item'), findsOneWidget);

      // Unmounting immediately has zero pending timers
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(milliseconds: 50));
    });

    testWidgets('disposing while delayed cancels timer cleanly',
        (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: EaseInFrame(
              index: 5, // 200ms delay
              child: Text('Delayed Item'),
            ),
          ),
        ),
      );

      await tester.pump(const Duration(milliseconds: 50)); // disposed before 200ms
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(milliseconds: 50));
      // No pending timer exception thrown
      expect(tester.takeException(), isNull);
    });
  });

  group('FadeThroughIndexedStack', () {
    testWidgets('swaps tabs with smooth fade and keeps states mounted',
        (tester) async {
      int activeTab = 0;

      await tester.pumpWidget(
        StatefulBuilder(
          builder: (context, setState) {
            return MaterialApp(
              home: Scaffold(
                body: FadeThroughIndexedStack(
                  index: activeTab,
                  children: const [
                    Text('Tab 0 Content'),
                    Text('Tab 1 Content'),
                  ],
                ),
                floatingActionButton: FloatingActionButton(
                  onPressed: () => setState(() => activeTab = 1),
                ),
              ),
            );
          },
        ),
      );

      await tester.pump();
      expect(find.text('Tab 0 Content'), findsOneWidget);

      // Tap to switch to Tab 1
      await tester.tap(find.byType(FloatingActionButton));
      await tester.pump();

      // Halfway through transition
      await tester.pump(const Duration(milliseconds: 100));
      // Fully settled
      await tester.pump(const Duration(milliseconds: 120));

      expect(find.text('Tab 1 Content'), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(milliseconds: 50));
    });

    testWidgets('reduced motion swaps instantly without animation',
        (tester) async {
      int activeTab = 0;

      await tester.pumpWidget(
        MediaQuery(
          data: const MediaQueryData(disableAnimations: true),
          child: StatefulBuilder(
            builder: (context, setState) {
              return MaterialApp(
                home: Scaffold(
                  body: FadeThroughIndexedStack(
                    index: activeTab,
                    children: const [
                      Text('Tab 0 Content'),
                      Text('Tab 1 Content'),
                    ],
                  ),
                  floatingActionButton: FloatingActionButton(
                    onPressed: () => setState(() => activeTab = 1),
                  ),
                ),
              );
            },
          ),
        ),
      );

      await tester.pump();
      expect(find.text('Tab 0 Content'), findsOneWidget);

      await tester.tap(find.byType(FloatingActionButton));
      await tester.pump();

      // Immediate swap under reduced motion
      expect(find.text('Tab 1 Content'), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(milliseconds: 50));
    });
  });

  group('CloudTransition', () {
    testWidgets('calls swap exactly once and completes transition',
        (tester) async {
      int swapCallCount = 0;
      bool completed = false;

      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (context) {
              return Scaffold(
                body: Center(
                  child: ElevatedButton(
                    onPressed: () async {
                      await CloudTransition.run(
                        context,
                        swap: () {
                          swapCallCount++;
                        },
                      );
                      completed = true;
                    },
                    child: const Text('Start Cloud'),
                  ),
                ),
              );
            },
          ),
        ),
      );

      await tester.pump();
      await tester.tap(find.text('Start Cloud'));
      await tester.pump();

      // At 100ms: cloud entering, swap not called yet
      await tester.pump(const Duration(milliseconds: 100));
      expect(swapCallCount, 0);

      // At 350ms: apex reached, swap called
      await tester.pump(const Duration(milliseconds: 250));
      expect(swapCallCount, 1);

      // At 750ms: exit complete
      await tester.pump(const Duration(milliseconds: 400));
      expect(swapCallCount, 1);
      expect(completed, isTrue);

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(milliseconds: 50));
      expect(tester.takeException(), isNull);
    });

    testWidgets('reduced motion runs 120ms cross-fade and calls swap',
        (tester) async {
      int swapCallCount = 0;
      bool completed = false;

      await tester.pumpWidget(
        MediaQuery(
          data: const MediaQueryData(disableAnimations: true),
          child: MaterialApp(
            home: Builder(
              builder: (context) {
                return Scaffold(
                  body: Center(
                    child: ElevatedButton(
                      onPressed: () async {
                        await CloudTransition.run(
                          context,
                          swap: () {
                            swapCallCount++;
                          },
                        );
                        completed = true;
                      },
                      child: const Text('Start Cloud'),
                    ),
                  ),
                );
              },
            ),
          ),
        ),
      );

      await tester.pump();
      await tester.tap(find.text('Start Cloud'));
      await tester.pump();

      // At 70ms: swap triggered
      await tester.pump(const Duration(milliseconds: 70));
      expect(swapCallCount, 1);

      // At 130ms: completed
      await tester.pump(const Duration(milliseconds: 60));
      expect(completed, isTrue);

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(milliseconds: 50));
      expect(tester.takeException(), isNull);
    });

    testWidgets('hard timeout forces cleanup if swap throws or hangs',
        (tester) async {
      bool completed = false;

      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (context) {
              return Scaffold(
                body: Center(
                  child: ElevatedButton(
                    onPressed: () async {
                      await CloudTransition.run(
                        context,
                        swap: () {
                          throw Exception('Something failed during swap');
                        },
                      );
                      completed = true;
                    },
                    child: const Text('Trigger Crash'),
                  ),
                ),
              );
            },
          ),
        ),
      );

      await tester.pump();
      await tester.tap(find.text('Trigger Crash'));
      await tester.pump();

      // Even with an exception during swap, the transition reaches apex and cleans up
      await tester.pump(const Duration(milliseconds: 800));
      expect(completed, isTrue);

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(milliseconds: 50));
      expect(tester.takeException(), isNull);
    });
  });
}
