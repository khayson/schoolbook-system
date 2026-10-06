import 'package:flutter/foundation.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';

/// "Add product from the approved list": `POST /products` with the title's id (the
/// server fills title, level, subject, language and publisher), then, if a code was
/// scanned, `POST /products/attach-code`.
///
/// Idempotency: one persisted key per title and payload (intent
/// `quick_create.book.{id}`), kept only while the outcome is unknown, so a retry after
/// a dropped connection returns the first product instead of creating a second one.
/// Money is entered as GHS text and parsed to pesewas by the screen (Money); the
/// phone never computes prices or stock.
class QuickCreateController extends ChangeNotifier {
  QuickCreateController({
    required this._products,
    required this._pendingStore,
    required this.book,
  });

  final ProductsRepository _products;
  final PendingSubmissionStore _pendingStore;
  final ReferenceBook book;

  bool saving = false;
  ApiException? error;

  /// The product was created but the scanned code could not be attached (for example
  /// it already belongs to another product).
  String? codeWarning;
  Product? created;

  String get intent => 'quick_create.book.${book.id}';

  static Map<String, dynamic> buildPayload({
    required ReferenceBook book,
    required int costPesewas,
    required int pricePesewas,
    String? variant,
    int? levelId,
    int? subjectId,
    int? languageId,
    int openingStock = 0,
  }) {
    final v = variant?.trim() ?? '';
    return {
      'reference_book_id': book.id,
      if (v.isNotEmpty) 'variant_label': v,
      'level_id': ?levelId,
      'subject_id': ?subjectId,
      'language_id': ?languageId,
      'cost_price': costPesewas,
      'selling_price': pricePesewas,
      if (openingStock > 0) 'opening_stock': openingStock,
    };
  }

  /// The created product (with the code attached when that worked), or null (see [error]).
  Future<Product?> submit(Map<String, dynamic> payload, {String? code}) async {
    if (saving) return null;
    saving = true;
    error = null;
    codeWarning = null;
    notifyListeners();

    try {
      final key = await _pendingStore.keyFor(intent, payload);
      var product = await _products.createProduct(payload, idempotencyKey: key);
      await _pendingStore.complete(intent);

      final trimmed = code?.trim() ?? '';
      if (trimmed.isNotEmpty) {
        try {
          product = await _products.attachCode(
            code: trimmed,
            productId: product.id,
          );
        } on ApiException catch (e) {
          codeWarning =
              'Product saved, but the code was not attached: ${e.message}';
        }
      }
      created = product;
      return product;
    } on ApiException catch (e) {
      error = e;
      if (!e.isOutcomeUnknown) {
        await _pendingStore.complete(intent);
      }
      return null;
    } finally {
      saving = false;
      notifyListeners();
    }
  }
}
