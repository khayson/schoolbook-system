import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/features/sales/presentation/sale_actions_controller.dart';

import '../../support/fakes.dart';

ApiException priceChanged() => ApiException(
      message: 'Prices have changed',
      code: 'price_changed',
      statusCode: 409,
      details: const {
        'priced_order': {
          'lines': [
            {'product_id': 11, 'product_title': 'English Reader P4', 'quantity': 3, 'base_price': 1200, 'unit_price': 1200, 'line_total': 3600},
          ],
          'subtotal': 3600,
          'total': 3600,
          'warnings': [],
        },
      },
    );

ApiException stockShort() => ApiException(
      message: 'Insufficient stock',
      code: 'insufficient_stock',
      statusCode: 422,
      details: const {
        'items': [
          {'product_id': 11, 'sku': 'ENG-P4', 'title': 'English Reader P4', 'requested': 3, 'available': 1},
          {'product_id': 12, 'sku': 'MTH-P4', 'title': 'Maths P4', 'requested': 2, 'available': 0},
        ],
      },
    );

ApiException creditExceeded() => ApiException(
      message: 'Over limit',
      code: 'credit_limit_exceeded',
      statusCode: 409,
      details: const {
        'credit_limit': 10000,
        'outstanding': 8000,
        'sale_total': 3000,
        'credit_applied': 0,
        'projected_balance': 11000,
        'override_flag': 'override_credit_limit',
      },
    );

void main() {
  late FakeSalesRepository sales;
  late PendingSubmissionStore store;
  late SaleActionsController controller;

  setUp(() {
    sales = FakeSalesRepository();
    store = PendingSubmissionStore(store: InMemoryKeyValueStore());
    controller = SaleActionsController(sales: sales, pendingStore: store);
  });

  test('confirm sends the exact body and clears the key on success', () async {
    final outcome = await controller.confirm(testSale(), dueDate: DateTime(2026, 11, 30), applyCredit: true);

    expect(outcome, isA<Confirmed>());
    expect(sales.confirmCalls.single.options, {'due_date': '2026-11-30', 'apply_credit': true, 'override_credit_limit': false});
    expect(await store.pending(SaleActionsController.confirmIntent(5)), isNull);
  });

  test('a lost connection keeps the key and the retry sends the same one', () async {
    sales.confirmResults.addAll([ApiException.network(), testSale(status: 'confirmed', invoiceNo: 'INV-2026-000001')]);

    final first = await controller.confirm(testSale());
    expect(first, isA<ConfirmFailed>());
    expect(await store.pending(SaleActionsController.confirmIntent(5)), isNotNull);

    final second = await controller.confirm(testSale());
    expect(second, isA<Confirmed>());
    expect(sales.confirmCalls[1].key, sales.confirmCalls[0].key);
  });

  test('price_changed: parsed new order, nothing kept, accept re-prices, next confirm uses a new key', () async {
    sales.confirmResults.add(priceChanged());
    final draft = testSale();

    final outcome = await controller.confirm(draft);

    expect(outcome, isA<PricesChanged>());
    final order = (outcome as PricesChanged).newOrder;
    expect(order.total, 3600);
    expect(order.lines.single.unitPrice, 1200);
    expect(await store.pending(SaleActionsController.confirmIntent(5)), isNull);

    sales.repriced = testSale(total: 3600, updatedAt: '2026-10-02T09:05:00.000000Z');
    final repriced = await controller.acceptNewPrices(draft);
    expect(sales.updateCalls.single, isEmpty, reason: 'accept = PUT /sales/{id} with {}');
    expect(repriced.total, 3600);

    final confirmed = await controller.confirm(repriced);
    expect(confirmed, isA<Confirmed>());
    expect(sales.confirmCalls[1].key, isNot(sales.confirmCalls[0].key));
  });

  test('the confirm key changes with updated_at even when the total does not', () async {
    sales.confirmResults.addAll([ApiException.network(), ApiException.network()]);

    await controller.confirm(testSale(updatedAt: 'A'));
    await controller.confirm(testSale(updatedAt: 'B'));

    expect(sales.confirmCalls[1].key, isNot(sales.confirmCalls[0].key));
  });

  test('insufficient_stock lists every short book', () async {
    sales.confirmResults.add(stockShort());

    final outcome = await controller.confirm(testSale()) as StockShort;

    expect(outcome.items.map((i) => '${i.sku}:${i.requested}/${i.available}'), ['ENG-P4:3/1', 'MTH-P4:2/0']);
  });

  test('credit_limit_exceeded is a warning; confirming with the override sends the flag and a new key', () async {
    sales.confirmResults.add(creditExceeded());

    final warning = await controller.confirm(testSale()) as CreditWarning;
    expect(warning.projectedBalance, 11000);
    expect(warning.creditLimit, 10000);

    final outcome = await controller.confirm(testSale(), overrideCreditLimit: true);

    expect(outcome, isA<Confirmed>());
    expect(sales.confirmCalls[1].options['override_credit_limit'], isTrue);
    expect(sales.confirmCalls[1].key, isNot(sales.confirmCalls[0].key));
  });

  test('other errors are ConfirmFailed with the original exception', () async {
    final notEditable = ApiException(message: 'This sale is confirmed', code: 'sale_not_editable', statusCode: 409);
    sales.confirmResults.add(notEditable);

    final outcome = await controller.confirm(testSale());

    expect((outcome as ConfirmFailed).error, same(notEditable));
  });
}
