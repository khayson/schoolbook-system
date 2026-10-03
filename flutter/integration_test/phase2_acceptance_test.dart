// Phase 2 acceptance (docs/acceptance-phase2.md), driven through the real app on a
// device/emulator against a live API seeded with AcceptanceSeeder.
//
//   flutter test integration_test/phase2_acceptance_test.dart -d emulator-5554 \
//     --dart-define=API_BASE_URL=http://10.0.2.2:8001/api/v1
//
// Every money figure is checked against the API (pesewas), not just the screen.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:integration_test/integration_test.dart';
import 'package:schoolbook/app.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/sales/domain/sale.dart';

const ownerEmail = String.fromEnvironment('OWNER_EMAIL', defaultValue: 'owner@schoolbook.test');
const ownerPassword = String.fromEnvironment('OWNER_PASSWORD', defaultValue: 'password');

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('Phase 2 end to end: school, bulk order, invoices, instalments, credit, void', (tester) async {
    final runId = DateTime.now().millisecondsSinceEpoch.toString().substring(7);
    final schoolName = 'Acceptance Academy $runId';
    final log = <String>[];
    void step(String s) {
      log.add(s);
      debugPrint('ACCEPTANCE: $s');
    }

    await tester.pumpWidget(const SchoolbookApp());
    // Either the login form or (with a stored token) the dashboard.
    await _waitFor(
      tester,
      find.byWidgetPredicate((w) => w.key == const Key('login_email') || (w is Text && w.data == 'Quick actions')),
    );

    // Start signed out so the run never uses a token from another server.
    if (find.byTooltip('Sign out').evaluate().isNotEmpty) {
      await tester.tap(find.byTooltip('Sign out'));
      await _settle(tester);
    }

    // 1. Log in --------------------------------------------------------------------------
    await _waitFor(tester, find.byKey(const Key('login_email')));
    await tester.enterText(find.byKey(const Key('login_email')), ownerEmail);
    await tester.enterText(find.byKey(const Key('login_password')), ownerPassword);
    await tester.tap(find.byKey(const Key('login_submit')));
    await _waitFor(tester, find.text('Quick actions'));
    step('1 logged in as $ownerEmail');

    final api = ApiClient(tokenStore: AuthTokenStore());
    final products = ProductsRepository(apiClient: api);
    final customers = CustomersRepository(apiClient: api);
    final sales = SalesRepository(apiClient: api);

    Future<Product> book(String sku) async => (await products.listProducts(search: sku)).data.single;
    final english = await book('ACC-ENG-P4');
    final maths = await book('ACC-MTH-P4');
    final science = await book('ACC-SCI-P4');
    final stockBefore = {english.id: english.stockOnHand, maths.id: maths.stockOnHand, science.id: science.stockOnHand};

    // 2. Create a school -----------------------------------------------------------------
    _go(tester, '/customers/new');
    await _waitFor(tester, find.byKey(const Key('cust_name')));
    await tester.enterText(find.byKey(const Key('cust_name')), schoolName);
    await tester.tap(find.text('Region'));
    await _settle(tester);
    await tester.tap(find.text('Greater Accra').last);
    await _settle(tester);
    await tester.tap(find.byKey(const Key('cust_save')));
    await _waitFor(tester, find.byKey(const Key('customer_record_payment')));
    final school = (await customers.listCustomers(search: schoolName)).data.single;
    expect(school.code, startsWith('CUS-'));
    step('2 created ${school.code} $schoolName');

    // 3-4. Bulk order: draft and confirm ---------------------------------------------------
    final invoice1 = await _newSaleAndConfirm(tester, school, [(english, 40), (maths, 20)], expectedTotal: 'GHS 1,800.00');
    expect(invoice1.total, 180000);
    expect((await book('ACC-ENG-P4')).stockOnHand, stockBefore[english.id]! - 40);
    expect((await book('ACC-MTH-P4')).stockOnHand, stockBefore[maths.id]! - 20);
    step('3-4 bulk order confirmed as ${invoice1.invoiceNo} GHS 1,800.00; stock dropped 40 + 20');

    final invoice2 = await _newSaleAndConfirm(tester, school, [(science, 30)], expectedTotal: 'GHS 450.00');
    expect(invoice2.total, 45000);
    step('4b second invoice ${invoice2.invoiceNo} GHS 450.00');

    // 5. Instalment 1: GHS 2,000 cash, auto-allocated across both invoices -----------------
    await _recordPayment(tester, school, amount: '2,000');
    expect(find.text('Applied to invoices: GHS 2,000.00\nKept as credit: GHS 0.00'), findsOneWidget);
    await tester.tap(find.text('Done'));
    await _settle(tester);
    var s1 = await sales.getSale(invoice1.id);
    var s2 = await sales.getSale(invoice2.id);
    expect(s1.balanceDue, 0);
    expect(s1.paymentStatus, 'paid');
    expect(s2.balanceDue, 25000);
    expect(s2.paymentStatus, 'partial');
    step('5 instalment 1 GHS 2,000.00: ${invoice1.invoiceNo} paid, ${invoice2.invoiceNo} part paid (GHS 250.00 left)');

    // 6. Instalment 2: GHS 300 MoMo overpays by GHS 50 -> credit ---------------------------
    await _recordPayment(tester, school, amount: '300', momoReference: 'MP-ACC-$runId');
    expect(find.text('Applied to invoices: GHS 250.00\nKept as credit: GHS 50.00'), findsOneWidget);
    await tester.tap(find.text('Done'));
    await _settle(tester);
    s2 = await sales.getSale(invoice2.id);
    var c = await customers.getCustomer(school.id);
    expect(s2.paymentStatus, 'paid');
    expect(c.creditBalance, 5000);
    expect(c.outstandingBalance, 0);
    step('6 instalment 2 GHS 300.00 MoMo: ${invoice2.invoiceNo} paid, GHS 50.00 credit');

    // 7. Apply credit to a new invoice ------------------------------------------------------
    final invoice3 = await _newSaleAndConfirm(tester, school, [(english, 4)], expectedTotal: 'GHS 100.00', turnOffApplyCredit: true);
    expect(invoice3.balanceDue, 10000);
    _go(tester, '/customers/${school.id}');
    await _waitFor(tester, find.byKey(const Key('customer_record_payment')));
    await _scrollToAndTap(tester, find.byKey(const Key('customer_apply_credit')));
    await _settle(tester);
    await tester.tap(find.byKey(const Key('apply_credit_confirm')));
    await _waitFor(tester, find.text('Applied GHS 50.00. Credit left GHS 0.00.'));
    var s3 = await sales.getSale(invoice3.id);
    c = await customers.getCustomer(school.id);
    expect(s3.balanceDue, 5000);
    expect(c.creditBalance, 0);
    expect(c.outstandingBalance, 5000);
    step('7 credit GHS 50.00 applied to ${invoice3.invoiceNo}; GHS 50.00 left to pay');

    // 8. Void the invoice: balances and stock reverse --------------------------------------
    _go(tester, '/sales/${invoice3.id}');
    await _waitFor(tester, find.text(invoice3.invoiceNo!)); // app bar title once the sale has loaded
    await _scrollToAndTap(tester, find.byKey(const Key('sale_void')));
    await _settle(tester);
    await tester.enterText(find.byKey(const Key('reason_field')), 'Acceptance: ordered in error');
    await tester.tap(find.byKey(const Key('reason_submit')));
    await _waitFor(tester, find.text('Invoice voided'));
    s3 = await sales.getSale(invoice3.id);
    c = await customers.getCustomer(school.id);
    expect(s3.status, 'void');
    expect(s3.amountPaid, 0);
    expect(c.creditBalance, 5000);
    expect(c.outstandingBalance, 0);
    expect((await book('ACC-ENG-P4')).stockOnHand, stockBefore[english.id]! - 40);
    step('8 voided ${invoice3.invoiceNo}: credit back to GHS 50.00, owes GHS 0.00, 4 books back in stock');

    debugPrint('ACCEPTANCE RESULT: PASS\n${log.join('\n')}');
  });
}

