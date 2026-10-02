import 'dart:convert';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Minimal persistent key-value store, so tests can use memory and the app can use
/// secure storage (which survives app restarts).
abstract interface class KeyValueStore {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

class SecureKeyValueStore implements KeyValueStore {
  SecureKeyValueStore({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String value) =>
      _storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);
}

class InMemoryKeyValueStore implements KeyValueStore {
  final Map<String, String> values = {};

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async => values[key] = value;

  @override
  Future<void> delete(String key) async => values.remove(key);
}

/// A submission that was started and has not been confirmed as succeeded.
class PendingSubmission {
  const PendingSubmission({
    required this.idempotencyKey,
    required this.payloadHash,
    required this.payload,
    required this.startedAt,
  });

  factory PendingSubmission.fromJson(Map<String, dynamic> json) {
    return PendingSubmission(
      idempotencyKey: json['key'] as String,
      payloadHash: json['hash'] as String,
      payload: Map<String, dynamic>.from(json['payload'] as Map),
      startedAt: DateTime.parse(json['started_at'] as String),
    );
  }

  final String idempotencyKey;
  final String payloadHash;
  final Map<String, dynamic> payload;
  final DateTime startedAt;

  Map<String, dynamic> toJson() => {
        'key': idempotencyKey,
        'hash': payloadHash,
        'payload': payload,
        'started_at': startedAt.toIso8601String(),
      };
}

/// One idempotency key per user intent (e.g. "record a payment for customer 12"),
/// persisted on the device until the server confirms success.
///
/// - Same intent + same payload -> the same key, even after the app was killed
///   mid-request and restarted. The server replays the original response instead of
///   recording the payment twice.
/// - Payload changed (user edited the form) -> a new key.
/// - Success -> [complete] forgets it.
class PendingSubmissionStore {
  PendingSubmissionStore({KeyValueStore? store, Random? random})
      : _store = store ?? SecureKeyValueStore(),
        _random = random ?? Random.secure();

  static const _prefix = 'pending_submission.';

  final KeyValueStore _store;
  final Random _random;

  Future<PendingSubmission?> pending(String intent) async {
    final raw = await _store.read('$_prefix$intent');
    if (raw == null) {
      return null;
    }
    try {
      return PendingSubmission.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } on Object {
      await _store.delete('$_prefix$intent');
      return null;
    }
  }

  /// Returns the key to send for [payload], persisting it before the request goes out.
  Future<String> keyFor(String intent, Map<String, dynamic> payload, {DateTime? now}) async {
    final hash = payloadHash(payload);
    final existing = await pending(intent);
    if (existing != null && existing.payloadHash == hash) {
      return existing.idempotencyKey;
    }

    final submission = PendingSubmission(
      idempotencyKey: _newKey(),
      payloadHash: hash,
      payload: payload,
      startedAt: now ?? DateTime.now(),
    );
    await _store.write('$_prefix$intent', jsonEncode(submission.toJson()));
    return submission.idempotencyKey;
  }

  /// The server confirmed the submission (2xx): forget the key.
  Future<void> complete(String intent) => _store.delete('$_prefix$intent');

  /// Stable hash of the payload: canonical JSON (keys sorted) through 64-bit FNV-1a.
  /// Only needs to detect "did the form change", not resist attackers.
  static String payloadHash(Map<String, dynamic> payload) {
    final bytes = utf8.encode(jsonEncode(_canonical(payload)));
    var hash = BigInt.parse('cbf29ce484222325', radix: 16);
    final prime = BigInt.parse('100000001b3', radix: 16);
    final mask = (BigInt.one << 64) - BigInt.one;
    for (final b in bytes) {
      hash = ((hash ^ BigInt.from(b)) * prime) & mask;
    }
    return hash.toRadixString(16).padLeft(16, '0');
  }

  static Object? _canonical(Object? value) {
    if (value is Map) {
      final keys = value.keys.map((k) => k.toString()).toList()..sort();
      return {for (final k in keys) k: _canonical(value[k])};
    }
    if (value is List) {
      return value.map(_canonical).toList();
    }
    return value;
  }

  /// Random v4-style UUID from a secure source.
  String _newKey() {
    final bytes = List<int>.generate(16, (_) => _random.nextInt(256));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    final hex = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
    return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}-'
        '${hex.substring(16, 20)}-${hex.substring(20)}';
  }
}
