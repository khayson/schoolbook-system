import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/features/payments/presentation/record_payment_controller.dart';

import '../../support/fakes.dart';

void main() {
  Map<String, dynamic> payload({
    int amount = 50000,
    String reference = 'MP-1',
  }) => RecordPaymentController.buildPayload(
    customerId: 1,
    amountPesewas: amount,
    method: 'momo',
    reference: reference,
    paidAt: DateTime.utc(2026, 10, 2, 9, 30),
    notes: '  ',
    keepAsCredit: false,
  );

  test('buildPayload is the exact API body', () {
    expect(payload(), {
      'customer_id': 1,
      'amount': 50000,
      'method': 'momo',
      'reference': 'MP-1',
      'paid_at': '2026-10-02T09:30:00.000Z',
      'notes': null,
      'auto_allocate': true,
    });
    expect(
      RecordPaymentController.buildPayload(
        customerId: 1,
        amountPesewas: 1,
        method: 'cash',
        reference: ' ',
        paidAt: DateTime.utc(2026),
        notes: null,
        keepAsCredit: true,
      )['auto_allocate'],
      isFalse,
    );
  });

  test(
    'a retry after a network error sends the same key; success forgets it',
    () async {
      final repo = FakePaymentsRepository(
        recordResults: [ApiException.network(), testPayment()],
      );
      final store = PendingSubmissionStore(store: InMemoryKeyValueStore());
      final controller = RecordPaymentController(
        payments: repo,
        pendingStore: store,
        customerId: 1,
      );

      expect(await controller.submit(payload()), isNull);
      expect(controller.error!.isNetworkError, isTrue);
      expect(controller.unfinished, isNotNull);

      final recorded = await controller.submit(payload());

      expect(recorded!.receiptNo, 'RCT-2026-000007');
      expect(repo.recordCalls, hasLength(2));
      expect(repo.recordCalls[1].key, repo.recordCalls[0].key);
      expect(controller.unfinished, isNull);
      expect(await store.pending(controller.intent), isNull);
    },
  );

  test('changing the amount after a failure uses a new key', () async {
    final repo = FakePaymentsRepository(
      recordResults: [ApiException.network(), testPayment()],
    );
    final controller = RecordPaymentController(
      payments: repo,
      pendingStore: PendingSubmissionStore(store: InMemoryKeyValueStore()),
      customerId: 1,
    );

    await controller.submit(payload(amount: 50000));
    await controller.submit(payload(amount: 55000));

    expect(repo.recordCalls[1].key, isNot(repo.recordCalls[0].key));
  });

  test(
    'killed mid-request: after a restart the unfinished payment reuses its key',
    () async {
      final backing = InMemoryKeyValueStore();
      final repo = FakePaymentsRepository(
        recordResults: [ApiException.network()],
      );
      final before = RecordPaymentController(
        payments: repo,
        pendingStore: PendingSubmissionStore(store: backing),
        customerId: 1,
      );
      await before.submit(payload());
      final firstKey = repo.recordCalls.single.key;

      // App restarted.
      final repoAfter = FakePaymentsRepository(recordResults: [testPayment()]);
      final after = RecordPaymentController(
        payments: repoAfter,
        pendingStore: PendingSubmissionStore(store: backing),
        customerId: 1,
      );
      await after.load();

      expect(after.unfinished, isNotNull);
      expect(after.unfinished!.payload, payload());

      await after.submit(after.unfinished!.payload);

      expect(repoAfter.recordCalls.single.key, firstKey);
      expect(after.unfinished, isNull);
    },
  );

  group('which errors keep the key', () {
    Future<(RecordPaymentController, PendingSubmissionStore)> failWith(
      ApiException e,
    ) async {
      final store = PendingSubmissionStore(store: InMemoryKeyValueStore());
      final controller = RecordPaymentController(
        payments: FakePaymentsRepository(recordResults: [e]),
        pendingStore: store,
        customerId: 1,
      );
      await controller.submit(payload());
      return (controller, store);
    }

    for (final (label, error) in [
      (
        'validation 422',
        ApiException(message: 'm', code: 'validation_failed', statusCode: 422),
      ),
      (
        'duplicate_reference 409',
        ApiException(
          message: 'm',
          code: 'duplicate_reference',
          statusCode: 409,
        ),
      ),
      (
        'sale_not_payable 422',
        ApiException(message: 'm', code: 'sale_not_payable', statusCode: 422),
      ),
      (
        'forbidden 403',
        ApiException(message: 'm', code: 'forbidden', statusCode: 403),
      ),
    ]) {
      test(
        '$label is definitive: key forgotten, no unfinished banner',
        () async {
          final (controller, store) = await failWith(error);

          expect(controller.error, same(error));
          expect(controller.unfinished, isNull);
          expect(await store.pending(controller.intent), isNull);
        },
      );
    }

    for (final (label, error) in [
      ('no connection', ApiException.network()),
      ('server error 500', ApiException(message: 'm', statusCode: 500)),
      ('bad gateway 502', ApiException(message: 'm', statusCode: 502)),
      (
        'request_in_progress 409',
        ApiException(
          message: 'm',
          code: 'request_in_progress',
          statusCode: 409,
        ),
      ),
    ]) {
      test('$label is unknown: key kept for a safe retry', () async {
        final (controller, store) = await failWith(error);

        expect(controller.unfinished, isNotNull);
        expect(await store.pending(controller.intent), isNotNull);
      });
    }

    test(
      'after a definitive error, the same payload gets a fresh key',
      () async {
        final repo = FakePaymentsRepository(
          recordResults: [
            ApiException(
              message: 'm',
              code: 'validation_failed',
              statusCode: 422,
            ),
            testPayment(),
          ],
        );
        final controller = RecordPaymentController(
          payments: repo,
          pendingStore: PendingSubmissionStore(store: InMemoryKeyValueStore()),
          customerId: 1,
        );

        await controller.submit(payload());
        await controller.submit(payload());

        expect(repo.recordCalls[1].key, isNot(repo.recordCalls[0].key));
      },
    );
  });

  test('discarding the unfinished payment forgets its key', () async {
    final store = PendingSubmissionStore(store: InMemoryKeyValueStore());
    final controller = RecordPaymentController(
      payments: FakePaymentsRepository(recordResults: [ApiException.network()]),
      pendingStore: store,
      customerId: 1,
    );
    await controller.submit(payload());

    await controller.discardUnfinished();

    expect(controller.unfinished, isNull);
    expect(await store.pending(controller.intent), isNull);
  });

  test('load fetches the recent payments for the customer', () async {
    final repo = FakePaymentsRepository(
      recent: [testPayment(id: 1), testPayment(id: 2)],
    );
    final controller = RecordPaymentController(
      payments: repo,
      pendingStore: PendingSubmissionStore(store: InMemoryKeyValueStore()),
      customerId: 1,
    );

    await controller.load();

    expect(controller.recent.map((p) => p.id), [1, 2]);
    expect(controller.unfinished, isNull);
  });
}