Future<Sale> _newSaleAndConfirm(
  WidgetTester tester,
  Customer school,
  List<(Product, int)> lines, {
  required String expectedTotal,
  bool turnOffApplyCredit = false,
}) async {
  _go(tester, '/sales/new');
  await _waitFor(tester, find.byKey(const Key('new_sale_customer')));

  await tester.tap(find.byKey(const Key('new_sale_customer')));
  await _settle(tester);
  await tester.enterText(find.byKey(const Key('customer_picker_search')), school.name);
  await _waitFor(tester, find.byKey(Key('pick_customer_${school.id}')));
  await tester.tap(find.byKey(Key('pick_customer_${school.id}')));
  await _settle(tester);

  await tester.tap(find.byKey(const Key('new_sale_add_books')));
  await _settle(tester);
  for (final (product, _) in lines) {
    await tester.enterText(find.byKey(const Key('product_picker_search')), product.sku);
    await _waitFor(tester, find.byKey(Key('pick_product_${product.id}')));
    await tester.tap(find.byKey(Key('pick_product_${product.id}')));
    await _settle(tester);
  }
  await tester.tap(find.text('Done'));
  await _settle(tester);

  for (final (product, quantity) in lines) {
    final field = find.descendant(of: find.byKey(Key('line_${product.id}')), matching: find.byKey(const Key('qty_field')));
    await tester.enterText(field, '$quantity');
    await _settle(tester);
  }
  await _waitFor(tester, find.text(expectedTotal));

  // Pinned bottom bar: the main action is on screen without scrolling.
  await tester.tap(find.byKey(const Key('new_sale_save_confirm')));
  await _waitFor(tester, find.byKey(const Key('confirm_submit')));
  if (turnOffApplyCredit && find.byKey(const Key('confirm_apply_credit')).evaluate().isNotEmpty) {
    await tester.tap(find.byKey(const Key('confirm_apply_credit')));
    await _settle(tester);
  }
  await tester.tap(find.byKey(const Key('confirm_submit')));
  await _waitFor(tester, find.textContaining(RegExp(r'^Invoice INV-\d{4}-\d{6} issued$')));

  final text = (find.textContaining(RegExp(r'^Invoice INV-')).evaluate().first.widget as Text).data!;
  final invoiceNo = RegExp(r'INV-\d{4}-\d{6}').firstMatch(text)!.group(0)!;
  final repo = SalesRepository(apiClient: ApiClient(tokenStore: AuthTokenStore()));
  final sale = (await repo.listSales(status: 'confirmed')).data.firstWhere((s) => s.invoiceNo == invoiceNo);
  expect(sale.customerId, school.id);
  return repo.getSale(sale.id);
}

