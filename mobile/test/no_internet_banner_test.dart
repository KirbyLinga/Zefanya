// test/no_internet_banner_test.dart
//
// Fixture for [NoInternetBanner] (S8B-lite: no-internet banner with Retry).
// Two states:
//   online -> the banner is a zero-height shrink-wrapped widget, invisible.
//   offline -> the banner inflates to the full toolbar height with the
//              Retry button.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/services/connection_watcher.dart';
import 'package:zefanya/widgets/no_internet_banner.dart';

void main() {
  testWidgets('Retry works above the Navigator without an overlay', (
    tester,
  ) async {
    final watcher = ConnectionWatcher.instance;
    watcher.markOffline();
    addTearDown(watcher.markOnline);
    var retried = false;
    await tester.pumpWidget(
      MaterialApp(
        builder: (context, child) => Column(
          children: [
            Material(
              child: NoInternetBanner(
                onRetry: () => retried = true,
                onRefresh: () {},
              ),
            ),
            Expanded(child: child!),
          ],
        ),
        home: const Scaffold(),
      ),
    );
    expect(tester.takeException(), isNull);
    expect(find.text('No internet connection'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.refresh));
    expect(retried, isTrue);
  });

  testWidgets('mounted banner follows offline and online transitions', (
    tester,
  ) async {
    ConnectionWatcher.instance.state = NetConnectionState.online;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: NoInternetBanner(onRetry: () {}, onRefresh: () {}),
        ),
      ),
    );
    expect(find.text('No internet connection'), findsNothing);
    ConnectionWatcher.instance.markOffline();
    await tester.pump();
    expect(find.text('No internet connection'), findsOneWidget);
    expect(find.byTooltip('Retry'), findsOneWidget);
    ConnectionWatcher.instance.markOnline();
    await tester.pump();
    expect(find.text('No internet connection'), findsNothing);
  });
  group('NoInternetBanner', () {
    testWidgets('online: shrinks to zero height', (WidgetTester tester) async {
      // Force online by setting the internal state directly.
      ConnectionWatcher.instance.state = NetConnectionState.online;
      ConnectionWatcher.instance.notifyListeners();

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: NoInternetBanner(onRetry: () {}, onRefresh: () {}),
          ),
        ),
      );

      final banner = tester.widget<NoInternetBanner>(
        find.byType(NoInternetBanner),
      );
      expect(banner.onRetry, isNotNull);
      // When online, the banner's build returns SizedBox.shrink() which is
      // zero-height and effectively invisible.
      final renderBox = tester.renderObject<RenderBox>(
        find.byType(NoInternetBanner),
      );
      expect(renderBox.size.width, 0.0);
      expect(renderBox.size.height, 0.0);
    });

    testWidgets('offline: renders the full toolbar-height banner with Retry', (
      WidgetTester tester,
    ) async {
      ConnectionWatcher.instance.markOffline();
      ConnectionWatcher.instance.notifyListeners();

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: NoInternetBanner(onRetry: () {}, onRefresh: () {}),
          ),
        ),
      );

      expect(find.text('No internet connection'), findsOneWidget);
      expect(find.byIcon(Icons.refresh), findsOneWidget);
    });

    testWidgets('Retry and refresh call the callbacks', (
      WidgetTester tester,
    ) async {
      ConnectionWatcher.instance.markOffline();
      ConnectionWatcher.instance.notifyListeners();

      var retryCalled = false;
      var refreshCalled = false;

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: NoInternetBanner(
              onRetry: () {
                retryCalled = true;
              },
              onRefresh: () {
                refreshCalled = true;
              },
            ),
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.refresh));
      await tester.pump();

      expect(retryCalled, isTrue);
      expect(refreshCalled, isFalse);
    });
  });
}
