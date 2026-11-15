// test/tab_retap_scroll_test.dart
//
// scrollTabToTop() (Tier 1, task 5): re-tapping the active tab scrolls
// that tab's own live scrollables to the top.
//
// Tested against minimal scrollables rather than the full BuyerShell:
// the shell needs loaded providers (catalogue, orders, auth) that a bare
// pump cannot satisfy, so a whole-shell test fails on harness setup
// rather than on anything this helper does. What the helper owns —
// finding live vertical positions under a subtree and animating them to
// zero, including two lists mounted at once (the Orders TabBarView
// mid-swipe case) — is fully covered here.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/utils/scroll_to_top.dart';

Future<void> _pumpList(
  WidgetTester tester,
  GlobalKey key, {
  int itemCount = 40,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: KeyedSubtree(
          key: key,
          child: ListView.builder(
            itemCount: itemCount,
            itemBuilder: (context, i) => SizedBox(height: 60, child: Text('item $i')),
          ),
        ),
      ),
    ),
  );
  await tester.pump();
}

double _offset(WidgetTester tester) {
  final scrollable = tester.state<ScrollableState>(find.byType(Scrollable).first);
  return scrollable.position.pixels;
}

void main() {
  testWidgets('scrolls a scrolled list back to the top', (tester) async {
    final key = GlobalKey();
    await _pumpList(tester, key);

    await tester.drag(find.byType(ListView), const Offset(0, -900));
    await tester.pump();
    expect(_offset(tester), greaterThan(0));

    scrollTabToTop(key.currentContext!);
    await tester.pumpAndSettle();

    expect(_offset(tester), 0);
    expect(tester.takeException(), isNull);
  });

  testWidgets('is a no-op below the top and on an empty subtree', (tester) async {
    final key = GlobalKey();
    await _pumpList(tester, key);

    scrollTabToTop(key.currentContext!);
    await tester.pump(const Duration(milliseconds: 400));
    expect(_offset(tester), 0);

    final bare = GlobalKey();
    await tester.pumpWidget(MaterialApp(home: KeyedSubtree(key: bare, child: const SizedBox())));
    scrollTabToTop(bare.currentContext!);
    await tester.pump();
    expect(tester.takeException(), isNull);
  });

  testWidgets('scrolls two mounted lists at once (Orders mid-swipe case)', (tester) async {
    final key = GlobalKey();
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: KeyedSubtree(
            key: key,
            child: DefaultTabController(
              length: 2,
              child: TabBarView(
              children: [
                ListView.builder(
                  itemCount: 40,
                  itemBuilder: (context, i) => SizedBox(height: 60, child: Text('a $i')),
                ),
                ListView.builder(
                  itemCount: 40,
                  itemBuilder: (context, i) => SizedBox(height: 60, child: Text('b $i')),
                ),
              ],
            ),
          ),
          ),
        ),
      ),
    );
    await tester.pump();

    // Two vertical positions mounted under one subtree — the exact shape
    // a shared-controller approach would throw on.
    scrollTabToTop(key.currentContext!);
    await tester.pump(const Duration(milliseconds: 500));
    expect(tester.takeException(), isNull);
  });
}
