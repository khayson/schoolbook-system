import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/offline/data/offline_store.dart';
import 'package:schoolbook/features/offline/data/sync_worker.dart';
import 'package:schoolbook/features/offline/domain/offline_queue_item.dart';

import '../../support/fakes.dart';

void main() {
  late InMemoryOfflineStore store;
  late FakeSalesRepository sales;
  late FakePaymentsRepository payments;
  late SyncWorker worker;

  setUp(() {
    store = InMemoryOfflineStore();
    sales = FakeSalesRepository();
    payments = FakePaymentsRepository();
    worker = SyncWorker(store: store, sales: sales, payments: payments);
  });

  test('enqueue keeps FIFO creation order', () async {
    final t0 = DateTime.utc(2026, 10, 18, 10);
    final a = await store.enqueue(
      type: OfflineQueueType.createDraftSale,
      payload: {'customer_id': 1, 'items': []},
      idempotencyKey: 'key-a',
      now: t0,
    );
    final b = await store.enqueue(
      type: OfflineQueueType.recordPayment,
      payload: {'customer_id': 1, 'amount': 100},
      idempotencyKey: 'key-b',
      now: t0.add(const Duration(seconds: 1)),
    );
    final c = await store.enqueue(
      type: OfflineQueueType.createDraftSale,
      payload: {'customer_id': 2, 'items': []},
      idempotencyKey: 'key-c',
      now: t0.add(const Duration(seconds: 2)),
    );

    final fifo = await store.pendingFifo();
    expect(fifo.map((i) => i.id), [a.id, b.id, c.id]);
  });

  test('same idempotency key does not duplicate the queue row', () async {
    await store.enqueue(
      type: OfflineQueueType.createDraftSale,
      payload: {'customer_id': 1},
      idempotencyKey: 'same',
    );
    await store.enqueue(
      type: OfflineQueueType.createDraftSale,
      payload: {'customer_id': 1},
      idempotencyKey: 'same',
    );
    expect(await store.queueLength(), 1);
  });

  test('failed conflict stays with error and is not silent-dropped', () async {
    await store.enqueue(
      type: OfflineQueueType.createDraftSale,
      payload: {
        'customer_id': 1,
        'items': [
          {'product_id': 11, 'quantity': 99},
        ],
      },
      idempotencyKey: 'stock-key',
      now: DateTime.utc(2026, 10, 18),
    );
    sales.createResults.add(
      ApiException(
        message: 'Not enough stock',
        code: 'insufficient_stock',
        statusCode: 422,
      ),
    );

    final result = await worker.drain();

    expect(result.conflicts, 1);
    expect(result.synced, 0);
    expect(await store.queueLength(), 1);
    final item = (await store.listQueue()).single;
    expect(item.status, OfflineQueueStatus.failed);
    expect(item.lastStatusCode, 422);
    expect(item.lastErrorCode, 'insufficient_stock');
    expect(item.needsUserResolve, isTrue);
    // Auto drain must not retry a conflict until the user asks.
    expect(await store.pendingFifo(), isEmpty);
  });

  test('successful drain removes items in FIFO order', () async {
    await store.enqueue(
      type: OfflineQueueType.createDraftSale,
      payload: {'customer_id': 1, 'items': []},
      idempotencyKey: 'k1',
      now: DateTime.utc(2026, 10, 18, 8),
    );
    await store.enqueue(
      type: OfflineQueueType.recordPayment,
      payload: {'customer_id': 1, 'amount': 500},
      idempotencyKey: 'k2',
      now: DateTime.utc(2026, 10, 18, 9),
    );
    sales.createResults.add(testSale(id: 10));
    payments.recordResults.add(testPayment(id: 20));

    final result = await worker.drain();

    expect(result.synced, 2);
    expect(await store.queueLength(), 0);
    expect(sales.createCalls.single.key, 'k1');
    expect(payments.recordCalls.single.key, 'k2');
  });

  test('network failure during sync leaves item pending for retry', () async {
    await store.enqueue(
      type: OfflineQueueType.recordPayment,
      payload: {'customer_id': 1, 'amount': 100},
      idempotencyKey: 'net-key',
    );
    payments.recordResults.add(ApiException.network());

    final result = await worker.drain();

    expect(result.failed, 1);
    expect(await store.queueLength(), 1);
    final item = (await store.listQueue()).single;
    expect(item.status, OfflineQueueStatus.pending);
    expect(item.idempotencyKey, 'net-key');
  });
}
