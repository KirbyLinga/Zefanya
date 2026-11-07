import 'dart:async';
import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/services/connection_watcher.dart';

void main() {
  test('failed probe stays offline and a successful Retry recovers', () async {
    var reachable = false;
    final watcher = ConnectionWatcher(probe: () async => reachable);
    addTearDown(watcher.dispose);
    await watcher.check();
    expect(watcher.connectionState, NetConnectionState.offline);
    reachable = true;
    await watcher.check();
    expect(watcher.connectionState, NetConnectionState.online);
  });
  test('throwing probe completes offline and allows another check', () async {
    final watcher = ConnectionWatcher(
      probe: () async => throw StateError('offline'),
    );
    addTearDown(watcher.dispose);
    await watcher.check();
    await watcher.check();
    expect(watcher.connectionState, NetConnectionState.offline);
  });
  test('concurrent probes share one request', () async {
    final answer = Completer<bool>();
    var calls = 0;
    final watcher = ConnectionWatcher(
      probe: () {
        calls++;
        return answer.future;
      },
    );
    addTearDown(watcher.dispose);
    final first = watcher.check();
    final second = watcher.check();
    answer.complete(false);
    await Future.wait([first, second]);
    expect(calls, 1);
    expect(watcher.connectionState, NetConnectionState.offline);
  });
}
