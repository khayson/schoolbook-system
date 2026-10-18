import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/pdf_sharer.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/sales/domain/sale.dart';
import 'package:schoolbook/features/sales/presentation/sale_detail_screen.dart';
import 'package:schoolbook/features/stock/data/stock_repository.dart';

import '../../support/fakes.dart';
import 'sale_actions_controller_test.dart'
    show creditExceeded, priceChanged, stockShort;

void main() {
  late FakeSalesRepository sales;
  late FakePdfSharer sharer;
  late FakeProductsRepository products;

  Future<void> pumpDetail(
    WidgetTester tester, {
    bool openConfirm = false,
  }) async {
    tester.view.physicalSize = const Size(1080, 3200);
    tester.view.devicePixelRatio = 1.5;
    addTearDown(tester.view.reset);

    final router = GoRouter(
      initialLocation: '/sales/5',
      routes: [
        GoRoute(
          path: '/sales/:id',
          builder: (_, _) =>
              SaleDetailScreen(saleId: 5, openConfirm: openConfirm),
        ),
        GoRoute(
          path: '/customers/:id/pay',
          builder: (_, s) =>
              Scaffold(body: Text('pay ${s.pathParameters['id']}')),
        ),
      ],
    );
    await tester.pumpWidget(
      MultiProvider(
        providers: [
          Provider<SalesRepository>.value(value: sales),
          Provider<ProductsRepository>.value(value: products),
          Provider<StockRepository>.value(
            value: StockRepository(apiClient: unusedApiClient()),
          ),
          Provider<PendingSubmissionStore>.value(
            value: PendingSubmissionStore(store: InMemoryKeyValueStore()),
          ),
          Provider<PdfSharer>.value(value: sharer),
        ],
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> confirmFromSheet(WidgetTester tester) async {
    await tester.tap(find.byKey(const Key('sale_confirm')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('confirm_submit')));
    await tester.pumpAndSettle();
  }

  /// AppToast auto-dismisses; catch the message while it is still on screen.
  Future<void> expectToast(WidgetTester tester, Finder message) async {
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    expect(message, findsOneWidget);
    await tester.pumpAndSettle();
  }

  setUp(() {
    sales = FakeSalesRepository();
    sharer = FakePdfSharer();
    products = FakeProductsRepository([
      testProduct(id: 11, title: 'English Reader P4'),
      testProduct(id: 12, title: 'Maths P4'),
    ]);
  });

  testWidgets('confirming a draft issues the invoice', (tester) async {
    await pumpDetail(tester);
    expect(find.text('English Reader P4'), findsOneWidget);

    await tester.tap(find.byKey(const Key('sale_confirm')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('confirm_submit')));
    await expectToast(
      tester,
      find.text('Invoice INV-2026-000001 issued'),
    );

    expect(find.byKey(const Key('sale_confirm')), findsNothing);
    expect(find.byKey(const Key('sale_more_actions')), findsOneWidget);
  });

  testWidgets(
    'price changed: diff dialog, accept re-prices then confirms with a new key',
    (tester) async {
      sales.confirmResults.add(priceChanged());
      sales.repriced = testSale(
        total: 3600,
        updatedAt: '2026-10-02T09:05:00.000000Z',
      );
      await pumpDetail(tester);

      await confirmFromSheet(tester);

      expect(find.byKey(const Key('price_changed_dialog')), findsOneWidget);
      expect(
        find.text('English Reader P4: GHS 10.00 -> GHS 12.00'),
        findsOneWidget,
      );
      expect(find.text('Total: GHS 30.00 -> GHS 36.00'), findsOneWidget);

      await tester.tap(find.byKey(const Key('accept_new_prices')));
      await expectToast(
        tester,
        find.text('Invoice INV-2026-000001 issued'),
      );

      expect(sales.updateCalls.single, isEmpty);
      expect(sales.confirmCalls, hasLength(2));
      expect(sales.confirmCalls[1].key, isNot(sales.confirmCalls[0].key));
    },
  );

  testWidgets('price changed: "Not now" leaves the draft untouched', (
    tester,
  ) async {
    sales.confirmResults.add(priceChanged());
    await pumpDetail(tester);
    await confirmFromSheet(tester);

    await tester.tap(find.text('Not now'));
    await tester.pumpAndSettle();

    expect(sales.updateCalls, isEmpty);
    expect(sales.confirmCalls, hasLength(1));
    expect(find.byKey(const Key('sale_confirm')), findsOneWidget);
  });

  testWidgets('insufficient stock lists every short book', (tester) async {
    sales.confirmResults.add(stockShort());
    await pumpDetail(tester);
    await confirmFromSheet(tester);

    expect(find.byKey(const Key('insufficient_stock_drawer')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const Key('insufficient_stock_drawer')),
        matching: find.text('English Reader P4'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('insufficient_stock_drawer')),
        matching: find.text('ENG-P4'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('insufficient_stock_drawer')),
        matching: find.text('Maths P4'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('insufficient_stock_drawer')),
        matching: find.text('MTH-P4'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('insufficient_stock_drawer')),
        matching: find.text('Need'),
      ),
      findsNWidgets(2),
    );
    expect(find.text('Add to stock'), findsWidgets);
  });

  testWidgets('credit warning: "Confirm anyway" retries with the override', (
    tester,
  ) async {
    sales.confirmResults.add(creditExceeded());
    await pumpDetail(tester);
    await confirmFromSheet(tester);

    expect(find.byKey(const Key('credit_warning_dialog')), findsOneWidget);
    expect(
      find.textContaining('= GHS 110.00, over the limit of GHS 100.00'),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const Key('credit_override')));
    await expectToast(
      tester,
      find.text('Invoice INV-2026-000001 issued'),
    );

    expect(sales.confirmCalls[1].options['override_credit_limit'], isTrue);
  });

  testWidgets('apply credit toggle appears only when the customer has credit', (
    tester,
  ) async {
    sales.sale = testSale(creditBalance: 500);
    await pumpDetail(tester);
    await tester.tap(find.byKey(const Key('sale_confirm')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('confirm_apply_credit')), findsOneWidget);
    await tester.tap(find.byKey(const Key('confirm_submit')));
    await tester.pumpAndSettle();
    expect(sales.confirmCalls.single.options['apply_credit'], isTrue);
  });

  testWidgets('save & confirm opens the confirm sheet straight away', (
    tester,
  ) async {
    await pumpDetail(tester, openConfirm: true);

    expect(find.byKey(const Key('confirm_submit')), findsOneWidget);
  });

  testWidgets('a lost connection says retrying is safe', (tester) async {
    sales.confirmResults.add(ApiException.network());
    await pumpDetail(tester);
    await tester.tap(find.byKey(const Key('sale_confirm')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('confirm_submit')));
    await expectToast(
      tester,
      find.textContaining('will not be recorded twice'),
    );
  });

  testWidgets(
    'confirmed sale: void needs a reason; invoice can be shared; record payment shortcut',
    (tester) async {
      sales.sale = testSale(status: 'confirmed', invoiceNo: 'INV-2026-000003');
      await pumpDetail(tester);

      await tester.tap(find.byKey(const Key('sale_share_invoice')));
      await tester.pumpAndSettle();
      expect(sharer.shared, ['INV-2026-000003.pdf']);

      await tester.tap(find.byKey(const Key('sale_more_actions')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('sale_void')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('reason_submit')));
      await tester.pumpAndSettle();
      expect(find.text('A reason is required'), findsOneWidget);

      await tester.enterText(
        find.byKey(const Key('reason_field')),
        'Wrong school',
      );
      await tester.tap(find.byKey(const Key('reason_submit')));
      await expectToast(tester, find.text('Invoice voided'));
      expect(find.text('Void'), findsOneWidget);
    },
  );

  testWidgets('record payment shortcut opens the customer payment screen', (
    tester,
  ) async {
    sales.sale = testSale(status: 'confirmed', invoiceNo: 'INV-2026-000003');
    await pumpDetail(tester);

    await tester.tap(find.byKey(const Key('sale_record_payment')));
    await tester.pumpAndSettle();

    expect(find.text('pay 1'), findsOneWidget);
  });

  group('a refused cancel/void/deliver refetches and shows the real state', () {
    ApiException conflict(String code) => ApiException(
      message: 'This sale is void and cannot be voided.',
      code: code,
      statusCode: 409,
    );

    testWidgets('void after it was already voided', (tester) async {
      sales.sale = testSale(status: 'confirmed', invoiceNo: 'INV-2026-000003');
      sales
        ..actionError = conflict('sale_not_editable')
        ..afterError = testSale(status: 'void', invoiceNo: 'INV-2026-000003');
      await pumpDetail(tester);

      await tester.tap(find.byKey(const Key('sale_more_actions')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('sale_void')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('reason_field')),
        'Retry after lost connection',
      );
      await tester.tap(find.byKey(const Key('reason_submit')));
      await expectToast(
        tester,
        find.text('This invoice was already voided.'),
      );
      expect(find.textContaining('Something went wrong'), findsNothing);
      expect(
        find.text('Void'),
        findsOneWidget,
        reason: 'status chip shows the refetched state',
      );
      expect(find.byKey(const Key('sale_more_actions')), findsNothing);
    });

    testWidgets('cancel after the draft was already cancelled', (tester) async {
      sales
        ..actionError = conflict('sale_not_editable')
        ..afterError = testSale(status: 'cancelled');
      await pumpDetail(tester);

      await tester.tap(find.byKey(const Key('sale_cancel')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('reason_submit')));
      await expectToast(
        tester,
        find.text('This draft was already cancelled.'),
      );
      expect(find.text('Cancelled'), findsOneWidget);
      expect(find.byKey(const Key('sale_confirm')), findsNothing);
    });

    testWidgets('deliver after the invoice was voided elsewhere', (
      tester,
    ) async {
      sales.sale = testSale(status: 'confirmed', invoiceNo: 'INV-2026-000004');
      sales
        ..actionError = conflict('sale_not_editable')
        ..afterError = testSale(status: 'void', invoiceNo: 'INV-2026-000004');
      await pumpDetail(tester);

      await tester.tap(find.byKey(const Key('sale_more_actions')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('sale_deliver')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('deliver_confirm')));
      await expectToast(
        tester,
        find.text('This invoice was voided, so it cannot be delivered.'),
      );
    });

    testWidgets(
      'a real failure (not a state conflict) is still shown as an error',
      (tester) async {
        sales.sale = testSale(
          status: 'confirmed',
          invoiceNo: 'INV-2026-000005',
        );
        sales.actionError = ApiException(
          message: 'Server error',
          statusCode: 500,
        );
        await pumpDetail(tester);

        await tester.tap(find.byKey(const Key('sale_more_actions')));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const Key('sale_deliver')));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const Key('deliver_confirm')));
        await expectToast(
          tester,
          find.textContaining('Something went wrong'),
        );
      },
    );
  });

  test('alreadyChangedMessage covers every action and falls back plainly', () {
    expect(
      alreadyChangedMessage(
        SaleAction.voidSale,
        testSale(status: 'confirmed', invoiceNo: 'I').copyDelivered(),
      ),
      'This invoice has been delivered, so it can no longer be voided.',
    );
    expect(
      alreadyChangedMessage(SaleAction.cancel, testSale(status: 'confirmed')),
      'This sale was confirmed meanwhile, so it can no longer be cancelled.',
    );
    expect(
      alreadyChangedMessage(
        SaleAction.deliver,
        testSale(status: 'confirmed').copyDelivered(),
      ),
      'This invoice was already marked as delivered.',
    );
    expect(
      alreadyChangedMessage(SaleAction.deliver, null),
      'This sale changed in the meantime. Showing its current state.',
    );
  });
}

extension on Sale {
  Sale copyDelivered() => Sale(
    id: id,
    invoiceNo: invoiceNo,
    customerId: customerId,
    customer: customer,
    status: status,
    paymentStatus: paymentStatus,
    saleDate: saleDate,
    subtotal: subtotal,
    discountTotal: discountTotal,
    total: total,
    amountPaid: amountPaid,
    balanceDue: balanceDue,
    deliveredAt: DateTime(2026, 10, 3),
    items: items,
  );
}
