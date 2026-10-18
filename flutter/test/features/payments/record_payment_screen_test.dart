import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/pdf_sharer.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/offline/presentation/offline_controller.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/payments/presentation/record_payment_screen.dart';

import '../../support/fakes.dart';

void main() {
  late FakePaymentsRepository payments;
  late InMemoryKeyValueStore backing;
  late FakePdfSharer sharer;
  late OfflineController offline;

  Future<void> pumpScreen(WidgetTester tester) async {
    // A tall phone-like surface so the lazily built ListView renders the whole form.
    tester.view.physicalSize = const Size(1080, 3200);
    tester.view.devicePixelRatio = 1.5;
    addTearDown(tester.view.reset);

    final router = GoRouter(
      initialLocation: '/customers/1/pay',
      routes: [
        GoRoute(
          path: '/customers/:id/pay',
          builder: (_, _) => RecordPaymentScreen(
            customerId: 1,
            now: () => DateTime(2026, 10, 2, 9, 30),
          ),
        ),
        GoRoute(
          path: '/payments/:id',
          builder: (_, s) =>
              Scaffold(body: Text('payment ${s.pathParameters['id']}')),
        ),
        GoRoute(
          path: '/payments',
          builder: (_, _) => const Scaffold(body: Text('payments list')),
        ),
      ],
    );
    await tester.pumpWidget(
      MultiProvider(
        providers: [
          Provider<PaymentsRepository>.value(value: payments),
          ChangeNotifierProvider<OfflineController>.value(value: offline),
          Provider<CustomersRepository>.value(value: FakeCustomersRepository()),
          Provider<PendingSubmissionStore>.value(
            value: PendingSubmissionStore(store: backing),
          ),
          Provider<PdfSharer>.value(value: sharer),
        ],
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> chooseMethod(WidgetTester tester, String label) async {
    await tester.tap(find.byKey(const Key('pay_method')));
    await tester.pumpAndSettle();
    await tester.tap(find.text(label).last);
    await tester.pumpAndSettle();
  }

  Future<void> submit(WidgetTester tester) async {
    await tester.ensureVisible(find.byKey(const Key('pay_submit')));
    await tester.tap(find.byKey(const Key('pay_submit')));
    await tester.pumpAndSettle();
    final confirm = find.byKey(const Key('pay_confirm_submit'));
    if (confirm.evaluate().isNotEmpty) {
      await tester.tap(confirm);
      await tester.pumpAndSettle();
    }
  }

  setUp(() {
    payments = FakePaymentsRepository(
      recent: [
        testPayment(id: 3, receiptNo: 'RCT-2026-000003', reference: 'MP-OLD'),
      ],
    );
    backing = InMemoryKeyValueStore();
    sharer = FakePdfSharer();
    offline = testOfflineStack(payments: payments).controller;
  });

  testWidgets(
    'shows the customer balances and recent payments to check first',
    (tester) async {
      await pumpScreen(tester);

      expect(find.text('Akwaaba Basic School'), findsOneWidget);
      expect(find.textContaining('Owes GHS 3,000.00'), findsOneWidget);
      expect(find.text('RECENT FROM THIS SCHOOL'), findsOneWidget);
      expect(find.byKey(const Key('recent_3')), findsOneWidget);
      expect(find.textContaining('MP-OLD'), findsOneWidget);
    },
  );

  testWidgets('amount must be an exact GHS amount', (tester) async {
    await pumpScreen(tester);

    for (final bad in ['', '1.234', '1,00', '0']) {
      await tester.enterText(find.byKey(const Key('pay_amount')), bad);
      await submit(tester);
      expect(payments.recordCalls, isEmpty, reason: 'accepted "$bad"');
    }
    expect(find.text('The amount must be more than zero'), findsOneWidget);
  });

  testWidgets('Mobile Money needs a reference; cash does not', (tester) async {
    await pumpScreen(tester);
    await tester.enterText(find.byKey(const Key('pay_amount')), '1,250.50');
    await chooseMethod(tester, 'Mobile Money');

    await submit(tester);
    expect(find.text('Enter the transaction or cheque number'), findsOneWidget);
    expect(payments.recordCalls, isEmpty);

    await tester.enterText(
      find.byKey(const Key('pay_reference')),
      'MP261002.77',
    );
    await submit(tester);

    expect(payments.recordCalls.single.payload, {
      'customer_id': 1,
      'amount': 125050,
      'method': 'momo',
      'reference': 'MP261002.77',
      'paid_at': DateTime(2026, 10, 2, 9, 30).toUtc().toIso8601String(),
      'notes': null,
      'auto_allocate': true,
    });
  });

  testWidgets('keep-as-credit sends auto_allocate false', (tester) async {
    await pumpScreen(tester);
    await tester.enterText(find.byKey(const Key('pay_amount')), '20');
    await tester.ensureVisible(find.byKey(const Key('pay_mode_credit')));
    await tester.tap(find.byKey(const Key('pay_mode_credit')));
    await submit(tester);

    expect(payments.recordCalls.single.payload['auto_allocate'], isFalse);
    expect(payments.recordCalls.single.payload['reference'], isNull);
  });

  testWidgets(
    'a duplicate reference shows the existing receipt and records nothing',
    (tester) async {
      payments.recordResults.add(
        ApiException(
          message: 'dup',
          code: 'duplicate_reference',
          statusCode: 409,
          details: const {
            'method': 'momo',
            'reference': 'MP-OLD',
            'existing_receipt_no': 'RCT-2026-000003',
            'existing_amount': 50000,
          },
        ),
      );
      await pumpScreen(tester);
      await tester.enterText(find.byKey(const Key('pay_amount')), '500');
      await chooseMethod(tester, 'Mobile Money');
      await tester.enterText(find.byKey(const Key('pay_reference')), 'MP-OLD');
      await submit(tester);

      expect(find.byKey(const Key('pay_error')), findsOneWidget);
      expect(find.text('Payment already recorded'), findsOneWidget);
      expect(find.textContaining('RCT-2026-000003'), findsWidgets);
    },
  );

  testWidgets(
    'after a lost connection the payment is queued offline with the same key',
    (tester) async {
      final stack = testOfflineStack(payments: payments, online: false);
      offline = stack.controller;
      await offline.start();

      payments.recordResults.add(ApiException.network());
      await pumpScreen(tester);
      await tester.enterText(find.byKey(const Key('pay_amount')), '500');
      await submit(tester);

      // Navigates away; queue owns the retry with the attempted key.
      expect(find.text('payments list'), findsOneWidget);
      expect(await stack.store.queueLength(), 1);
      expect(payments.recordCalls, hasLength(1));
      expect(
        (await stack.store.listQueue()).single.idempotencyKey,
        payments.recordCalls.single.key,
      );

      // Reconnect drains with that same Idempotency-Key.
      payments.recordResults.add(
        testPayment(id: 9, receiptNo: 'RCT-2026-000009'),
      );
      offline.online = true;
      final drained = await offline.kickSync();
      expect(drained.synced, 1);
      expect(await stack.store.queueLength(), 0);
      expect(payments.recordCalls, hasLength(2));
      expect(payments.recordCalls[1].key, payments.recordCalls[0].key);
    },
  );

  testWidgets(
    'an unfinished payment from before a restart can be restored and retried safely',
    (tester) async {
      final key = await PendingSubmissionStore(store: backing)
          .keyFor('record_payment.customer.1', {
            'customer_id': 1,
            'amount': 75000,
            'method': 'cheque',
            'reference': 'CHQ-12',
            'paid_at': DateTime.utc(2026, 10, 1, 15).toIso8601String(),
            'notes': null,
            'auto_allocate': true,
          }, now: DateTime(2026, 10, 1, 15));

      await pumpScreen(tester);

      expect(find.byKey(const Key('pay_unfinished')), findsOneWidget);
      expect(
        find.textContaining('GHS 750.00 by Cheque (CHQ-12)'),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('pay_unfinished_restore')));
      await tester.pumpAndSettle();
      expect(find.widgetWithText(TextFormField, '750.00'), findsOneWidget);

      await submit(tester);

      expect(payments.recordCalls.single.key, key);
      expect(payments.recordCalls.single.payload['reference'], 'CHQ-12');
    },
  );

  testWidgets('discarding the unfinished banner forgets it', (tester) async {
    await PendingSubmissionStore(store: backing)
        .keyFor('record_payment.customer.1', {'amount': 1});
    await pumpScreen(tester);

    await tester.tap(find.byKey(const Key('pay_unfinished_discard')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('pay_unfinished')), findsNothing);
    expect(backing.values, isEmpty);
  });
}
