// lib/data/repositories/return_repository.dart
//
// Where the buyer's return/refund requests live.
//
// ## The split, stated plainly
//
// Mock mode owns a device-local list, persisted under `return_requests`, with
// the same shape a server could return: id, order, description, ordered photo
// paths, submission time, status and the seller's reason. `ReturnState` owns
// the rules (delivery, seven-day window, evidence limits, open-request block);
// this repository owns only the storage and the writes.
//
// The live API has no returns route yet. Rather than post to a guessed
// endpoint, the live implementation names the three missing calls. That is the
// same rule the other live repositories follow, and it matters here: a return
// request that appears to succeed while the seller receives nothing is worse
// than an honest "not built yet".

import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../../state/return_state.dart';
import '../api/api_exception.dart';
import 'live_stub.dart';

abstract class ReturnRepository {
  /// Restores local requests before the first frame.
  Future<void> load();

  /// Every request this buyer has made, newest first.
  Future<List<ReturnRequest>> all();

  /// Creates a request for [orderId] and returns the stored row.
  ///
  /// The id is the server's to mint in live mode; the mock mints its own. The
  /// photo order is preserved exactly.
  Future<ReturnRequest> submit({
    required String orderId,
    required String description,
    required List<String> photoPaths,
    required DateTime submittedAt,
  });

  /// Moves a rejected request to [ReturnStatus.escalated].
  Future<ReturnRequest> escalate(String requestId, DateTime at);
}

// ---------------------------------------------------------------------------
// Mock
// ---------------------------------------------------------------------------

class MockReturnRepository implements ReturnRepository {
  static const _storageKey = 'return_requests';

  /// Seeded ids start clear of any future sample history. The number is not
  /// meaningful to the buyer; it is a stable local reference.
  static const int _firstRequestNumber = 2001;

  final List<ReturnRequest> _requests = [];

  @override
  Future<void> load() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_storageKey);
      if (raw == null) return;
      final decoded = (jsonDecode(raw) as List<dynamic>)
          .cast<Map<String, dynamic>>();
      _requests
        ..clear()
        ..addAll(
          decoded.map(ReturnRequest.fromJson).whereType<ReturnRequest>(),
        );
      _sort();
    } catch (_) {
      // Corrupt or older shape: one bad entry should cost the list, not the
      // app. Returns are a local mock feature today, so an empty list is a
      // safe recovery rather than a crash on boot.
      _requests.clear();
    }
  }

  @override
  Future<List<ReturnRequest>> all() async => List.unmodifiable(_requests);

  @override
  Future<ReturnRequest> submit({
    required String orderId,
    required String description,
    required List<String> photoPaths,
    required DateTime submittedAt,
  }) async {
    final request = ReturnRequest(
      id: 'RET-${_firstRequestNumber + _requests.length}',
      orderId: orderId,
      description: description,
      photoPaths: List.unmodifiable(photoPaths),
      submittedAt: submittedAt,
    );
    _requests.add(request);
    _sort();
    await _save();
    return request;
  }

  @override
  Future<ReturnRequest> escalate(String requestId, DateTime at) async {
    final index = _requests.indexWhere((request) => request.id == requestId);
    if (index == -1) {
      throw const NotFoundException(message: 'That return request is gone.');
    }
    final escalated = _requests[index].copyWith(
      status: ReturnStatus.escalated,
      escalatedAt: at,
    );
    _requests[index] = escalated;
    _sort();
    await _save();
    return escalated;
  }

  void _sort() {
    _requests.sort((a, b) => b.submittedAt.compareTo(a.submittedAt));
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
      _storageKey,
      jsonEncode([for (final request in _requests) request.toJson()]),
    );
  }
}

// ---------------------------------------------------------------------------
// Live
// ---------------------------------------------------------------------------

class LiveReturnRepository extends LiveRepository implements ReturnRepository {
  LiveReturnRepository(super.client);

  @override
  Future<void> load() async {
    // Nothing local to restore: the server owns returns.
  }

  @override
  Future<List<ReturnRequest>> all() async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('GET /returns');
  }

  @override
  Future<ReturnRequest> submit({
    required String orderId,
    required String description,
    required List<String> photoPaths,
    required DateTime submittedAt,
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('POST /orders/{sellerOrder}/returns');
  }

  @override
  Future<ReturnRequest> escalate(String requestId, DateTime at) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('POST /returns/{return}/escalate');
  }
}
