import 'package:fake_async/fake_async.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/features/sales/presentation/new_sale_controller.dart';

import '../../support/fakes.dart';

void main() {
  late FakeSalesRepository sales;
  late NewSaleController controller;

  NewSaleController make({
    Duration debounce = const Duration(milliseconds: 400),
  }) => NewSaleController(
    sales: sales,
    pendingStore: PendingSubmissionStore(store: InMemoryKeyValueStore()),
    debounce: debounce,
  );

  setUp(() => sales = FakeSalesRepository());

  test('quantity editing: add merges, +/- steps, never below 1', () {
    controller = make()
      ..addProduct(testProduct(id: 11))
      ..addProduct(testProduct(id: 11))
      ..addProduct(testProduct(id: 12));

    expect(controller.lines.map((l) => '${l.product.id}x${l.quantity}'), [
      '11x2',
      '12x1',
    ]);

    controller
      ..step(11, 5)
      ..step(12, -1)
      ..setQuantity(11, 0);
    expect(controller.lines.map((l) => l.quantity), [7, 1]);

    controller.remove(12);
    expect(controller.lines, hasLength(1));
    controller.dispose();
  });

  test(
    'totals come from one debounced server preview, never computed locally',
    () {
      fakeAsync((async) {
        controller = make()
          ..setCustomer(testCustomer())
          ..addProduct(testProduct(id: 11))
          ..step(11, 1)
          ..step(11, 1);

        async.elapse(const Duration(milliseconds: 399));
        expect(sales.previewCalls, isEmpty);

        async.elapse(const Duration(milliseconds: 1));
        async.flushMicrotasks();

        expect(sales.previewCalls, hasLength(1));
        expect(sales.previewCalls.single, [
          {'product_id': 11, 'quantity': 3},
        ]);
        expect(controller.preview!.total, 3000);
        expect(controller.previewFor(11)!.lineTotal, 3000);
        controller.dispose();
      });
    },
  );

  test('a slow older preview never overwrites a newer one', () {
    fakeAsync((async) {
      controller = make(debounce: Duration.zero)
        ..addProduct(testProduct(id: 11));
      sales.previewResult = null;

      // First (slow) request for qty 1; then a fast one for qty 2.
      sales.previewDelay = const Duration(seconds: 2);
      controller.refreshPreview();
      sales.previewDelay = Duration.zero;
      controller.step(11, 1);
      controller.refreshPreview();

      async.elapse(const Duration(seconds: 3));
      async.flushMicrotasks();

      expect(controller.preview!.total, 2000);
      controller.dispose();
    });
  });

  test('saving a draft: exact body, same key after a lost connection, new key after a change', () async {
    sales.createResults.addAll([ApiException.network(), testSale(id: 77)]);
    controller = make(debounce: const Duration(hours: 1))
      ..setCustomer(testCustomer())
      ..addProduct(testProduct(id: 11));

    expect(await controller.saveDraft(notes: '  '), isNull);
    expect(controller.saveError!.isNetworkError, isTrue);

    final sale = await controller.saveDraft(notes: '  ');

    expect(sale!.id, 77);
    expect(sales.createCalls[0].payload, {
      'customer_id': 1,
      'notes': null,
      'items': [
        {'product_id': 11, 'quantity': 1},
      ],
    });
    expect(sales.createCalls[1].key, sales.createCalls[0].key);

    sales.createResults.addAll([ApiException.network(), testSale(id: 78)]);
    await controller.saveDraft();
    controller.step(11, 1);
    await controller.saveDraft();
    expect(sales.createCalls[3].key, isNot(sales.createCalls[2].key));
    controller.dispose();
  });

  test(
    'a definitive save error (inactive customer 422) forgets the key',
    () async {
      final store = PendingSubmissionStore(store: InMemoryKeyValueStore());
      sales.createResults.add(
        ApiException(message: 'm', code: 'validation_failed', statusCode: 422),
      );
      controller =
          NewSaleController(
              sales: sales,
              pendingStore: store,
              debounce: const Duration(hours: 1),
            )
            ..setCustomer(testCustomer())
            ..addProduct(testProduct());

      await controller.saveDraft();

      expect(await store.pending(NewSaleController.intent), isNull);
      controller.dispose();
    },
  );

  test('cannot save without a customer or without books', () async {
    controller = make(debounce: const Duration(hours: 1));
    expect(controller.canSave, isFalse);
    controller.addProduct(testProduct());
    expect(controller.canSave, isFalse);
    controller.setCustomer(testCustomer());
    expect(controller.canSave, isTrue);
    controller.dispose();
  });
}
