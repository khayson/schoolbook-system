import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/reports/data/reports_repository.dart';
import 'package:schoolbook/features/reports/domain/report_models.dart';
import 'package:schoolbook/features/reports/presentation/dashboard_cards.dart';
import 'package:schoolbook/features/reports/presentation/light_reports_screens.dart';

import '../../support/fakes.dart';

/// docs/acceptance-phase3.md 3.7 and 3.8 (as of 2026-06-30), as the API returns them.
Map<String, dynamic> dashboardJson() => {
  'date': '2026-06-30',
  'sales_today': {'count': 0, 'revenue': 0},
  'sales_month': {'count': 5, 'revenue': 96000},
  'collections_today': 0,
  'collections_month': 81000,
  'owed': 49000,
  'overdue': 32000,
  'credit': 2000,
  'low_stock_count': 3,
  'top_sellers': <dynamic>[],
};

Map<String, dynamic> agingJson() => {
  'as_of': '2026-06-30',
  'rows': [
    {
      'customer_id': 1,
      'name': 'Alpha School',
      'not_yet_due': 12000,
      'days_1_30': 0,
      'days_31_60': 0,
      'days_61_90': 0,
      'days_90_plus': 0,
      'total': 12000,
    },
    {
      'customer_id': 2,
      'name': 'Beta School',
      'not_yet_due': 5000,
      'days_1_30': 4000,
      'days_31_60': 6000,
      'days_61_90': 10000,
      'days_90_plus': 12000,
      'total': 37000,
    },
  ],
  'totals': {
    'not_yet_due': 17000,
    'days_1_30': 4000,
    'days_31_60': 6000,
    'days_61_90': 10000,
    'days_90_plus': 12000,
    'total': 49000,
  },
};

class FakeReportsRepository extends ReportsRepository {
  FakeReportsRepository({this.fail = false})
    : super(apiClient: unusedApiClient());

  bool fail;
  int dashboardCalls = 0;

  @override
  Future<DashboardFigures> dashboard({String? date}) async {
    dashboardCalls++;
    if (fail) {
      throw ApiException(
        message: 'Forbidden',
        statusCode: 403,
        code: 'forbidden',
      );
    }
    return DashboardFigures.fromJson(dashboardJson());
  }

  @override
  Future<AgingReport> receivablesAging({String? asOf}) async =>
      AgingReport.fromJson(agingJson());

  @override
  Future<List<LowStockRow>> lowStock() async => [
    LowStockRow.fromJson({
      'product_id': 3,
      'sku': 'RPT-C',
      'title': 'Maths JHS1',
      'stock_on_hand': 34,
      'reorder_level': 40,
      'shortfall': 6,
      'status': 'low',
    }),
    LowStockRow.fromJson({
      'product_id': 5,
      'sku': 'RPT-E',
      'title': 'Maths P4 Workbook',
      'stock_on_hand': -1,
      'reorder_level': 0,
      'shortfall': 1,
      'status': 'out_of_stock',
    }),
  ];
}

void main() {
  late FakeReportsRepository reports;

  Future<void> pump(
    WidgetTester tester,
    Widget home, {
    String initial = '/',
  }) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    final router = GoRouter(
      initialLocation: initial,
      routes: [
        GoRoute(
          path: '/',
          builder: (_, _) => Scaffold(body: ListView(children: [home])),
        ),
        GoRoute(path: '/owing', builder: (_, _) => const OwingReportScreen()),
        GoRoute(path: '/low', builder: (_, _) => const LowStockReportScreen()),
        for (final p in [
          '/sales',
          '/payments',
          '/customers',
          '/reports/owing',
          '/reports/low-stock',
        ])
          GoRoute(
            path: p,
            builder: (_, _) => Scaffold(body: Text('opened $p')),
          ),
        GoRoute(
          path: '/customers/:id',
          builder: (_, s) =>
              Scaffold(body: Text('customer ${s.pathParameters['id']}')),
        ),
        GoRoute(
          path: '/products/:id',
          builder: (_, s) =>
              Scaffold(body: Text('product ${s.pathParameters['id']}')),
        ),
      ],
    );
    await tester.pumpWidget(
      Provider<ReportsRepository>.value(
        value: reports,
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await tester.pumpAndSettle();
  }

  setUp(() => reports = FakeReportsRepository());

  testWidgets(
    'dashboard cards show the server figures and open the matching list',
    (tester) async {
      await pump(tester, const DashboardCards());

      String cardText(String key) => tester
          .widgetList<Text>(
            find.descendant(
              of: find.byKey(Key(key)),
              matching: find.byType(Text),
            ),
          )
          .map((t) => t.data)
          .join(' | ');

      expect(cardText('card_sales_today'), 'Sales today | GHS 0.00 | 0 sales');
      expect(cardText('card_sales_month'), 'This month | GHS 960.00 | 5 sales');
      expect(
        cardText('card_collections'),
        'Collected today | GHS 0.00 | Month GHS 810.00',
      );
      expect(cardText('card_owed'), 'Owed to you | GHS 490.00');
      expect(cardText('card_overdue'), 'Overdue | GHS 320.00');
      expect(cardText('card_credit'), 'Credit held | GHS 20.00');
      expect(cardText('card_low_stock'), 'Low stock | 3 | products');

      await tester.tap(find.byKey(const Key('card_overdue')));
      await tester.pumpAndSettle();
      expect(find.text('opened /reports/owing'), findsOneWidget);

      // Coming back refreshes the figures.
      tester.state<NavigatorState>(find.byType(Navigator).last).pop();
      await tester.pumpAndSettle();
      expect(reports.dashboardCalls, 2);

      await tester.tap(find.byKey(const Key('card_low_stock')));
      await tester.pumpAndSettle();
      expect(find.text('opened /reports/low-stock'), findsOneWidget);
    },
  );

  testWidgets('dashboard cards show a retry when the figures cannot load', (
    tester,
  ) async {
    reports.fail = true;
    await pump(tester, const DashboardCards());
    expect(find.text('Figures unavailable'), findsOneWidget);

    reports.fail = false;
    await tester.tap(find.text('Retry'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('card_owed')), findsOneWidget);
  });

  testWidgets(
    'who owes most lists the largest balance first with the overdue part',
    (tester) async {
      await pump(tester, const SizedBox(), initial: '/owing');

      expect(
        tester.widget<Text>(find.byKey(const Key('owing_total'))).data,
        'GHS 490.00',
      );
      final beta = tester.getTopLeft(find.byKey(const Key('owing_2')));
      final alpha = tester.getTopLeft(find.byKey(const Key('owing_1')));
      expect(beta.dy < alpha.dy, isTrue);
      expect(
        find.text('Overdue GHS 320.00 (over 90 days GHS 120.00)'),
        findsOneWidget,
      );
      expect(find.text('Nothing overdue'), findsOneWidget);

      await tester.tap(find.byKey(const Key('owing_2')));
      await tester.pumpAndSettle();
      expect(find.text('customer 2'), findsOneWidget);
    },
  );

  testWidgets('low stock shows shortfall or out of stock', (tester) async {
    await pump(tester, const SizedBox(), initial: '/low');

    expect(find.text('34 left'), findsOneWidget);
    expect(find.text('Short 6'), findsOneWidget);
    expect(find.text('-1 left'), findsOneWidget);
    expect(find.text('Out of stock'), findsOneWidget);
  });
}
