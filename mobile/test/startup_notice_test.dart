// test/startup_notice_test.dart
//
// The rule that keeps a half-built backend from becoming an app that never starts.
//
// `main()` awaits nine `load()`s before `runApp`, so a rejection in any one of them
// holds the native splash on screen forever. The first live run did exactly that:
// the person saw the logo and nothing else, because `ChatState.load()` asked for
// an endpoint the server does not serve yet. These tests pin both halves of the
// fix — a failed load is *recorded* rather than fatal, and it is *shown* rather
// than hidden.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/state/startup_state.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/widgets/startup_notice.dart';

/// A load that always fails the way an endpoint the server has not built does.
Future<void> _unwired() async =>
    throw const ApiNotImplementedException('GET /chat/threads');

/// A load that fails until [recovered] is set — a request that could work.
class _Flaky {
  bool recovered = false;

  Future<void> load() async {
    if (recovered) return;

    throw const NetworkException();
  }
}

/// A one-shot retryable failure — a request that could work, without
/// needing the mutable _Flaky harness.
Future<void> Function() _flakyLoad(bool recovered) => () async {
      if (!recovered) throw const NetworkException();
    };

Future<void> _pump(WidgetTester tester, StartupState startup) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;

  await tester.pumpWidget(
    ChangeNotifierProvider.value(
      value: startup,
      child: MaterialApp(
        theme: AppTheme.light,
        home: Scaffold(body: Column(children: const [StartupNotice(), Spacer()])),
      ),
    ),
  );
  await tester.pump();
}

void main() {
  group('StartupState', () {
    test('a failed load is recorded, not thrown — the first frame always arrives', () async {
      final startup = StartupState();

      // This is the whole point: before it, the exception left `Future.wait`
      // before `runApp` and the splash never gave way.
      await startup.guard('your chats', _unwired);

      expect(startup.hasFailures, isTrue);
      expect(startup.failures.single.what, 'your chats');
      expect(startup.failures.single.error, isA<ApiNotImplementedException>());
    });

    test('a build mistake is still fatal', () async {
      final startup = StartupState();

      // Live mode with no base URL is not a runtime condition anybody on the
      // phone can act on, and no screen can explain it.
      await expectLater(
        startup.guard('your cart', () async => throw const ApiNotConfiguredException()),
        throwsA(isA<ApiNotConfiguredException>()),
      );

      expect(startup.hasFailures, isFalse);
    });

    test('the message names only retryable failures', () async {
      final startup = StartupState();

      // A known-unbuilt endpoint is recorded but never named: the notice
      // must not speak about entries it does not show.
      await startup.guard('your reviews', _unwired);
      expect(startup.hasFailures, isTrue);
      expect(startup.hasActionable, isFalse);
      expect(startup.message, isEmpty);

      await startup.guard('your cart', _flakyLoad(false));
      expect(startup.message, "We couldn't load your cart.");

      await startup.guard('your chats', _flakyLoad(false));
      expect(startup.message, "We couldn't load your cart or your chats.");
    });

    test('actionable lists only failures retrying could fix', () async {
      final startup = StartupState();

      await startup.guard('your reviews', _unwired);
      await startup.guard('your cart', _flakyLoad(false));

      expect([for (final f in startup.actionable) f.what], ['your cart']);
      expect(startup.anyRetryable, isTrue);
    });

    test('retry clears what now works and keeps what does not', () async {
      final startup = StartupState();
      final flaky = _Flaky();

      await startup.guard('your chats', flaky.load);
      await startup.guard('your cart', _unwired);
      expect(startup.failures, hasLength(2));

      flaky.recovered = true;
      await startup.retry();

      expect([for (final failure in startup.failures) failure.what], ['your cart']);
      // The survivor is non-retryable, so nothing actionable remains —
      // but the record itself is kept, not hidden.
      expect(startup.hasActionable, isFalse);
    });
  });

  group('the notice', () {
    testWidgets('says nothing at all when nothing failed', (tester) async {
      await _pump(tester, StartupState());

      expect(find.byType(Text), findsNothing);
    });

    testWidgets('a non-retryable-only set shows nothing at all', (tester) async {
      final startup = StartupState();
      await startup.guard('your reviews', _unwired);
      await _pump(tester, startup);

      // Recorded in state, logged quietly — but never bannered. A bar a
      // customer cannot act on is noise over every screen.
      expect(startup.hasFailures, isTrue);
      expect(find.byType(Text), findsNothing);
    });

    testWidgets('a mix shows only the retryable names', (tester) async {
      final startup = StartupState();
      await startup.guard('your reviews', _unwired);
      await startup.guard('your chats', _flakyLoad(false));
      await _pump(tester, startup);

      expect(find.text("We couldn't load your chats."), findsOneWidget);
      expect(find.text('Retry'), findsOneWidget);
    });

    testWidgets('a failure that could work gets a Retry, and it clears the notice', (tester) async {
      final startup = StartupState();
      final flaky = _Flaky();
      await startup.guard('your chats', flaky.load);
      await _pump(tester, startup);

      expect(find.text('Retry'), findsOneWidget);

      flaky.recovered = true;
      await tester.tap(find.text('Retry'));
      await tester.pump();

      expect(find.text('Retry'), findsNothing);
      expect(find.textContaining("We couldn't load"), findsNothing);
    });
  });
}
