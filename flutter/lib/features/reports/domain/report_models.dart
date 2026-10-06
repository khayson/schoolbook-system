/// Report figures from `/reports/*` (docs/api.md, Reports). Money is integer pesewas;
/// the app only displays these, it never computes them.
class SalesFigure {
  const SalesFigure({required this.count, required this.revenue});

  factory SalesFigure.fromJson(Map<String, dynamic> json) =>
      SalesFigure(count: json['count'] as int, revenue: json['revenue'] as int);

  final int count;
  final int revenue;
}

class DashboardFigures {
  const DashboardFigures({
    required this.date,
    required this.salesToday,
    required this.salesMonth,
    required this.collectionsToday,
    required this.collectionsMonth,
    required this.owed,
    required this.overdue,
    required this.credit,
    required this.lowStockCount,
  });

  factory DashboardFigures.fromJson(Map<String, dynamic> json) =>
      DashboardFigures(
        date: json['date'] as String,
        salesToday: SalesFigure.fromJson(
          json['sales_today'] as Map<String, dynamic>,
        ),
        salesMonth: SalesFigure.fromJson(
          json['sales_month'] as Map<String, dynamic>,
        ),
        collectionsToday: json['collections_today'] as int,
        collectionsMonth: json['collections_month'] as int,
        owed: json['owed'] as int,
        overdue: json['overdue'] as int,
        credit: json['credit'] as int,
        lowStockCount: json['low_stock_count'] as int,
      );

  final String date;
  final SalesFigure salesToday;
  final SalesFigure salesMonth;
  final int collectionsToday;
  final int collectionsMonth;
  final int owed;
  final int overdue;
  final int credit;
  final int lowStockCount;
}

/// One customer's row of the receivables aging report.
class AgingRow {
  const AgingRow({
    required this.customerId,
    required this.name,
    required this.notYetDue,
    required this.days1To30,
    required this.days31To60,
    required this.days61To90,
    required this.days90Plus,
    required this.total,
  });

  factory AgingRow.fromJson(Map<String, dynamic> json) => AgingRow(
    customerId: json['customer_id'] as int,
    name: json['name'] as String,
    notYetDue: json['not_yet_due'] as int,
    days1To30: json['days_1_30'] as int,
    days31To60: json['days_31_60'] as int,
    days61To90: json['days_61_90'] as int,
    days90Plus: json['days_90_plus'] as int,
    total: json['total'] as int,
  );

  final int customerId;
  final String name;
  final int notYetDue;
  final int days1To30;
  final int days31To60;
  final int days61To90;
  final int days90Plus;
  final int total;

  /// Past due, whatever the bucket.
  int get overdue => days1To30 + days31To60 + days61To90 + days90Plus;
}

class AgingReport {
  const AgingReport({
    required this.asOf,
    required this.rows,
    required this.total,
  });

  factory AgingReport.fromJson(Map<String, dynamic> json) => AgingReport(
    asOf: json['as_of'] as String,
    rows: (json['rows'] as List<dynamic>)
        .map((e) => AgingRow.fromJson(e as Map<String, dynamic>))
        .toList(),
    total: (json['totals'] as Map<String, dynamic>)['total'] as int,
  );

  final String asOf;
  final List<AgingRow> rows;
  final int total;

  /// Who owes most first (the server lists by name).
  List<AgingRow> get byAmountOwed =>
      [...rows]..sort((a, b) => b.total.compareTo(a.total));
}

class LowStockRow {
  const LowStockRow({
    required this.productId,
    required this.sku,
    required this.title,
    required this.stockOnHand,
    required this.reorderLevel,
    required this.shortfall,
    required this.status,
  });

  factory LowStockRow.fromJson(Map<String, dynamic> json) => LowStockRow(
    productId: json['product_id'] as int,
    sku: json['sku'] as String,
    title: json['title'] as String,
    stockOnHand: json['stock_on_hand'] as int,
    reorderLevel: json['reorder_level'] as int,
    shortfall: json['shortfall'] as int,
    status: json['status'] as String,
  );

  final int productId;
  final String sku;
  final String title;
  final int stockOnHand;
  final int reorderLevel;
  final int shortfall;

  /// `out_of_stock` or `low`.
  final String status;

  bool get isOutOfStock => status == 'out_of_stock';
}
