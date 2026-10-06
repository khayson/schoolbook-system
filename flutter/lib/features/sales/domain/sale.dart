import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/payments/domain/payment.dart';

/// Mirrors `SaleResource` (with `items`, `customer` and `allocations` loaded).
class Sale {
  const Sale({
    required this.id,
    this.invoiceNo,
    required this.customerId,
    this.customer,
    required this.status,
    required this.paymentStatus,
    required this.saleDate,
    this.dueDate,
    required this.subtotal,
    required this.discountTotal,
    required this.total,
    required this.amountPaid,
    required this.balanceDue,
    this.deliveredAt,
    this.notes,
    this.cancelReason,
    this.voidReason,
    this.updatedAt,
    this.items = const [],
    this.allocations = const [],
  });

  factory Sale.fromJson(Map<String, dynamic> json) {
    final customer = json['customer'];
    final items = json['items'];
    final allocations = json['allocations'];
    return Sale(
      id: json['id'] as int,
      invoiceNo: json['invoice_no'] as String?,
      customerId: json['customer_id'] as int,
      customer: customer is Map<String, dynamic>
          ? Customer.fromJson(customer)
          : null,
      status: json['status'] as String,
      paymentStatus: json['payment_status'] as String? ?? 'unpaid',
      saleDate: DateTime.parse(json['sale_date'] as String),
      dueDate: json['due_date'] == null
          ? null
          : DateTime.parse(json['due_date'] as String),
      subtotal: json['subtotal'] as int? ?? 0,
      discountTotal: json['discount_total'] as int? ?? 0,
      total: json['total'] as int? ?? 0,
      amountPaid: json['amount_paid'] as int? ?? 0,
      balanceDue: json['balance_due'] as int? ?? 0,
      deliveredAt: json['delivered_at'] == null
          ? null
          : DateTime.parse(json['delivered_at'] as String).toLocal(),
      notes: json['notes'] as String?,
      cancelReason: json['cancel_reason'] as String?,
      voidReason: json['void_reason'] as String?,
      updatedAt: json['updated_at'] as String?,
      items: items is List
          ? items
                .map((e) => SaleLine.fromJson(e as Map<String, dynamic>))
                .toList()
          : const [],
      allocations: allocations is List
          ? allocations
                .map(
                  (e) => PaymentAllocation.fromJson(e as Map<String, dynamic>),
                )
                .toList()
          : const [],
    );
  }

  final int id;
  final String? invoiceNo;
  final int customerId;
  final Customer? customer;
  final String status;
  final String paymentStatus;
  final DateTime saleDate;
  final DateTime? dueDate;
  final int subtotal;
  final int discountTotal;
  final int total;
  final int amountPaid;
  final int balanceDue;
  final DateTime? deliveredAt;
  final String? notes;
  final String? cancelReason;
  final String? voidReason;

  /// Raw server timestamp; part of the confirm idempotency payload, so a re-priced
  /// (re-saved) draft gets a new key.
  final String? updatedAt;
  final List<SaleLine> items;
  final List<PaymentAllocation> allocations;

  bool get isDraft => status == 'draft';
  bool get isConfirmed => status == 'confirmed';
  bool get canVoid => isConfirmed && deliveredAt == null;
  String get label => invoiceNo ?? 'Draft #$id';
}

/// A sale item (`SaleItemResource`) or a priced preview line (`PricedLine`): same keys.
class SaleLine {
  const SaleLine({
    required this.productId,
    required this.productTitle,
    required this.quantity,
    required this.basePrice,
    required this.unitPrice,
    required this.lineTotal,
    this.isPriceOverridden = false,
    this.overrideReason,
  });

  factory SaleLine.fromJson(Map<String, dynamic> json) {
    return SaleLine(
      productId: json['product_id'] as int,
      productTitle: json['product_title'] as String? ?? '',
      quantity: json['quantity'] as int,
      basePrice: json['base_price'] as int? ?? 0,
      unitPrice: json['unit_price'] as int? ?? 0,
      lineTotal: json['line_total'] as int? ?? 0,
      isPriceOverridden: json['is_price_overridden'] as bool? ?? false,
      overrideReason: json['override_reason'] as String?,
    );
  }

  final int productId;
  final String productTitle;
  final int quantity;
  final int basePrice;
  final int unitPrice;
  final int lineTotal;
  final bool isPriceOverridden;
  final String? overrideReason;
}

/// `POST /pricing/preview` data, and `details.priced_order` of a 409 price_changed.
class PricedOrder {
  const PricedOrder({
    required this.lines,
    required this.subtotal,
    required this.total,
    this.warnings = const [],
  });

  factory PricedOrder.fromJson(Map<String, dynamic> json) {
    return PricedOrder(
      lines: (json['lines'] as List<dynamic>? ?? const [])
          .map((e) => SaleLine.fromJson(e as Map<String, dynamic>))
          .toList(),
      subtotal: json['subtotal'] as int? ?? 0,
      total: json['total'] as int? ?? 0,
      warnings: (json['warnings'] as List<dynamic>? ?? const [])
          .map((e) => e.toString())
          .toList(),
    );
  }

  final List<SaleLine> lines;
  final int subtotal;
  final int total;
  final List<String> warnings;
}

abstract final class SaleStatuses {
  static String label(String status) => switch (status) {
    'draft' => 'Draft',
    'confirmed' => 'Confirmed',
    'cancelled' => 'Cancelled',
    'void' => 'Void',
    'requested' => 'Requested',
    _ => status,
  };

  static String paymentLabel(String status) => switch (status) {
    'paid' => 'Paid',
    'partial' => 'Part paid',
    _ => 'Unpaid',
  };
}
