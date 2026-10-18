import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:path_provider/path_provider.dart';
import 'package:schoolbook/features/offline/domain/field_kit_snapshot.dart';
import 'package:schoolbook/features/offline/domain/offline_queue_item.dart';

/// Persisted outbound queue + field-kit catalog snapshot for offline sales.
abstract interface class OfflineStore {
  Future<List<OfflineQueueItem>> listQueue();

  Future<OfflineQueueItem> enqueue({
    required OfflineQueueType type,
    required Map<String, dynamic> payload,
    required String idempotencyKey,
    DateTime? now,
    String? id,
  });

  Future<void> updateItem(OfflineQueueItem item);

  Future<void> remove(String id);

  /// FIFO among items still eligible for automatic send (pending / stuck
  /// syncing). Failed conflicts stay out until [requeueForRetry].
  Future<List<OfflineQueueItem>> pendingFifo();

  /// Move a failed item back to pending so the next drain retries it.
  Future<void> requeueForRetry(String id, {DateTime? now});

  Future<int> queueLength();

  Future<FieldKitSnapshot> readSnapshot();

  Future<void> writeSnapshot(FieldKitSnapshot snapshot);
}

/// JSON file under application support — same pattern as the reference cache.
/// Drift was skipped for v1 (see docs/decisions.md).
class FileOfflineStore implements OfflineStore {
  FileOfflineStore({
    Future<Directory> Function()? directory,
    Random? random,
  }) : _directory = directory ?? getApplicationSupportDirectory,
       _random = random ?? Random.secure();

  final Future<Directory> Function() _directory;
  final Random _random;

  Future<File> _queueFile() async =>
      File('${(await _directory()).path}/offline_sales_queue.json');

  Future<File> _snapshotFile() async =>
      File('${(await _directory()).path}/field_kit_snapshot.json');

  @override
  Future<List<OfflineQueueItem>> listQueue() async {
    final file = await _queueFile();
    if (!await file.exists()) return const [];
    try {
      final raw = jsonDecode(await file.readAsString());
      if (raw is! List) return const [];
      return [
        for (final e in raw)
          OfflineQueueItem.fromJson(Map<String, dynamic>.from(e as Map)),
      ];
    } on Object {
      return const [];
    }
  }

  Future<void> _writeQueue(List<OfflineQueueItem> items) async {
    final file = await _queueFile();
    final temp = File('${file.path}.tmp');
    await temp.writeAsString(
      jsonEncode([for (final i in items) i.toJson()]),
      flush: true,
    );
    await temp.rename(file.path);
  }

  @override
  Future<OfflineQueueItem> enqueue({
    required OfflineQueueType type,
    required Map<String, dynamic> payload,
    required String idempotencyKey,
    DateTime? now,
    String? id,
  }) async {
    final items = [...await listQueue()];
    final existing = items.where((i) => i.idempotencyKey == idempotencyKey);
    if (existing.isNotEmpty) {
      return existing.first;
    }
    final item = OfflineQueueItem(
      id: id ?? _newId(),
      type: type,
      payload: Map<String, dynamic>.from(payload),
      idempotencyKey: idempotencyKey,
      status: OfflineQueueStatus.pending,
      createdAt: now ?? DateTime.now().toUtc(),
    );
    items.add(item);
    await _writeQueue(items);
    return item;
  }

  @override
  Future<void> updateItem(OfflineQueueItem item) async {
    final items = await listQueue();
    final index = items.indexWhere((i) => i.id == item.id);
    if (index < 0) return;
    items[index] = item;
    await _writeQueue(items);
  }

  @override
  Future<void> remove(String id) async {
    final items = await listQueue();
    items.removeWhere((i) => i.id == id);
    await _writeQueue(items);
  }

