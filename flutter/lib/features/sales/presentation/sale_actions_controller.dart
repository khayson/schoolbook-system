import 'package:flutter/foundation.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/sales/domain/sale.dart';

/// What happened when confirming. Screens switch on this to show the right dialog.
sealed class ConfirmOutcome {
  const ConfirmOutcome();
}

class Confirmed extends ConfirmOutcome {
  const Confirmed(this.sale);
  final Sale sale;
}

/// 409 price_changed: nothing was written; [newOrder] is what the draft costs now.
class PricesChanged extends ConfirmOutcome {
  const PricesChanged(this.newOrder);
  final PricedOrder newOrder;
}

/// 422 insufficient_stock: every short book.
class StockShort extends ConfirmOutcome {
  const StockShort(this.items);
  final List<ShortItem> items;
}

/// 409 credit_limit_exceeded: a warning; confirm again with the override to proceed.
class CreditWarning extends ConfirmOutcome {
  const CreditWarning({
    required this.creditLimit,
    required this.outstanding,
    required this.saleTotal,
    required this.creditApplied,
    required this.projectedBalance,
  });

  final int creditLimit;
  final int outstanding;
  final int saleTotal;
  final int creditApplied;
  final int projectedBalance;
}

class ConfirmFailed extends ConfirmOutcome {
  const ConfirmFailed(this.error);
  final ApiException error;
}

class ShortItem {
  const ShortItem({
    required this.sku,
    required this.title,
    required this.requested,
    required this.available,
  });

  factory ShortItem.fromJson(Map<String, dynamic> json) => ShortItem(
    sku: json['sku']?.toString() ?? '',
    title: json['title']?.toString() ?? '',
    requested: json['requested'] as int? ?? 0,
    available: json['available'] as int? ?? 0,
  );

  final String sku;
  final String title;
  final int requested;
  final int available;
}

/// Confirm (and re-price) a draft with a persisted idempotency key.
///
/// The key's payload includes the sale id, the draft total and its `updated_at`, plus
/// the confirm options. Re-pricing the draft changes `updated_at` (and usually the
/// total), and ticking "override credit limit" changes the options: each gets a new
/// key. A retry with nothing changed (lost connection) reuses the key, so the server
/// replays the confirmation instead of issuing a second invoice.
class SaleActionsController extends ChangeNotifier {
  SaleActionsController({required this._sales, required this._pendingStore});

  final SalesRepository _sales;
  final PendingSubmissionStore _pendingStore;

  bool busy = false;

  static String confirmIntent(int saleId) => 'confirm_sale.$saleId';

  /// Exact request body for `POST /sales/{id}/confirm`.
  static Map<String, dynamic> confirmBody({
    DateTime? dueDate,
    bool applyCredit = false,
    bool overrideCreditLimit = false,
  }) {
    return {
      'due_date': dueDate == null
          ? null
          : '${dueDate.year.toString().padLeft(4, '0')}-${dueDate.month.toString().padLeft(2, '0')}-${dueDate.day.toString().padLeft(2, '0')}',
      'apply_credit': applyCredit,
      'override_credit_limit': overrideCreditLimit,
    };
  }

  /// What the key is derived from: the body plus the draft's identity and version.
  static Map<String, dynamic> confirmKeyPayload(
    Sale sale,
    Map<String, dynamic> body,
  ) {
    return {
      'sale_id': sale.id,
      'total': sale.total,
      'updated_at': sale.updatedAt,
      ...body,
    };
  }

  Future<ConfirmOutcome> confirm(
    Sale sale, {
    DateTime? dueDate,
    bool applyCredit = false,
    bool overrideCreditLimit = false,
  }) async {
    final body = confirmBody(
      dueDate: dueDate,
      applyCredit: applyCredit,
      overrideCreditLimit: overrideCreditLimit,
    );
    final intent = confirmIntent(sale.id);

    return _run(() async {
      final key = await _pendingStore.keyFor(
        intent,
        confirmKeyPayload(sale, body),
      );
      try {
        final confirmed = await _sales.confirm(
          sale.id,
          idempotencyKey: key,
          options: body,
        );
        await _pendingStore.complete(intent);
        return Confirmed(confirmed);
      } on ApiException catch (e) {
        if (!e.isOutcomeUnknown) {
          await _pendingStore.complete(intent);
        }
        return _outcome(e);
      }
    });
  }

  /// "Accept new prices": `PUT /sales/{id}` with `{}` re-prices the draft. Returns the
  /// re-priced draft (new updated_at), so the next confirm gets a new key.
  Future<Sale> acceptNewPrices(Sale sale) =>
      _run(() => _sales.updateDraft(sale.id, const {}));

  Future<Sale> cancel(Sale sale, {String? reason}) =>
      _run(() => _sales.cancel(sale.id, reason: reason));

  Future<Sale> voidSale(Sale sale, String reason) =>
      _run(() => _sales.voidSale(sale.id, reason));

  Future<Sale> deliver(Sale sale) => _run(() => _sales.deliver(sale.id));

  Future<T> _run<T>(Future<T> Function() action) async {
    busy = true;
    notifyListeners();
    try {
      return await action();
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  static ConfirmOutcome _outcome(ApiException e) {
    final d = e.details;
    switch (e.code) {
      case 'price_changed':
        final order = d['priced_order'];
        if (order is Map<String, dynamic>) {
          return PricesChanged(PricedOrder.fromJson(order));
        }
      case 'insufficient_stock':
        final items = d['items'];
        if (items is List) {
          return StockShort(
            items
                .map(
                  (i) =>
                      ShortItem.fromJson(Map<String, dynamic>.from(i as Map)),
                )
                .toList(),
          );
        }
      case 'credit_limit_exceeded':
        return CreditWarning(
          creditLimit: d['credit_limit'] as int? ?? 0,
          outstanding: d['outstanding'] as int? ?? 0,
          saleTotal: d['sale_total'] as int? ?? 0,
          creditApplied: d['credit_applied'] as int? ?? 0,
          projectedBalance: d['projected_balance'] as int? ?? 0,
        );
    }
    return ConfirmFailed(e);
  }
}
