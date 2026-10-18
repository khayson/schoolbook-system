import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';

/// Thin wrapper so tests can fake online/offline without platform channels.
abstract interface class ConnectivityService {
  /// True when at least one non-none interface is reported.
  Future<bool> get isOnline;

  Stream<bool> get onStatusChanged;

  Future<void> dispose();
}

class PlusConnectivityService implements ConnectivityService {
  PlusConnectivityService({Connectivity? connectivity})
    : _connectivity = connectivity ?? Connectivity() {
    _sub = _connectivity.onConnectivityChanged.listen((results) {
      _controller.add(_hasLink(results));
    });
  }

  final Connectivity _connectivity;
  final _controller = StreamController<bool>.broadcast();
  StreamSubscription<List<ConnectivityResult>>? _sub;

  static bool _hasLink(List<ConnectivityResult> results) =>
      results.any((r) => r != ConnectivityResult.none);

  @override
  Future<bool> get isOnline async {
    final results = await _connectivity.checkConnectivity();
    return _hasLink(results);
  }

  @override
  Stream<bool> get onStatusChanged => _controller.stream;

  @override
  Future<void> dispose() async {
    await _sub?.cancel();
    await _controller.close();
  }
}

/// Controllable fake for airplane-mode style tests.
class FakeConnectivityService implements ConnectivityService {
  FakeConnectivityService({bool online = true}) : _online = online;

  bool _online;
  final _controller = StreamController<bool>.broadcast();

  set online(bool value) {
    if (_online == value) return;
    _online = value;
    _controller.add(value);
  }

  @override
  Future<bool> get isOnline async => _online;

  @override
  Stream<bool> get onStatusChanged => _controller.stream;

  @override
  Future<void> dispose() async => _controller.close();
}
