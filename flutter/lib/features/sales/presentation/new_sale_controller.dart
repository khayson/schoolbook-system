import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/sales/domain/sale.dart';

class DraftLine {
  DraftLine({required this.product, required this.quantity});

  final Product product;
  int quantity;
}

/// The new-sale form. Totals always come from `POST /pricing/preview` (debounced); the
/// app never computes prices. Saving uses a persisted idempotency key so a retry after
/// a lost connection cannot create a second draft.
class NewSaleController extends ChangeNotifier {
  NewSaleController({
    required this._sales,
    required this._pendingStore,
    this.debounce = const Duration(milliseconds: 400),
  });

  static const intent = 'create_draft_sale';

  final SalesRepository _sales;
  final PendingSubmissionStore _pendingStore;
  final Duration debounce;

  Customer? customer;
  final List<DraftLine> lines = [];

  PricedOrder? preview;
  bool previewLoading = false;
  ApiException? previewError;

  bool saving = false;
  ApiException? saveError;

  Timer? _timer;
  int _previewSeq = 0;

  bool get canSave => customer != null && lines.isNotEmpty && !saving;

  /// Server price for a product in the current preview, if any.
  SaleLine? previewFor(int productId) {
    for (final line in preview?.lines ?? const <SaleLine>[]) {
      if (line.productId == productId) {
        return line;
      }
    }
    return null;
  }

  void setCustomer(Customer value) {
    customer = value;
    _changed();
  }

  /// Adding a book already on the order adds one to its quantity.
  void addProduct(Product product) {
    for (final line in lines) {
      if (line.product.id == product.id) {
        line.quantity++;
        _changed();
        return;
      }
    }
    lines.add(DraftLine(product: product, quantity: 1));
    _changed();
  }

  void setQuantity(int productId, int quantity) {
    if (quantity < 1) {
      return;
    }
    for (final line in lines) {
      if (line.product.id == productId) {
        line.quantity = quantity;
      }
    }
    _changed();
  }

  void step(int productId, int delta) {
    for (final line in lines) {
      if (line.product.id == productId) {
        setQuantity(productId, line.quantity + delta);
        return;
      }
    }
  }

  void remove(int productId) {
    lines.removeWhere((l) => l.product.id == productId);
    _changed();
  }

  List<Map<String, dynamic>> get _items => [
    for (final l in lines) {'product_id': l.product.id, 'quantity': l.quantity},
  ];

  /// Exact `POST /sales` body.
  Map<String, dynamic> draftPayload({String? notes}) => {
    'customer_id': customer?.id,
    'notes': (notes?.trim().isEmpty ?? true) ? null : notes!.trim(),
    'items': _items,
  };

  void _changed() {
    saveError = null;
    notifyListeners();
    _timer?.cancel();
    if (lines.isEmpty) {
      preview = null;
      previewError = null;
      return;
    }
    _timer = Timer(debounce, refreshPreview);
  }

  /// Latest request wins: a slow older response never overwrites a newer one.
  Future<void> refreshPreview() async {
    final seq = ++_previewSeq;
    previewLoading = true;
    notifyListeners();
    try {
      final order = await _sales.preview(
        customerId: customer?.id,
        items: _items,
      );
      if (seq == _previewSeq) {
        preview = order;
        previewError = null;
      }
    } on ApiException catch (e) {
      if (seq == _previewSeq) {
        previewError = e;
      }
    } finally {
      if (seq == _previewSeq) {
        previewLoading = false;
        notifyListeners();
      }
    }
  }

  Future<Sale?> saveDraft({String? notes}) async {
    if (!canSave) {
      return null;
    }
    saving = true;
    saveError = null;
    notifyListeners();
    final payload = draftPayload(notes: notes);
    try {
      final key = await _pendingStore.keyFor(intent, payload);
      final sale = await _sales.createDraft(
        idempotencyKey: key,
        payload: payload,
      );
      await _pendingStore.complete(intent);
      return sale;
    } on ApiException catch (e) {
      if (!e.isOutcomeUnknown) {
        await _pendingStore.complete(intent);
      }
      saveError = e;
      return null;
    } finally {
      saving = false;
      notifyListeners();
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }
}
