import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/stock/data/stock_counts_repository.dart';
import 'package:schoolbook/features/stock/domain/stock_count.dart';
import 'package:schoolbook/features/stock/presentation/stock_count_controller.dart';
import 'package:schoolbook/features/stock/presentation/stock_count_screen.dart';
import 'package:schoolbook/features/stock/presentation/stock_counts_screen.dart';

import '../../support/fakes.dart';

/// Plays the server's part for count K1 (docs/acceptance-phase3.md 3.10): it records
/// system_qty from [stock] at entry time and returns the variance. The app never
/// computes either.
class FakeStockCountsRepository extends StockCountsRepository {
  FakeStockCountsRepository() : super(apiClient: unusedApiClient());

  final Map<int, int> stock = {1: 86, 2: 44, 3: 34, 4: 20, 5: -1, 6: 9};
  final Map<int, ({int system, int counted})> entries = {};
  final List<({int productId, int? qty})> enterCalls = [];
  String status = 'open';
  int created = 0;
  ApiException? nextError;

  static const titles = {
    1: 'Maths P4',
    2: 'Science P4',
    3: 'Maths JHS1',
    4: 'Science JHS1',
    5: 'Maths P4 Workbook',
    6: 'Science JHS1 Workbook',
  };

  StockCount current() => StockCount(
    id: 1,
    reference: 'CNT-2026-000001',
    status: status,
    createdAt: DateTime(2026, 7, 1, 9),
    items: [
      for (final id in titles.keys)
        StockCountItem(
          productId: id,
          sku: 'RPT-${'ABCDEF'[id - 1]}',
          title: titles[id]!,
          systemQty: entries[id]?.system,
          countedQty: entries[id]?.counted,
          variance: entries[id] == null
              ? null
              : entries[id]!.counted - entries[id]!.system,
        ),
    ],
    totals: StockCountTotals(
      items: 6,
      counted: entries.length,
      varianceUnits: entries.values.fold(0, (s, e) => s + e.counted - e.system),
      varianceValue: 0,
    ),
  );

  @override
  Future<List<StockCount>> openCounts() async =>
      status == 'open' && created > 0 ? [current()] : [];

  @override
  Future<StockCount> create() async {
    created++;
    return current();
  }

  @override
  Future<StockCount> get(int id) async => current();

  @override
  Future<StockCount> enter(int countId, int productId, int? countedQty) async {
    enterCalls.add((productId: productId, qty: countedQty));
    final error = nextError;
    if (error != null) {
      nextError = null;
      throw error;
    }
    if (countedQty == null) {
      entries.remove(productId);
    } else {
      entries[productId] = (system: stock[productId]!, counted: countedQty);
    }
    return current();
  }
}

void main() {
  late FakeStockCountsRepository repo;

  setUp(() => repo = FakeStockCountsRepository());

  test('controller: entries, a sale, a re-entry; progress and the variances filter', () async {
    final c = StockCountController(repository: repo, countId: 1);
    await c.load();
    expect(c.totalItems, 6);
    expect(c.countedItems, 0);

    for (final e in {1: 84, 2: 44, 3: 30, 4: 21, 6: 8}.entries) {
      expect(await c.enter(e.key, e.value), isNull);
    }
    expect(c.countedItems, 5);
    expect(c.progress, closeTo(5 / 6, 1e-9));
    expect(c.itemFor(3)!.variance, -4);

    // 11:00 sale of C x2; 11:30 C re-entered: the server's new system quantity is used.
    repo.stock[3] = 32;
    await c.enter(3, 31);
    expect([c.itemFor(3)!.systemQty, c.itemFor(3)!.variance], [32, -1]);

    c.setFilter(CountFilter.variances);
    expect(c.visibleItems.map((i) => i.sku), [
      'RPT-A',
      'RPT-C',
      'RPT-D',
      'RPT-F',
    ]);
    c.setFilter(CountFilter.uncounted);
    expect(c.visibleItems.map((i) => i.sku), ['RPT-E']);
    c.setFilter(CountFilter.all);
    c.setQuery('workbook');
    expect(c.visibleItems.map((i) => i.sku), ['RPT-E', 'RPT-F']);
  });

  test('controller: a closed count is reported, not hidden', () async {
    final c = StockCountController(repository: repo, countId: 1);
    await c.load();
    repo.nextError = ApiException(
      message: 'closed',
      statusCode: 409,
      code: 'stock_count_not_open',
      details: {'status': 'applied'},
    );
    final error = await c.enter(1, 84);
    expect(error, isNotNull);
    expect(c.itemFor(1)!.isCounted, isFalse);
  });

  Future<void> pumpScreen(
    WidgetTester tester, {
    String initial = '/stock/counts/1',
  }) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    final router = GoRouter(
      initialLocation: initial,
      routes: [
        GoRoute(
          path: '/stock/counts',
          builder: (_, _) => const StockCountsScreen(),
          routes: [
            GoRoute(
              path: ':id',
              builder: (_, s) =>
                  StockCountScreen(countId: int.parse(s.pathParameters['id']!)),
            ),
          ],
        ),
      ],
    );
    await tester.pumpWidget(
      Provider<StockCountsRepository>.value(
        value: repo,
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets(
    'entering a count from the list updates progress and shows the variance',
    (tester) async {
      await pumpScreen(tester);
      expect(find.text('0 of 6 counted'), findsOneWidget);
      expect(
        find.text('Applying the count is done from the web admin.'),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('count_item_1')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('count_qty_field')), '84');
      await tester.tap(find.byKey(const Key('count_qty_save')));
      await tester.pumpAndSettle();

      expect(repo.enterCalls.single, (productId: 1, qty: 84));
      expect(find.text('1 of 6 counted'), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const Key('count_item_1')),
          matching: find.text('-2'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('count_item_1')),
          matching: find.text('RPT-A | system 86'),
        ),
        findsOneWidget,
      );

      // Invalid input is refused in the dialog, nothing is sent.
      await tester.tap(find.byKey(const Key('count_item_2')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('count_qty_save')));
      await tester.pumpAndSettle();
      expect(find.text('Enter a whole number, 0 or more.'), findsOneWidget);
      expect(repo.enterCalls, hasLength(1));
    },
  );

  testWidgets('search narrows the list; a closed count cannot be edited', (
    tester,
  ) async {
    repo.status = 'applied';
    await pumpScreen(tester);
    expect(
      find.text('This count is applied; entries are closed.'),
      findsOneWidget,
    );
    expect(find.byKey(const Key('count_scan')), findsNothing);

    await tester.enterText(find.byKey(const Key('count_search')), 'jhs1');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('count_item_3')), findsOneWidget);
    expect(find.byKey(const Key('count_item_1')), findsNothing);

    await tester.tap(find.byKey(const Key('count_item_3')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('count_qty_field')), findsNothing);
  });

  testWidgets('starting a new count opens it; it then appears as open', (
    tester,
  ) async {
    await pumpScreen(tester, initial: '/stock/counts');
    expect(find.text('No open counts'), findsOneWidget);

    await tester.tap(find.byKey(const Key('start_count')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('start_count_confirm')));
    await tester.pumpAndSettle();
    expect(find.text('0 of 6 counted'), findsOneWidget);

    tester.state<NavigatorState>(find.byType(Navigator).last).pop();
    await tester.pumpAndSettle();
    expect(find.text('CNT-2026-000001'), findsOneWidget);
  });
}
