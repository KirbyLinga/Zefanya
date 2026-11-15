// lib/state/startup_state.dart
//
// What `main()` is doing while it stands between the process and the first frame.
//
// ## Why this file exists
//
// `main()` awaits nine `load()`s before `runApp`, and several of them read over
// the network. A rejection in any one of them is not an error state — it is an app
// that never starts: the native splash holds the screen, the person sees the logo
// and nothing else, and nothing they can do changes it. Live mode did exactly
// that. `ChatState.load()` asked for its threads, the server does not serve that
// endpoint yet, and the exception left `Future.wait` before `runApp` — so the
// first frame never came and the splash stayed.
//
// So the rule is applied once, here, instead of in each of the nine states:
//
//  * **boot never depends on the network** — a failed load is recorded, not
//    rethrown, so the first frame always arrives;
//  * **boot never hides what it could not load** — each failure is kept by name
//    (`'your chats'`) and the shell shows them with a Retry;
//  * **one exception stays fatal** — [ApiNotConfiguredException] is a *build*
//    mistake (live mode with no base URL), its owner is a developer, and no screen
//    can do anything sensible with it.
//
// A failure here is the catch-all, not the only channel: the Orders screen
// explains its own through `OrderState.loadFailure`, because that screen can say
// what orders are. Everything else — chat threads, the voucher list, reviews —
// has no screen of its own yet, which is exactly why this notice exists.

import 'package:flutter/foundation.dart';

import '../data/api/api_exception.dart';

/// One thing that could not be loaded while the app was starting, and why.
@immutable
class StartupFailure {
  const StartupFailure(this.what, this.error);

  /// What the person would call it — `'your chats'`, `'your cart'`.
  final String what;

  /// The failure itself, kept typed so a caller can ask whether retrying could work.
  final ApiException error;
}

class StartupState extends ChangeNotifier {
  final List<StartupFailure> _failures = [];

  /// Every load passed to [guard], so [retry] can run them again.
  final Map<String, Future<void> Function()> _loads = {};

  /// What could not be loaded, in the order it was attempted.
  List<StartupFailure> get failures => List.unmodifiable(_failures);

  bool get hasFailures => _failures.isNotEmpty;

  /// Whether retrying could plausibly work — false when every failure says it
  /// could not, which is what an endpoint the server has not built answers.
  bool get anyRetryable => _failures.any((failure) => failure.error.isRetryable);

  /// Only the failures a person could act on: retryable ones. Known-unbuilt
  /// endpoints (reviews, chat, courier jobs in live mode) are recorded in
  /// [failures] and logged quietly, never bannered — a permanent bar a
  /// customer cannot act on is noise over every screen.
  List<StartupFailure> get actionable =>
      [for (final failure in _failures) if (failure.error.isRetryable) failure];

  bool get hasActionable => actionable.isNotEmpty;

  /// The sentence the notice renders: `We couldn't load your chats.`
  ///
  /// Names only [actionable] failures — the notice never speaks about
  /// entries it does not show.
  String get message {
    final names = [for (final failure in actionable) failure.what];

    return switch (names.length) {
      0 => '',
      1 => "We couldn't load ${names.single}.",
      2 => "We couldn't load ${names.first} or ${names.last}.",
      _ => "We couldn't load ${names.take(names.length - 1).join(', ')} or ${names.last}.",
    };
  }

  /// Runs one of `main()`'s loads, keeping a failure instead of dying on one.
  ///
  /// The load is remembered even when it succeeds, because [retry] has to be able
  /// to run it again later.
  Future<void> guard(String what, Future<void> Function() load) async {
    _loads[what] = load;

    try {
      await load();
    } on ApiNotConfiguredException {
      // Deliberately not caught. See the header: this is a build mistake, and the
      // app is better off failing loudly at startup than limping into a notice.
      rethrow;
    } on ApiException catch (error) {
      // Replace rather than append: a retry that fails again must not turn one
      // problem into the same sentence twice.
      _failures.removeWhere((failure) => failure.what == what);
      _failures.add(StartupFailure(what, error));
      notifyListeners();

      return;
    }

    // It worked. If it had failed before, the notice has something to forget.
    final before = _failures.length;
    _failures.removeWhere((failure) => failure.what == what);

    if (_failures.length != before) notifyListeners();
  }

  /// Re-runs everything that failed.
  ///
  /// Cannot throw: the button that calls it has nothing to do with the error, and
  /// this state is where the error belongs. [ApiNotConfiguredException] is caught
  /// here for the same reason it is rethrown in [guard] — it was already fatal at
  /// startup, and a Retry is not the place to raise it a second time.
  Future<void> retry() async {
    for (final failure in List.of(_failures)) {
      try {
        await guard(failure.what, _loads[failure.what]!);
      } on ApiException {
        // Recorded by `guard`, and rendered from `failures`.
      }
    }
  }
}
