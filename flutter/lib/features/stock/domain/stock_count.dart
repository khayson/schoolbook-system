/// A stock-take (docs/api.md, Stock-take). The server records the system quantity and
/// variance when a count is entered; the app only displays them.
class StockCountItem {
  const StockCountItem({
    required this.productId,
    required this.sku,
    required this.title,
    this.systemQty,
    this.countedQty,
    this.variance,
  });

  factory StockCountItem.fromJson(Map<String, dynamic> json) => StockCountItem(
    productId: json['product_id'] as int,
    sku: (json['sku'] as String?) ?? '',
    title: (json['title'] as String?) ?? '',
    systemQty: json['system_qty'] as int?,
    countedQty: json['counted_qty'] as int?,
    variance: json['variance'] as int?,
  );

  final int productId;
  final String sku;
  final String title;
  final int? systemQty;
  final int? countedQty;
  final int? variance;

  bool get isCounted => countedQty != null;

  bool get hasVariance => isCounted && (variance ?? 0) != 0;
}

class StockCountTotals {
  const StockCountTotals({
    required this.items,
    required this.counted,
    required this.varianceUnits,
    required this.varianceValue,
  });

  factory StockCountTotals.fromJson(Map<String, dynamic> json) =>
      StockCountTotals(
        items: json['items'] as int,
        counted: json['counted'] as int,
        varianceUnits: json['variance_units'] as int,
        varianceValue: json['variance_value'] as int,
      );

  final int items;
  final int counted;
  final int varianceUnits;

  /// Pesewas at cost.
  final int varianceValue;
}

class StockCount {
  const StockCount({
    required this.id,
    required this.reference,
    required this.status,
    required this.createdAt,
    this.items = const [],
    this.totals,
  });

  factory StockCount.fromJson(Map<String, dynamic> json) => StockCount(
    id: json['id'] as int,
    reference: json['reference'] as String,
    status: json['status'] as String,
    createdAt: DateTime.parse(json['created_at'] as String).toLocal(),
    items: ((json['items'] as List<dynamic>?) ?? const [])
        .map((e) => StockCountItem.fromJson(e as Map<String, dynamic>))
        .toList(),
    totals: json['totals'] == null
        ? null
        : StockCountTotals.fromJson(json['totals'] as Map<String, dynamic>),
  );

  final int id;
  final String reference;

  /// `open`, `applied` or `cancelled`.
  final String status;
  final DateTime createdAt;
  final List<StockCountItem> items;
  final StockCountTotals? totals;

  bool get isOpen => status == 'open';
}
