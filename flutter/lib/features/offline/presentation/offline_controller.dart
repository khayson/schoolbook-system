import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/offline/data/connectivity_service.dart';
import 'package:schoolbook/features/offline/data/offline_store.dart';
import 'package:schoolbook/features/offline/data/sync_worker.dart';
import 'package:schoolbook/features/offline/domain/field_kit_snapshot.dart';
import 'package:schoolbook/features/offline/domain/offline_queue_item.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';

/// Coordinates connectivity, the outbound queue, catalog snapshot, and sync.
class OfflineController extends ChangeNotifier {
  OfflineController({
    required OfflineStore store,
    required SyncWorker syncWorker,
    required ConnectivityService connectivity,
    required CustomersRepository customers,
    required ProductsRepository products,
  }) : _store = store,
       _syncWorker = syncWorker,
       _connectivity = connectivity,
       _customers = customers,
       _products = products;

  final OfflineStore _store;
  final SyncWorker _syncWorker;
  final ConnectivityService _connectivity;
  final CustomersRepository _customers;
  final ProductsRepository _products;

  StreamSubscription<bool>? _connSub;

  bool online = true;
  bool syncing = false;
  List<OfflineQueueItem> queue = const [];
  FieldKitSnapshot snapshot = FieldKitSnapshot.empty();
  SyncDrainResult? lastDrain;

  int get pendingCount =>
      queue.where((i) => i.status != OfflineQueueStatus.failed).length;

  int get conflictCount => queue.where((i) => i.needsUserResolve).length;

  bool get showBanner => !online || queue.isNotEmpty;

  String get bannerMessage {
    if (!online && queue.isEmpty) {
      return 'You’re offline. Draft sales and payments will queue until you’re back.';
    }
    if (!online && queue.isNotEmpty) {
      return 'Offline — ${queue.length} waiting to sync';
    }
    if (conflictCount > 0) {
      return '$conflictCount need attention before sync can finish';
    }
    if (syncing) {
      return 'Syncing ${queue.length} saved offline…';
    }
    return '${queue.length} saved offline — will sync';
  }

  Future<void> start() async {
    online = await _connectivity.isOnline;
    await refresh();
    _connSub = _connectivity.onStatusChanged.listen((isOnline) {
      final wasOffline = !online;
      online = isOnline;
      notifyListeners();
      if (wasOffline && isOnline) {
        unawaited(kickSync());
      }
    });
    if (online) {
      unawaited(kickSync());
      unawaited(refreshSnapshot());
    }
  }

  Future<void> refresh() async {
    queue = await _store.listQueue();
    snapshot = await _store.readSnapshot();
    notifyListeners();
  }

  /// Enqueue a draft sale after a network / unknown outcome. Idempotency key
  /// must already be the one used for the failed attempt.
  Future<OfflineQueueItem> enqueueDraftSale({
    required Map<String, dynamic> payload,
    required String idempotencyKey,
  }) async {
    final item = await _store.enqueue(
      type: OfflineQueueType.createDraftSale,
      payload: payload,
      idempotencyKey: idempotencyKey,
    );
    await refresh();
    return item;
  }

  Future<OfflineQueueItem> enqueuePayment({
    required Map<String, dynamic> payload,
    required String idempotencyKey,
  }) async {
    final item = await _store.enqueue(
      type: OfflineQueueType.recordPayment,
      payload: payload,
      idempotencyKey: idempotencyKey,
    );
    await refresh();
    return item;
  }

  Future<void> discard(String id) async {
    await _store.remove(id);
    await refresh();
  }

  Future<void> retry(String id) async {
    await _store.requeueForRetry(id);
    await refresh();
    await kickSync();
  }

  Future<SyncDrainResult> kickSync() async {
    if (!online || syncing) {
      return const SyncDrainResult(stoppedEarly: true);
    }
    syncing = true;
    notifyListeners();
    try {
      lastDrain = await _syncWorker.drain();
      await refresh();
      return lastDrain!;
    } finally {
      syncing = false;
      notifyListeners();
    }
  }

  /// Pull first pages of customers + products while online. Never invents totals.
  Future<void> refreshSnapshot({int maxPages = 4}) async {
    if (!online) return;
    try {
      final customers = <Customer>[];
      final products = <Product>[];
      for (var page = 1; page <= maxPages; page++) {
        final c = await _customers.listCustomers(page: page);
        customers.addAll(c.data);
        if (page >= c.meta.lastPage) break;
      }
      for (var page = 1; page <= maxPages; page++) {
        final p = await _products.listProducts(page: page);
        products.addAll(p.data);
        if (page >= p.meta.lastPage) break;
      }
      final next = FieldKitSnapshot(
        customers: customers,
        products: products,
        capturedAt: DateTime.now().toUtc(),
      );
      await _store.writeSnapshot(next);
      snapshot = next;
      notifyListeners();
    } on Object {
      // Keep the previous snapshot; online UI still uses live APIs.
    }
  }

  @override
  void dispose() {
    unawaited(_connSub?.cancel());
    unawaited(_connectivity.dispose());
    super.dispose();
  }
}
