/// Mirrors `CustomerResource` (laravel/app/Http/Resources/CustomerResource.php).
class Customer {
  const Customer({
    required this.id,
    required this.code,
    required this.name,
    required this.type,
    required this.region,
    this.district,
    this.address,
    this.contactPerson,
    this.phone,
    this.email,
    this.creditLimit,
    required this.creditBalance,
    required this.outstandingBalance,
    this.notes,
    required this.isActive,
  });

  factory Customer.fromJson(Map<String, dynamic> json) {
    return Customer(
      id: json['id'] as int,
      code: json['code'] as String? ?? '',
      name: json['name'] as String,
      type: json['type'] as String? ?? 'school',
      region: json['region'] as String? ?? '',
      district: json['district'] as String?,
      address: json['address'] as String?,
      contactPerson: json['contact_person'] as String?,
      phone: json['phone'] as String?,
      email: json['email'] as String?,
      creditLimit: json['credit_limit'] as int?,
      creditBalance: json['credit_balance'] as int? ?? 0,
      outstandingBalance: json['outstanding_balance'] as int? ?? 0,
      notes: json['notes'] as String?,
      isActive: json['is_active'] as bool? ?? true,
    );
  }

  final int id;
  final String code;
  final String name;
  final String type;
  final String region;
  final String? district;
  final String? address;
  final String? contactPerson;
  final String? phone;
  final String? email;

  /// Pesewas; null = no limit.
  final int? creditLimit;

  /// Unallocated money held for this customer (pesewas).
  final int creditBalance;

  /// Sum of balance due on confirmed invoices (pesewas).
  final int outstandingBalance;
  final String? notes;
  final bool isActive;
}

abstract final class CustomerOptions {
  static const types = {
    'school': 'School',
    'reseller': 'Reseller',
    'individual': 'Individual',
  };

  /// The 16 regions of Ghana (server enum `GhanaRegion`; values are the names).
  static const regions = [
    'Ahafo',
    'Ashanti',
    'Bono',
    'Bono East',
    'Central',
    'Eastern',
    'Greater Accra',
    'North East',
    'Northern',
    'Oti',
    'Savannah',
    'Upper East',
    'Upper West',
    'Volta',
    'Western',
    'Western North',
  ];
}
