import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/features/offline/domain/offline_queue_item.dart';
import 'package:schoolbook/features/offline/presentation/offline_banner.dart';
import 'package:schoolbook/features/offline/presentation/offline_controller.dart';
import 'package:schoolbook/features/payments/presentation/record_payment_controller.dart';
import 'package:schoolbook/features/sales/presentation/new_sale_controller.dart';

import '../../support/fakes.dart';

/// Airplane-mode style: enqueue while offline → reconnect → drain.
void main() {
  test(
    'draft sale: network fail enqueues; reconnect drains with same key',
    () async {
      final stack = testOfflineStack(online: false);
      await stack.controller.start();

      final pending = PendingSubmissionStore(store: InMemoryKeyValueStore());
      stack.sales.createResults.add(ApiException.network());

      final saleCtrl = NewSaleController(
        sales: stack.sales,
        pendingStore: pending,
        offline: stack.controller,
        debounce: const Duration(hours: 1),
      )
        ..setCustomer(testCustomer())
        ..addProduct(testProduct(id: 11));

      expect(await saleCtrl.saveDraft(notes: 'Field visit'), isNull);
      expect(saleCtrl.queuedOffline, isTrue);
      expect(await stack.store.queueLength(), 1);
      expect(await pending.pending(NewSaleController.intent), isNull);

      final queued = (await stack.store.listQueue()).single;
      expect(queued.type, OfflineQueueType.createDraftSale);
      expect(queued.payload['customer_id'], 1);

      // Still offline — drain is a no-op.
      await stack.controller.kickSync();
      expect(await stack.store.queueLength(), 1);

      // Come back online; sync uses the stored Idempotency-Key.
      stack.sales.createResults.add(testSale(id: 55));
      stack.connectivity.online = true;
      await Future<void>.delayed(Duration.zero);
      await stack.controller.kickSync();

      expect(await stack.store.queueLength(), 0);
      expect(stack.sales.createCalls, hasLength(2));
      expect(
        stack.sales.createCalls[1].key,
        stack.sales.createCalls[0].key,
      );
      expect(stack.sales.createCalls[1].key, queued.idempotencyKey);

      saleCtrl.dispose();
      stack.controller.dispose();
    },
  );

  test(
    'payment: stock/validation 422 on sync keeps failed item (not dropped)',
    () async {
      final stack = testOfflineStack(online: true);
      await stack.controller.start();

      await stack.controller.enqueuePayment(
        payload: {
          'customer_id': 1,
          'amount': 50000,
          'method': 'cash',
          'auto_allocate': true,
        },
        idempotencyKey: 'pay-conflict',
      );

      stack.payments.recordResults.add(
        ApiException(
          message: 'Validation failed',
          code: 'validation_failed',
          statusCode: 422,
        ),
      );

      final result = await stack.controller.kickSync();
      expect(result.conflicts, 1);
      expect(await stack.store.queueLength(), 1);
      expect(stack.controller.conflictCount, 1);
      expect(stack.controller.showBanner, isTrue);

      final item = stack.controller.queue.single;
      expect(item.status, OfflineQueueStatus.failed);
      expect(item.lastStatusCode, 422);

      // Manual retry after user resolve path: requeue then succeed.
      stack.payments.recordResults.add(testPayment(id: 99));
      await stack.controller.retry(item.id);
      expect(await stack.store.queueLength(), 0);

      stack.controller.dispose();
    },
  );

  testWidgets('banner shows when offline or queue non-empty', (tester) async {
    final stack = testOfflineStack(online: false);
    await stack.controller.start();
    await stack.controller.enqueueDraftSale(
      payload: {'customer_id': 1, 'items': []},
      idempotencyKey: 'banner-key',
    );

    await tester.pumpWidget(
      ChangeNotifierProvider<OfflineController>.value(
        value: stack.controller,
        child: const MaterialApp(
          home: Scaffold(body: OfflineBanner()),
        ),
      ),
    );
    await tester.pump();

    expect(find.textContaining('Offline'), findsOneWidget);
    expect(find.textContaining('waiting to sync'), findsOneWidget);

    stack.controller.dispose();
  });

  test('payment controller enqueues on network error', () async {
    final stack = testOfflineStack(online: false);
    await stack.controller.start();
    stack.payments.recordResults.add(ApiException.network());

    final ctrl = RecordPaymentController(
      payments: stack.payments,
      pendingStore: PendingSubmissionStore(store: InMemoryKeyValueStore()),
      offline: stack.controller,
      customerId: 1,
    );

    final payload = RecordPaymentController.buildPayload(
      customerId: 1,
      amountPesewas: 10000,
      method: 'cash',
      reference: null,
      paidAt: DateTime.utc(2026, 10, 18, 12),
      notes: null,
      keepAsCredit: false,
    );

    expect(await ctrl.submit(payload), isNull);
    expect(ctrl.queuedOffline, isTrue);
    expect(await stack.store.queueLength(), 1);
    expect(
      (await stack.store.listQueue()).single.type,
      OfflineQueueType.recordPayment,
    );

    ctrl.dispose();
    stack.controller.dispose();
  });
}