Future<void> _recordPayment(WidgetTester tester, Customer school, {required String amount, String? momoReference}) async {
  _go(tester, '/customers/${school.id}/pay');
  await _waitFor(tester, find.byKey(const Key('pay_amount')));
  await tester.enterText(find.byKey(const Key('pay_amount')), amount);
  if (momoReference != null) {
    await tester.tap(find.byKey(const Key('pay_method')));
    await _settle(tester);
    await tester.tap(find.text('Mobile Money').last);
    await _settle(tester);
    await tester.enterText(find.byKey(const Key('pay_reference')), momoReference);
  }
  await _scrollToAndTap(tester, find.byKey(const Key('pay_submit')));
  await _waitFor(tester, find.byKey(const Key('pay_recorded_dialog')));
}

/// Detail screens build lazily: close the keyboard (on a real phone it shrinks the
/// list and can drop off-screen items again), scroll the screen's vertical list until
/// [finder] exists, then tap it straight away.
Future<void> _scrollToAndTap(WidgetTester tester, Finder finder) async {
  FocusManager.instance.primaryFocus?.unfocus();
  await _settle(tester);
  await tester.scrollUntilVisible(
    finder,
    300,
    scrollable: find.byWidgetPredicate((w) => w is Scrollable && w.axisDirection == AxisDirection.down).first,
  );
  await tester.pump();
  await tester.tap(finder);
}

void _go(WidgetTester tester, String location) {
  GoRouter.of(tester.element(find.byType(Navigator).first)).go(location);
}

/// pumpAndSettle with a cap, so spinners waiting on the network cannot hang the run.
Future<void> _settle(WidgetTester tester) async {
  try {
    await tester.pumpAndSettle(const Duration(milliseconds: 100), EnginePhase.sendSemanticsUpdate, const Duration(seconds: 10));
  } on FlutterError {
    // Still animating (e.g. a progress indicator): carry on and let _waitFor poll.
  }
}

Future<void> _waitFor(WidgetTester tester, Finder finder, {Duration timeout = const Duration(seconds: 30)}) async {
  final end = DateTime.now().add(timeout);
  while (DateTime.now().isBefore(end)) {
    await tester.pump(const Duration(milliseconds: 200));
    if (finder.evaluate().isNotEmpty) {
      await _settle(tester);
      return;
    }
  }
  throw TestFailure('Timed out waiting for $finder');
}