  @override
  Future<List<OfflineQueueItem>> pendingFifo() async {
    final items = await listQueue();
    final eligible = items
        .where(
          (i) =>
              i.status == OfflineQueueStatus.pending ||
              i.status == OfflineQueueStatus.syncing,
        )
        .toList();
    eligible.sort((a, b) => a.createdAt.compareTo(b.createdAt));
    return eligible;
  }

  @override
  Future<void> requeueForRetry(String id, {DateTime? now}) async {
    final items = await listQueue();
    final index = items.indexWhere((i) => i.id == id);
    if (index < 0) return;
    items[index] = items[index].copyWith(
      status: OfflineQueueStatus.pending,
      updatedAt: now ?? DateTime.now().toUtc(),
      clearError: true,
    );
    await _writeQueue(items);
  }

  @override
  Future<int> queueLength() async => (await listQueue()).length;

  @override
  Future<FieldKitSnapshot> readSnapshot() async {
    final file = await _snapshotFile();
    if (!await file.exists()) return FieldKitSnapshot.empty();
    try {
      return FieldKitSnapshot.fromJson(
        jsonDecode(await file.readAsString()) as Map<String, dynamic>,
      );
    } on Object {
      return FieldKitSnapshot.empty();
    }
  }

  @override
  Future<void> writeSnapshot(FieldKitSnapshot snapshot) async {
    final file = await _snapshotFile();
    final temp = File('${file.path}.tmp');
    await temp.writeAsString(jsonEncode(snapshot.toJson()), flush: true);
    await temp.rename(file.path);
  }

  String _newId() {
    final bytes = List<int>.generate(16, (_) => _random.nextInt(256));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    final hex = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
    return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}-'
        '${hex.substring(16, 20)}-${hex.substring(20)}';
  }
}

/// In-memory store for unit / widget tests.
class InMemoryOfflineStore implements OfflineStore {
  final List<OfflineQueueItem> items = [];
  FieldKitSnapshot snapshot = FieldKitSnapshot.empty();
  int _seq = 0;

  @override
  Future<List<OfflineQueueItem>> listQueue() async => List.of(items);

  @override
  Future<OfflineQueueItem> enqueue({
    required OfflineQueueType type,
    required Map<String, dynamic> payload,
    required String idempotencyKey,
    DateTime? now,
    String? id,
  }) async {
    final existing = items.where((i) => i.idempotencyKey == idempotencyKey);
    if (existing.isNotEmpty) return existing.first;
    final item = OfflineQueueItem(
      id: id ?? 'q-${++_seq}',
      type: type,
      payload: Map<String, dynamic>.from(payload),
      idempotencyKey: idempotencyKey,
      status: OfflineQueueStatus.pending,
      createdAt: now ?? DateTime.now().toUtc(),
    );
    items.add(item);
    return item;
  }

  @override
  Future<void> updateItem(OfflineQueueItem item) async {
    final index = items.indexWhere((i) => i.id == item.id);
    if (index >= 0) items[index] = item;
  }

  @override
  Future<void> remove(String id) async => items.removeWhere((i) => i.id == id);

  @override
  Future<List<OfflineQueueItem>> pendingFifo() async {
    final eligible = items
        .where(
          (i) =>
              i.status == OfflineQueueStatus.pending ||
              i.status == OfflineQueueStatus.syncing,
        )
        .toList()
      ..sort((a, b) => a.createdAt.compareTo(b.createdAt));
    return eligible;
  }

  @override
  Future<void> requeueForRetry(String id, {DateTime? now}) async {
    final index = items.indexWhere((i) => i.id == id);
    if (index < 0) return;
    items[index] = items[index].copyWith(
      status: OfflineQueueStatus.pending,
      updatedAt: now ?? DateTime.now().toUtc(),
      clearError: true,
    );
  }

  @override
  Future<int> queueLength() async => items.length;

  @override
  Future<FieldKitSnapshot> readSnapshot() async => snapshot;

  @override
  Future<void> writeSnapshot(FieldKitSnapshot value) async => snapshot = value;
}
