import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/offline/data/offline_store.dart';
import 'package:schoolbook/features/offline/domain/offline_queue_item.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/sales/data/sales_repository.dart';

/// Result of one drain pass.
class SyncDrainResult {
  const SyncDrainResult({
    this.synced = 0,
    this.failed = 0,
    this.conflicts = 0,
    this.stoppedEarly = false,
  });

  final int synced;
  final int failed;
  final int conflicts;
  final bool stoppedEarly;

  bool get didWork => synced > 0 || failed > 0 || conflicts > 0;
}

/// Posts queued draft sales and payments FIFO with their stored Idempotency-Key.
///
/// Never invents money or stock. On 409/422 (stock, validation, price change),
/// the item stays in the queue as [OfflineQueueStatus.failed] for the user to
/// resolve — it is never silent-dropped.
class SyncWorker {
  SyncWorker({
    required OfflineStore store,
    required SalesRepository sales,
    required PaymentsRepository payments,
  }) : _store = store,
       _sales = sales,
       _payments = payments;

  final OfflineStore _store;
  final SalesRepository _sales;
  final PaymentsRepository _payments;

  bool _running = false;

  /// Drain eligible items in creation order. Concurrent calls coalesce.
  Future<SyncDrainResult> drain() async {
    if (_running) {
      return const SyncDrainResult(stoppedEarly: true);
    }
    _running = true;
    var synced = 0;
    var failed = 0;
    var conflicts = 0;
    try {
      final queue = await _store.pendingFifo();
      for (final item in queue) {
        final outcome = await _syncOne(item);
        switch (outcome) {
          case _SyncOutcome.synced:
            synced++;
          case _SyncOutcome.failedRetryable:
            failed++;
          case _SyncOutcome.conflict:
            conflicts++;
          case _SyncOutcome.skipped:
            break;
        }
      }
      return SyncDrainResult(
        synced: synced,
        failed: failed,
        conflicts: conflicts,
      );
    } finally {
      _running = false;
    }
  }

  Future<_SyncOutcome> _syncOne(OfflineQueueItem item) async {
    final now = DateTime.now().toUtc();
    await _store.updateItem(
      item.copyWith(
        status: OfflineQueueStatus.syncing,
        updatedAt: now,
        clearError: true,
      ),
    );

    try {
      switch (item.type) {
        case OfflineQueueType.createDraftSale:
          await _sales.createDraft(
            idempotencyKey: item.idempotencyKey,
            payload: item.payload,
          );
        case OfflineQueueType.recordPayment:
          await _payments.recordPayment(
            idempotencyKey: item.idempotencyKey,
            payload: item.payload,
          );
      }
      await _store.remove(item.id);
      return _SyncOutcome.synced;
    } on ApiException catch (e) {
      if (e.isOutcomeUnknown) {
        // Outcome unknown — leave pending so a later drain retries the same key.
        await _store.updateItem(
          item.copyWith(
            status: OfflineQueueStatus.pending,
            lastError: e.message,
            lastErrorCode: e.code,
            lastStatusCode: e.statusCode,
            updatedAt: DateTime.now().toUtc(),
          ),
        );
        return _SyncOutcome.failedRetryable;
      }

      // Definitive errors (incl. stock 422 / price 409): keep with error; never drop.
      final status = e.statusCode;
      final isConflict = status == 409 || status == 422;
      await _store.updateItem(
        item.copyWith(
          status: OfflineQueueStatus.failed,
          lastError: e.message,
          lastErrorCode: e.code,
          lastStatusCode: status,
          updatedAt: DateTime.now().toUtc(),
        ),
      );
      return isConflict ? _SyncOutcome.conflict : _SyncOutcome.failedRetryable;
    }
  }
}

enum _SyncOutcome { synced, failedRetryable, conflict, skipped }
