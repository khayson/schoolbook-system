import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/products/domain/product.dart';

/// They gave us (Taken) or they took from us.
enum ExchangeDirection {
  inn('in'),
  out('out');

  const ExchangeDirection(this.apiValue);
  final String apiValue;

  static ExchangeDirection fromApi(String? value) {
    return switch (value) {
      'out' => ExchangeDirection.out,
      _ => ExchangeDirection.inn,
    };
  }

  String get label => switch (this) {
    ExchangeDirection.inn => 'They gave us (Taken)',
    ExchangeDirection.out => 'They took from us',
  };

  String get shortLabel => switch (this) {
    ExchangeDirection.inn => 'Taken',
    ExchangeDirection.out => 'They took',
  };
}

enum ExchangeSettlement {
  cash('cash'),
  installment('installment'),
  payLater('pay_later');

  const ExchangeSettlement(this.apiValue);
  final String apiValue;

  static ExchangeSettlement? fromApi(String? value) {
    return switch (value) {
      'cash' => ExchangeSettlement.cash,
      'installment' => ExchangeSettlement.installment,
      'pay_later' => ExchangeSettlement.payLater,
      _ => null,
    };
  }

  String get label => switch (this) {
    ExchangeSettlement.cash => 'Cash and carry',
    ExchangeSettlement.installment => 'Installment',
    ExchangeSettlement.payLater => 'Pay later',
  };

  String get description => switch (this) {
    ExchangeSettlement.cash => 'Pay the full invoice when you confirm.',
    ExchangeSettlement.installment => 'Confirm the invoice, then pay in parts.',
    ExchangeSettlement.payLater => 'Confirm now; collect the balance later.',
  };
}

class StockExchangeItem {
  const StockExchangeItem({
    required this.id,
    required this.direction,
    required this.productId,
    required this.quantity,
    this.unitCost,
    this.unitPrice,
    this.product,
  });

  factory StockExchangeItem.fromJson(Map<String, dynamic> json) {
    final productJson = json['product'];
    return StockExchangeItem(
      id: json['id'] as int? ?? 0,
      direction: ExchangeDirection.fromApi(json['direction'] as String?),
      productId: json['product_id'] as int,
      quantity: json['quantity'] as int,
      unitCost: json['unit_cost'] as int?,
      unitPrice: json['unit_price'] as int?,
      product: productJson is Map<String, dynamic>
          ? Product.fromJson(productJson)
          : null,
    );
  }

  final int id;
  final ExchangeDirection direction;
  final int productId;
  final int quantity;
  final int? unitCost;
  final int? unitPrice;
  final Product? product;

  String get productTitle => product?.title ?? 'Product #$productId';
}

class StockExchange {
  const StockExchange({
    required this.id,
    required this.customerId,
    required this.counterpartyName,
    required this.status,
    required this.createdBy,
    required this.updatedAt,
    this.exchangeNo,
    this.supplierId,
    this.notes,
    this.settlement,
    this.saleId,
    this.goodsReceiptId,
    this.confirmedAt,
    this.voidedAt,
    this.voidReason,
    this.customer,
    this.items = const [],
    this.saleInvoiceNo,
    this.saleTotal,
    this.saleBalanceDue,
    this.receiptNo,
  });

  factory StockExchange.fromJson(Map<String, dynamic> json) {
    final customerJson = json['customer'];
    final saleJson = json['sale'];
    final grnJson = json['goods_receipt'];
    final itemsJson = json['items'];

    return StockExchange(
      id: json['id'] as int,
      exchangeNo: json['exchange_no'] as String?,
      customerId: json['customer_id'] as int,
      supplierId: json['supplier_id'] as int?,
      counterpartyName: json['counterparty_name'] as String? ?? '',
      status: json['status'] as String? ?? 'draft',
      settlement: ExchangeSettlement.fromApi(json['settlement'] as String?),
      notes: json['notes'] as String?,
      createdBy: json['created_by'] as int? ?? 0,
      saleId: json['sale_id'] as int?,
      goodsReceiptId: json['goods_receipt_id'] as int?,
      confirmedAt: json['confirmed_at'] as String?,
      voidedAt: json['voided_at'] as String?,
      voidReason: json['void_reason'] as String?,
      updatedAt: json['updated_at'] as String? ?? '',
      customer: customerJson is Map<String, dynamic>
          ? Customer.fromJson(customerJson)
          : null,
      items: itemsJson is List
          ? itemsJson
                .whereType<Map<String, dynamic>>()
                .map(StockExchangeItem.fromJson)
                .toList()
          : const [],
      saleInvoiceNo: saleJson is Map<String, dynamic>
          ? saleJson['invoice_no'] as String?
          : null,
      saleTotal: saleJson is Map<String, dynamic>
          ? saleJson['total'] as int?
          : null,
      saleBalanceDue: saleJson is Map<String, dynamic>
          ? saleJson['balance_due'] as int?
          : null,
      receiptNo: grnJson is Map<String, dynamic>
          ? grnJson['receipt_no'] as String?
          : null,
    );
  }

  final int id;
  final String? exchangeNo;
  final int customerId;
  final int? supplierId;
  final String counterpartyName;
  final String status;
  final ExchangeSettlement? settlement;
  final String? notes;
  final int createdBy;
  final int? saleId;
  final int? goodsReceiptId;
  final String? confirmedAt;
  final String? voidedAt;
  final String? voidReason;
  final String updatedAt;
  final Customer? customer;
  final List<StockExchangeItem> items;
  final String? saleInvoiceNo;
  final int? saleTotal;
  final int? saleBalanceDue;
  final String? receiptNo;

  bool get isDraft => status == 'draft';
  bool get isConfirmed => status == 'confirmed';

  String get displayNo => exchangeNo ?? 'Draft #$id';

  List<StockExchangeItem> get takenItems =>
      items.where((i) => i.direction == ExchangeDirection.inn).toList();

  List<StockExchangeItem> get theyTookItems =>
      items.where((i) => i.direction == ExchangeDirection.out).toList();

  int get takenCopies =>
      takenItems.fold(0, (sum, i) => sum + i.quantity);

  int get theyTookCopies =>
      theyTookItems.fold(0, (sum, i) => sum + i.quantity);
}
