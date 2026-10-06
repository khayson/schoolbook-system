/// Mirrors `PaymentResource` / `PaymentAllocationResource`.
class Payment {
  const Payment({
    required this.id,
    required this.receiptNo,
    required this.customerId,
    this.customerName,
    required this.amount,
    required this.method,
    this.reference,
    required this.paidAt,
    required this.unallocatedAmount,
    required this.status,
    this.voidReason,
    this.notes,
    this.allocations = const [],
  });

  factory Payment.fromJson(Map<String, dynamic> json) {
    final customer = json['customer'];
    final allocations = json['allocations'];
    return Payment(
      id: json['id'] as int,
      receiptNo: json['receipt_no'] as String,
      customerId: json['customer_id'] as int,
      customerName: customer is Map ? customer['name'] as String? : null,
      amount: json['amount'] as int,
      method: json['method'] as String,
      reference: json['reference'] as String?,
      paidAt: DateTime.parse(json['paid_at'] as String).toLocal(),
      unallocatedAmount: json['unallocated_amount'] as int? ?? 0,
      status: json['status'] as String? ?? 'valid',
      voidReason: json['void_reason'] as String?,
      notes: json['notes'] as String?,
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
  final String receiptNo;
  final int customerId;
  final String? customerName;
  final int amount;
  final String method;
  final String? reference;
  final DateTime paidAt;
  final int unallocatedAmount;
  final String status;
  final String? voidReason;
  final String? notes;
  final List<PaymentAllocation> allocations;

  bool get isVoid => status == 'void';

  String get methodLabel => PaymentMethods.label(method);
}

/// One ledger row. Negative rows reverse an earlier allocation.
class PaymentAllocation {
  const PaymentAllocation({
    required this.id,
    required this.saleId,
    this.invoiceNo,
    this.receiptNo,
    required this.amount,
    this.reversalOfId,
  });

  factory PaymentAllocation.fromJson(Map<String, dynamic> json) {
    return PaymentAllocation(
      id: json['id'] as int,
      saleId: json['sale_id'] as int,
      invoiceNo: json['invoice_no'] as String?,
      receiptNo: json['receipt_no'] as String?,
      amount: json['amount'] as int,
      reversalOfId: json['reversal_of_id'] as int?,
    );
  }

  final int id;
  final int saleId;
  final String? invoiceNo;
  final String? receiptNo;
  final int amount;
  final int? reversalOfId;

  bool get isReversal => reversalOfId != null;
}

abstract final class PaymentMethods {
  static const all = ['cash', 'momo', 'bank_transfer', 'cheque'];

  static String label(String method) => switch (method) {
    'cash' => 'Cash',
    'momo' => 'Mobile Money',
    'bank_transfer' => 'Bank transfer',
    'cheque' => 'Cheque',
    _ => method,
  };

  static String referenceLabel(String method) =>
      method == 'cash' ? 'Reference (optional)' : 'Reference';

  /// A cheque number is only unique per bank, so cheques need the bank too. Spaces are
  /// ignored when matching duplicates: "GCB 000123" equals "GCB000123".
  static const referenceHelp =
      'MoMo transaction ID, bank reference, or bank + cheque number (for example GCB 000123)';
}

/// Result of `POST /customers/{id}/apply-credit`.
class CreditApplication {
  const CreditApplication({
    required this.appliedTotal,
    required this.creditBalance,
    required this.outstandingBalance,
  });

  factory CreditApplication.fromJson(Map<String, dynamic> json) {
    final customer = json['customer'] as Map<String, dynamic>;
    return CreditApplication(
      appliedTotal: json['applied_total'] as int,
      creditBalance: customer['credit_balance'] as int? ?? 0,
      outstandingBalance: customer['outstanding_balance'] as int? ?? 0,
    );
  }

  final int appliedTotal;
  final int creditBalance;
  final int outstandingBalance;
}
