import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/products/domain/product.dart';

/// Cached schools + products for field use when the network is down.
///
/// Stock and balances here are hints only — never used to invent money or
/// authorize stock moves. The server remains authoritative on sync.
class FieldKitSnapshot {
  const FieldKitSnapshot({
    required this.customers,
    required this.products,
    required this.capturedAt,
  });

  factory FieldKitSnapshot.empty() => FieldKitSnapshot(
    customers: const [],
    products: const [],
    capturedAt: DateTime.fromMillisecondsSinceEpoch(0, isUtc: true),
  );

  factory FieldKitSnapshot.fromJson(Map<String, dynamic> json) {
    return FieldKitSnapshot(
      customers: (json['customers'] as List<dynamic>? ?? const [])
          .map((e) => Customer.fromJson(e as Map<String, dynamic>))
          .toList(),
      products: (json['products'] as List<dynamic>? ?? const [])
          .map((e) => Product.fromJson(e as Map<String, dynamic>))
          .toList(),
      capturedAt: DateTime.parse(json['captured_at'] as String),
    );
  }

  final List<Customer> customers;
  final List<Product> products;
  final DateTime capturedAt;

  bool get isEmpty => customers.isEmpty && products.isEmpty;

  Map<String, dynamic> toJson() => {
    'customers': [for (final c in customers) c.toCacheJson()],
    'products': [for (final p in products) p.toCacheJson()],
    'captured_at': capturedAt.toUtc().toIso8601String(),
  };

  List<Customer> searchCustomers(String query) {
    final q = query.trim().toLowerCase();
    if (q.isEmpty) return customers;
    return customers
        .where(
          (c) =>
              c.name.toLowerCase().contains(q) ||
              c.code.toLowerCase().contains(q) ||
              (c.phone?.toLowerCase().contains(q) ?? false),
        )
        .toList();
  }

  List<Product> searchProducts(String query) {
    final q = query.trim().toLowerCase();
    if (q.isEmpty) return products;
    return products
        .where(
          (p) =>
              p.title.toLowerCase().contains(q) ||
              p.sku.toLowerCase().contains(q) ||
              (p.isbn?.toLowerCase().contains(q) ?? false) ||
              (p.barcode?.toLowerCase().contains(q) ?? false),
        )
        .toList();
  }
}
