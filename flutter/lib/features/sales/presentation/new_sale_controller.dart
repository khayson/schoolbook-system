import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/offline/presentation/offline_controller.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/sales/domain/sale.dart';

class DraftLine {
  DraftLine({required this.product, required this.quantity});

  Product product;
  int quantity;

  /// True when the ordered qty is more than what is currently on hand.
  bool get isShort => quantity > product.stockOnHand;
}

/// The new-sale form. Totals always come from `POST /pricing/preview` (debounced); the
/// app never computes prices. Saving uses a persisted idempotency key so a retry after
/// a lost connection cannot create a second draft.
class NewSaleController extends ChangeNotifier {
  NewSaleController({
    required this._sales,
    required this._pendingStore,
    this._offline,
    this.debounce = const Duration(milliseconds: 400),
  });

  static const intent = 'create_draft_sale';

  final SalesRepository _sales;
  final PendingSubmissionStore _pendingStore;
  final OfflineController? _offline;
  final Duration debounce;

  Customer? customer;
  final List<DraftLine> lines = [];

  /// When set, [saveDraft] updates this draft instead of creating a new one.
  int? editingSaleId;

  PricedOrder? preview;
  bool previewLoading = false;
  ApiException? previewError;

  bool saving = false;
  ApiException? saveError;

  /// True when the last [saveDraft] was enqueued for later sync (network/unknown).
  bool queuedOffline = false;

  Timer? _timer;
  int _previewSeq = 0;

  int? _baselineCustomerId;
  String _baselineNotes = '';
  List<(int, int)> _baselineItems = const [];

  bool get isEditing => editingSaleId != null;

  bool get canSave => customer != null && lines.isNotEmpty && !saving;

  /// True when the form differs from the loaded draft (always true for new sales).
  bool isDirty({String notes = ''}) {
    if (!isEditing) return true;
    if (customer?.id != _baselineCustomerId) return true;
    if (_normalizeNotes(notes) != _baselineNotes) return true;
    final current = [
      for (final l in lines) (l.product.id, l.quantity),
    ];
    if (current.length != _baselineItems.length) return true;
    for (var i = 0; i < current.length; i++) {
      if (current[i] != _baselineItems[i]) return true;
    }
    return false;
  }

  /// Ready to write — for edits, requires a real change.
  bool canPersist({String notes = ''}) =>
      canSave && isDirty(notes: notes);

  /// Hydrate the form from an existing draft (edit flow).
  void loadExistingDraft({
    required int saleId,
    required Customer customer,
    required List<DraftLine> draftLines,
    String notes = '',
  }) {
    editingSaleId = saleId;
    this.customer = customer;
    lines
      ..clear()
      ..addAll(draftLines);
    _captureBaseline(notes: notes);
    _changed();
  }

  void _captureBaseline({String notes = ''}) {
    _baselineCustomerId = customer?.id;
    _baselineNotes = _normalizeNotes(notes);
    _baselineItems = [
      for (final l in lines) (l.product.id, l.quantity),
    ];
  }

  static String _normalizeNotes(String notes) => notes.trim();

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

  /// Refresh a line's product (e.g. after adjusting stock) without changing qty.
  void replaceProduct(Product product) {
    for (var i = 0; i < lines.length; i++) {
      if (lines[i].product.id == product.id) {
        lines[i] = DraftLine(
          product: product,
          quantity: lines[i].quantity,
        );
        notifyListeners();
        return;
      }
    }
  }

  /// Drop every line that is short on stock.
  void removeShortLines() {
    final before = lines.length;
    lines.removeWhere((l) => l.isShort);
    if (lines.length != before) _changed();
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
    final noteText = notes ?? '';
    if (!canPersist(notes: noteText)) {
      return null;
    }
    saving = true;
    saveError = null;
    queuedOffline = false;
    notifyListeners();
    final payload = draftPayload(notes: notes);
    final editId = editingSaleId;
    try {
      if (editId != null) {
        final sale = await _sales.updateDraft(editId, payload);
        _captureBaseline(notes: noteText);
        return sale;
      }
      final key = await _pendingStore.keyFor(intent, payload);
      final sale = await _sales.createDraft(
        idempotencyKey: key,
        payload: payload,
      );
      await _pendingStore.complete(intent);
      return sale;
    } on ApiException catch (e) {
      if (editId == null && e.isOutcomeUnknown && _offline != null) {
        final pending = await _pendingStore.pending(intent);
        final key = pending?.idempotencyKey;
        if (key != null) {
          await _offline.enqueueDraftSale(
            payload: payload,
            idempotencyKey: key,
          );
          await _pendingStore.complete(intent);
          queuedOffline = true;
          saveError = null;
          return null;
        }
      }
      if (editId == null && !e.isOutcomeUnknown) {
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
