import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/pdf_sharer.dart';
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/sales/presentation/sale_detail_screen.dart';

import '../../support/fakes.dart';
import 'sale_actions_controller_test.dart' show creditExceeded, priceChanged, stockShort;

void main() {
  late FakeSalesRepository sales;
  late FakePdfSharer sharer;

  Future<void> pumpDetail(WidgetTester tester, {bool openConfirm = false}) async {
    tester.view.physicalSize = const Size(1080, 3200);
    tester.view.devicePixelRatio = 1.5;
    addTearDown(tester.view.reset);

    final router = GoRouter(
      initialLocation: '/sales/5',
      routes: [
        GoRoute(path: '/sales/:id', builder: (_, _) => SaleDetailScreen(saleId: 5, openConfirm: openConfirm)),
        GoRoute(path: '/customers/:id/pay', builder: (_, s) => Scaffold(body: Text('pay ${s.pathParameters['id']}'))),
      ],
    );
    await tester.pumpWidget(
      MultiProvider(
        providers: [
          Provider<SalesRepository>.value(value: sales),
          Provider<PendingSubmissionStore>.value(value: PendingSubmissionStore(store: InMemoryKeyValueStore())),
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

  setUp(() {
    sales = FakeSalesRepository();
    sharer = FakePdfSharer();
  });

  testWidgets('confirming a draft issues the invoice', (tester) async {
    await pumpDetail(tester);
    expect(find.text('English Reader P4'), findsOneWidget);

    await confirmFromSheet(tester);

    expect(find.text('Invoice INV-2026-000001 issued'), findsOneWidget);
    expect(find.byKey(const Key('sale_confirm')), findsNothing);
    expect(find.byKey(const Key('sale_void')), findsOneWidget);
  });

  testWidgets('price changed: diff dialog, accept re-prices then confirms with a new key', (tester) async {
    sales.confirmResults.add(priceChanged());
    sales.repriced = testSale(total: 3600, updatedAt: '2026-10-02T09:05:00.000000Z');
    await pumpDetail(tester);

    await confirmFromSheet(tester);

    expect(find.byKey(const Key('price_changed_dialog')), findsOneWidget);
    expect(find.text('English Reader P4: GHS 10.00 -> GHS 12.00'), findsOneWidget);
    expect(find.text('Total: GHS 30.00 -> GHS 36.00'), findsOneWidget);

    await tester.tap(find.byKey(const Key('accept_new_prices')));
    await tester.pumpAndSettle();

    expect(sales.updateCalls.single, isEmpty);
    expect(sales.confirmCalls, hasLength(2));
    expect(sales.confirmCalls[1].key, isNot(sales.confirmCalls[0].key));
    expect(find.text('Invoice INV-2026-000001 issued'), findsOneWidget);
  });

  testWidgets('price changed: "Not now" leaves the draft untouched', (tester) async {
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

    expect(find.byKey(const Key('insufficient_stock_dialog')), findsOneWidget);
    expect(find.text('ENG-P4 English Reader P4: need 3, have 1'), findsOneWidget);
    expect(find.text('MTH-P4 Maths P4: need 2, have 0'), findsOneWidget);
  });

  testWidgets('credit warning: "Confirm anyway" retries with the override', (tester) async {
    sales.confirmResults.add(creditExceeded());
    await pumpDetail(tester);
    await confirmFromSheet(tester);

    expect(find.byKey(const Key('credit_warning_dialog')), findsOneWidget);
    expect(find.textContaining('= GHS 110.00, over the limit of GHS 100.00'), findsOneWidget);

    await tester.tap(find.byKey(const Key('credit_override')));
    await tester.pumpAndSettle();

    expect(sales.confirmCalls[1].options['override_credit_limit'], isTrue);
    expect(find.text('Invoice INV-2026-000001 issued'), findsOneWidget);
  });

  testWidgets('apply credit toggle appears only when the customer has credit', (tester) async {
    sales.sale = testSale(creditBalance: 500);
    await pumpDetail(tester);
    await tester.tap(find.byKey(const Key('sale_confirm')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('confirm_apply_credit')), findsOneWidget);
    await tester.tap(find.byKey(const Key('confirm_submit')));
    await tester.pumpAndSettle();
    expect(sales.confirmCalls.single.options['apply_credit'], isTrue);
  });

  testWidgets('save & confirm opens the confirm sheet straight away', (tester) async {
    await pumpDetail(tester, openConfirm: true);

    expect(find.byKey(const Key('confirm_submit')), findsOneWidget);
  });

  testWidgets('a lost connection says retrying is safe', (tester) async {
    sales.confirmResults.add(ApiException.network());
    await pumpDetail(tester);
    await confirmFromSheet(tester);

    expect(find.textContaining('will not be recorded twice'), findsOneWidget);
  });

  testWidgets('confirmed sale: void needs a reason; invoice can be shared; record payment shortcut', (tester) async {
    sales.sale = testSale(status: 'confirmed', invoiceNo: 'INV-2026-000003');
    await pumpDetail(tester);

    await tester.tap(find.byKey(const Key('sale_share_invoice')));
    await tester.pumpAndSettle();
    expect(sharer.shared, ['INV-2026-000003.pdf']);

    await tester.tap(find.byKey(const Key('sale_void')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('reason_submit')));
    await tester.pumpAndSettle();
    expect(find.text('A reason is required'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('reason_field')), 'Wrong school');
    await tester.tap(find.byKey(const Key('reason_submit')));
    await tester.pumpAndSettle();
    expect(find.text('Invoice voided'), findsOneWidget);
    expect(find.text('Void'), findsOneWidget);
  });

  testWidgets('record payment shortcut opens the customer payment screen', (tester) async {
    sales.sale = testSale(status: 'confirmed', invoiceNo: 'INV-2026-000003');
    await pumpDetail(tester);

    await tester.tap(find.byKey(const Key('sale_record_payment')));
    await tester.pumpAndSettle();

    expect(find.text('pay 1'), findsOneWidget);
  });
}
