import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/features/catalog/data/lookups_repository.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/sales/presentation/new_sale_screen.dart';

import '../../support/fakes.dart';

void main() {
  late FakeSalesRepository sales;
  final books = [
    for (var i = 1; i <= 8; i++) testProduct(id: 100 + i, title: 'Book $i'),
  ];

  /// Phone-sized surface (about 412 x 915 dp), so a long order really is longer than the screen.
  Future<void> pumpNewSale(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.625;
    addTearDown(tester.view.reset);

    final router = GoRouter(
      initialLocation: '/sales/new',
      routes: [
        GoRoute(
          path: '/sales/new',
          builder: (_, _) =>
              const NewSaleScreen(previewDebounce: Duration(milliseconds: 10)),
        ),
        GoRoute(
          path: '/sales/:id',
          builder: (_, s) => Scaffold(
            body: Text(
              'sale ${s.pathParameters['id']} confirm=${s.uri.queryParameters['confirm']}',
            ),
          ),
        ),
      ],
    );
    await tester.pumpWidget(
      MultiProvider(
        providers: [
          Provider<SalesRepository>.value(value: sales),
          Provider<PendingSubmissionStore>.value(
            value: PendingSubmissionStore(store: InMemoryKeyValueStore()),
          ),
          Provider<CustomersRepository>.value(
            value: PickableCustomersRepository(),
          ),
          Provider<ProductsRepository>.value(
            value: FakeProductsRepository(books),
          ),
          Provider<LookupsRepository>.value(value: FakeLookupsRepository()),
        ],
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> chooseCustomer(WidgetTester tester) async {
    await tester.tap(find.byKey(const Key('new_sale_customer')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('pick_customer_1')));
    await tester.pumpAndSettle();
  }

  Future<void> addAllBooks(WidgetTester tester) async {
    await tester.tap(find.byKey(const Key('new_sale_add_books')));
    await tester.pumpAndSettle();
    for (final b in books) {
      await tester.scrollUntilVisible(
        find.byKey(Key('pick_product_${b.id}')),
        200,
        scrollable: find
            .descendant(
              of: find.byType(BottomSheet),
              matching: find.byType(Scrollable),
            )
            .last,
      );
      await tester.tap(find.byKey(Key('pick_product_${b.id}')));
      await tester.pump();
    }
    await tester.tap(find.text('Done'));
    await tester.pumpAndSettle();
  }

  bool onScreen(WidgetTester tester, Finder finder) =>
      finder.hitTestable().evaluate().isNotEmpty;

  setUp(() => sales = FakeSalesRepository());

  testWidgets(
    'the total and both save buttons stay on screen however long the order',
    (tester) async {
      await pumpNewSale(tester);

      // Empty order: bar is there, actions disabled until there is a customer and a book.
      expect(onScreen(tester, find.byKey(const Key('new_sale_bar'))), isTrue);
      expect(
        tester
            .widget<FilledButton>(
              find.byKey(const Key('new_sale_save_confirm')),
            )
            .onPressed,
        isNull,
      );

      await chooseCustomer(tester);
      await addAllBooks(tester);
      await tester.pumpAndSettle();

      // The 8 lines are longer than the screen ...
      expect(onScreen(tester, find.byKey(const Key('line_108'))), isFalse);
      // ... yet the server-priced total and both actions are visible without scrolling.
      expect(onScreen(tester, find.byKey(const Key('new_sale_total'))), isTrue);
      expect(find.text('GHS 80.00'), findsOneWidget);
      expect(find.text('Total for 8 books'), findsOneWidget);
      expect(
        onScreen(tester, find.byKey(const Key('new_sale_save_confirm'))),
        isTrue,
      );
      expect(onScreen(tester, find.byKey(const Key('new_sale_save'))), isTrue);

      // Scrolling the list does not move the bar.
      await tester.drag(find.byType(ListView).first, const Offset(0, -2000));
      await tester.pumpAndSettle();
      expect(onScreen(tester, find.byKey(const Key('line_108'))), isTrue);
      expect(
        onScreen(tester, find.byKey(const Key('new_sale_save_confirm'))),
        isTrue,
      );
    },
  );

  testWidgets('quantities update the server-priced total in the bar', (
    tester,
  ) async {
    await pumpNewSale(tester);
    await chooseCustomer(tester);
    await tester.tap(find.byKey(const Key('new_sale_add_books')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('pick_product_101')));
    await tester.pump();
    await tester.tap(find.text('Done'));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('line_101')),
        matching: find.byKey(const Key('qty_field')),
      ),
      '40',
    );
    await tester.pumpAndSettle();

    expect(find.text('GHS 400.00'), findsOneWidget);
    expect(find.text('Total for 40 books'), findsOneWidget);
    expect(sales.previewCalls.last, [
      {'product_id': 101, 'quantity': 40},
    ]);
  });

  testWidgets(
    'Save & confirm from the bar saves the draft and opens it with the confirm sheet',
    (tester) async {
      sales.createResults.add(testSale(id: 41));
      await pumpNewSale(tester);
      await chooseCustomer(tester);
      await addAllBooks(tester);

      await tester.tap(find.byKey(const Key('new_sale_save_confirm')));
      await tester.pumpAndSettle();

      expect(sales.createCalls.single.payload['items'], hasLength(8));
      expect(find.text('sale 41 confirm=1'), findsOneWidget);
    },
  );

  testWidgets(
    'Save draft from the bar opens the draft without the confirm sheet',
    (tester) async {
      sales.createResults.add(testSale(id: 42));
      await pumpNewSale(tester);
      await chooseCustomer(tester);
      await addAllBooks(tester);

      await tester.tap(find.byKey(const Key('new_sale_save')));
      await tester.pumpAndSettle();

      expect(find.text('sale 42 confirm=null'), findsOneWidget);
    },
  );
}
