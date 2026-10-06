import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/pdf_sharer.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/presentation/customer_detail_screen.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/sales/domain/sale_summary.dart';

import '../../support/fakes.dart';

class StatementCustomersRepository extends FakeCustomersRepository {
  final List<({int customerId, String from, String to})> statementCalls = [];

  @override
  Future<List<SaleSummary>> recentSales(
    int customerId, {
    int perPage = 10,
  }) async => const [];

  @override
  Future<List<int>> statementPdf(
    int customerId, {
    required String from,
    required String to,
  }) async {
    statementCalls.add((customerId: customerId, from: from, to: to));
    return [37, 80, 68, 70];
  }
}

void main() {
  testWidgets('share statement asks for a period and shares the PDF for it', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);

    final customers = StatementCustomersRepository();
    final sharer = FakePdfSharer();
    final router = GoRouter(
      initialLocation: '/customers/1',
      routes: [
        GoRoute(
          path: '/customers/:id',
          builder: (_, _) => const CustomerDetailScreen(customerId: 1),
        ),
      ],
    );
    await tester.pumpWidget(
      MultiProvider(
        providers: [
          Provider<CustomersRepository>.value(value: customers),
          Provider<PaymentsRepository>.value(value: FakePaymentsRepository()),
          Provider<PendingSubmissionStore>.value(
            value: PendingSubmissionStore(store: InMemoryKeyValueStore()),
          ),
          Provider<PdfSharer>.value(value: sharer),
        ],
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('customer_statement')));
    await tester.pumpAndSettle();
    // The range picker opens on this month; accept it.
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    final now = DateTime.now();
    String d(DateTime x) =>
        '${x.year}-${x.month.toString().padLeft(2, '0')}-${x.day.toString().padLeft(2, '0')}';
    final from = d(DateTime(now.year, now.month));
    final to = d(now);
    expect(customers.statementCalls.single, (
      customerId: 1,
      from: from,
      to: to,
    ));
    expect(sharer.shared.single, 'statement-CUS-0001-$from-$to.pdf');
  });
}
