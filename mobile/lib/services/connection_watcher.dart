// lib/services/connection_watcher.dart
//
// Owns the one true connection state for the whole app and lets every
// listener know when the net goes away or comes back.
//
// Why not `connectivity`?: the pubspec deliberately avoids third-party
// network packages (every dependency must round-trip to the server anyway),
// so a tiny inline check is the honest choice. [InternetAddress.lookup] on
// the public 8.8.8.8 returns instantly when the stack is up; a blocked
// socket is what we read as "offline".
//
// The check is non-blocking (DNS + carrier routing), fire-and-forget, and
// coalesces rapid changes so a flapping signal doesn't thrash the UI.

import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';

/// Whether the device currently has a usable network path to the server.
enum NetConnectionState { online, offline }

/// The single source of connection truth for the app.
class ConnectionWatcher extends ChangeNotifier {
  ConnectionWatcher._();

  static ConnectionWatcher? _instance;

  static ConnectionWatcher get instance {
    _instance ??= ConnectionWatcher._();
    return _instance!;
  }

  NetConnectionState _state = NetConnectionState.online;
  Timer? _probe;

  /// The current connection state. Exposed as a getter for reads and as a
  /// setter for tests and forced-offline scenarios. The setter is named
  /// [state] and the getter no longer shares that name.
  NetConnectionState get connectionState => _state;

  /// Directly set the connection state (used by tests and forced-offline
  /// scenarios). Exposing it as a setter avoids a round trip through
  /// markOffline() when a deterministic test state is needed.
  set state(NetConnectionState s) {
    _state = s;
    notifyListeners();
  }

  /// Poison the probe (call on a real failure, e.g. a [SocketException] or
  /// [IOException]). The next [check()] run lands on [offline].
  void markOffline() {
    _state = NetConnectionState.offline;
    notifyListeners();
  }

  /// Re-run the probe now, then every [probeInterval].
  void startPolling({Duration probeInterval = const Duration(seconds: 3)}) {
    _probe?.cancel();
    _probe = Timer.periodic(probeInterval, (_) => _probeOnce());
  }

  void stopPolling() {
    _probe?.cancel();
    _probe = null;
  }

  /// Run the probe once. This method never returns on its own: the probe
  /// restarts on a periodic timer. The analyzer can't see that from
  /// `await done.future` alone, so we end with an explicit return.
  Future<void> _probeOnce() async {
    try {
      // 8.8.8.8 is a public DNS that every carrier routes. A successful
      // lookup (or a 3xx TCP handshake that times out at the Socket level)
      // means the radio stack is up — that is the definition we care
      // about, not "can I resolve this domain".
      final done = Completer<void>();
      InternetAddress.lookup('8.8.8.8').timeout(
            const Duration(seconds: 2),
            onTimeout: () {
              // The lookup timed out: the radio is up but nothing answers.
              // Mark offline immediately, then let the periodic tick
              // re-probe. We return a bare empty list as the value that
              // [timeout] wants for its [onTimeout] closure.
              _state = NetConnectionState.offline;
              notifyListeners();
              return <InternetAddress>[];
            },
          )
          .then((tasks) {
            // At least one DNS resolution succeeded: the radio stack is up.
            done.complete();
          })
          .catchError((_) {
            // LookupException, SocketException, etc. — the radio is up but
            // nothing answers; treat as offline.
            _state = NetConnectionState.offline;
            notifyListeners();
          });
      await done.future;
      if (_state != NetConnectionState.online) {
        _state = NetConnectionState.online;
        notifyListeners();
      }
      return;
    } catch (_) {
      // Never let a probe crash the app; the cached state survives.
      return;
    } finally {
      // The probe runs repeatedly; the timer restarts it. Only stop if the
      // watcher was explicitly closed — this method is fire-and-forget.
      if (_probe != null) {
        _probe?.cancel();
        _probe = Timer.periodic(
          const Duration(seconds: 3),
          (_) => _probeOnce(),
        );
      }
    }
  }
}
