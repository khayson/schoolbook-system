/// The fields of `SaleResource` needed for customer screens (full sales UI is 2D.3).
class SaleSummary {
  const SaleSummary({
    required this.id,
    this.invoiceNo,
    required this.status,
    required this.paymentStatus,
    required this.saleDate,
    this.dueDate,
    required this.total,
    required this.balanceDue,
  });

  factory SaleSummary.fromJson(Map<String, dynamic> json) {
    return SaleSummary(
      id: json['id'] as int,
      invoiceNo: json['invoice_no'] as String?,
      status: json['status'] as String,
      paymentStatus: json['payment_status'] as String? ?? 'unpaid',
      saleDate: DateTime.parse(json['sale_date'] as String),
      dueDate: json['due_date'] == null ? null : DateTime.parse(json['due_date'] as String),
      total: json['total'] as int? ?? 0,
      balanceDue: json['balance_due'] as int? ?? 0,
    );
  }

  final int id;
  final String? invoiceNo;
  final String status;
  final String paymentStatus;
  final DateTime saleDate;
  final DateTime? dueDate;
  final int total;
  final int balanceDue;

  String get label => invoiceNo ?? 'Draft #$id';
}
