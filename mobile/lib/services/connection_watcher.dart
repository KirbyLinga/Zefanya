import 'dart:async';
import 'package:flutter/foundation.dart';

enum NetConnectionState { online, offline }

/// API reachability works on web too; mock mode never starts polling.
class ConnectionWatcher extends ChangeNotifier {
  ConnectionWatcher({this.probe});
  static final instance = ConnectionWatcher();
  Future<bool> Function()? probe;
  Timer? _timer;
  Future<void>? _checking;
  NetConnectionState _state = NetConnectionState.online;
  NetConnectionState get connectionState => _state;
  set state(NetConnectionState value) {
    if (_state == value) return;
    _state = value;
    notifyListeners();
  }

  void markOffline() => state = NetConnectionState.offline;
  void markOnline() => state = NetConnectionState.online;
  Future<void> check() =>
      _checking ??= _check().whenComplete(() => _checking = null);
  Future<void> _check() async {
    final probe = this.probe;
    if (probe == null) return;
    try {
      state = await probe().timeout(const Duration(seconds: 5))
          ? NetConnectionState.online
          : NetConnectionState.offline;
    } catch (_) {
      markOffline();
    }
  }

  void startPolling({
    Future<bool> Function()? probe,
    Duration probeInterval = const Duration(seconds: 15),
  }) {
    this.probe = probe ?? this.probe;
    stopPolling();
    unawaited(check());
    _timer = Timer.periodic(probeInterval, (_) => unawaited(check()));
  }

  void stopPolling() {
    _timer?.cancel();
    _timer = null;
  }

  @override
  void dispose() {
    stopPolling();
    super.dispose();
  }
}
