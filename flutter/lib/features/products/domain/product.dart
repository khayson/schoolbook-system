import 'package:schoolbook/features/catalog/domain/lookup_models.dart';

class Product {
  const Product({
    required this.id,
    required this.sku,
    required this.title,
    required this.levelId,
    required this.subjectId,
    required this.languageId,
    required this.costPrice,
    required this.sellingPrice,
    required this.reorderLevel,
    required this.stockOnHand,
    required this.isActive,
    this.isbn,
    this.barcode,
    this.publisherId,
    this.referenceBookId,
    this.variantLabel,
    this.edition,
    this.level,
    this.subject,
    this.language,
  });

  final int id;
  final String sku;
  final String? isbn;
  final String? barcode;
  final String title;
  final int levelId;
  final int subjectId;
  final int languageId;
  final int? publisherId;

  /// The approved-list (NaCCA) title this product is; null when not on the list.
  final int? referenceBookId;
  final String? variantLabel;
  final String? edition;
  final int costPrice;
  final int sellingPrice;
  final int reorderLevel;
  final int stockOnHand;
  final bool isActive;
  final NamedLookup? level;
  final NamedLookup? subject;
  final LanguageLookup? language;

  factory Product.fromJson(Map<String, dynamic> json) {
    return Product(
      id: json['id'] as int,
      sku: json['sku'] as String,
      isbn: json['isbn'] as String?,
      barcode: json['barcode'] as String?,
      title: json['title'] as String,
      levelId: json['level_id'] as int,
      subjectId: json['subject_id'] as int,
      languageId: json['language_id'] as int,
      publisherId: json['publisher_id'] as int?,
      referenceBookId: json['reference_book_id'] as int?,
      variantLabel: json['variant_label'] as String?,
      edition: json['edition'] as String?,
      costPrice: json['cost_price'] as int? ?? 0,
      sellingPrice: json['selling_price'] as int? ?? 0,
      reorderLevel: json['reorder_level'] as int? ?? 0,
      stockOnHand: json['stock_on_hand'] as int? ?? 0,
      isActive: json['is_active'] as bool? ?? true,
      level: json['level'] != null
          ? NamedLookup.fromJson(json['level'] as Map<String, dynamic>)
          : null,
      subject: json['subject'] != null
          ? NamedLookup.fromJson(json['subject'] as Map<String, dynamic>)
          : null,
      language: json['language'] != null
          ? LanguageLookup.fromJson(json['language'] as Map<String, dynamic>)
          : null,
    );
  }

  Map<String, dynamic> toWriteJson() {
    return {
      'sku': sku,
      'isbn': isbn,
      'barcode': barcode,
      'title': title,
      'level_id': levelId,
      'subject_id': subjectId,
      'language_id': languageId,
      'publisher_id': publisherId,
      'edition': edition,
      'cost_price': costPrice,
      'selling_price': sellingPrice,
      'reorder_level': reorderLevel,
      'is_active': isActive,
    };
  }
}

class StockMovement {
  const StockMovement({
    required this.id,
    required this.type,
    required this.quantity,
    required this.balanceAfter,
    required this.occurredAt,
    this.unitCost,
    this.note,
  });

  final int id;
  final String type;
  final int quantity;
  final int balanceAfter;
  final int? unitCost;
  final String? note;
  final DateTime occurredAt;

  factory StockMovement.fromJson(Map<String, dynamic> json) {
    return StockMovement(
      id: json['id'] as int,
      type: json['type'] as String,
      quantity: json['quantity'] as int,
      balanceAfter: json['balance_after'] as int,
      unitCost: json['unit_cost'] as int?,
      note: json['note'] as String?,
      occurredAt: DateTime.parse(json['occurred_at'] as String),
    );
  }
}
