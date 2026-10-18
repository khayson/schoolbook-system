import 'package:flutter/foundation.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/features/exchanges/data/exchanges_repository.dart';
import 'package:schoolbook/features/exchanges/domain/stock_exchange.dart';
import 'package:schoolbook/features/sales/presentation/sale_actions_controller.dart';

sealed class ExchangeConfirmOutcome {
  const ExchangeConfirmOutcome();
}

class ExchangeConfirmed extends ExchangeConfirmOutcome {
  const ExchangeConfirmed(this.exchange);
  final StockExchange exchange;
}

class ExchangeCreditWarning extends ExchangeConfirmOutcome {
  const ExchangeCreditWarning({
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

class ExchangeStockShort extends ExchangeConfirmOutcome {
  const ExchangeStockShort(this.items);
  final List<ShortItem> items;
}

class ExchangeConfirmFailed extends ExchangeConfirmOutcome {
  const ExchangeConfirmFailed(this.error);
  final ApiException error;
}

/// Confirm a draft exchange with a persisted idempotency key.
class ExchangeActionsController extends ChangeNotifier {
  ExchangeActionsController({
    required ExchangesRepository exchanges,
    required PendingSubmissionStore pendingStore,
  }) : _exchanges = exchanges,
       _pendingStore = pendingStore;

  final ExchangesRepository _exchanges;
  final PendingSubmissionStore _pendingStore;

  bool busy = false;

  static String confirmIntent(int exchangeId) =>
      'confirm_stock_exchange.$exchangeId';

  static Map<String, dynamic> confirmBody({
    ExchangeSettlement? settlement,
    DateTime? dueDate,
    bool applyCredit = false,
    bool overrideCreditLimit = false,
  }) {
    return {
      'settlement': settlement?.apiValue,
      'due_date': dueDate == null
          ? null
          : '${dueDate.year.toString().padLeft(4, '0')}-${dueDate.month.toString().padLeft(2, '0')}-${dueDate.day.toString().padLeft(2, '0')}',
      'apply_credit': applyCredit,
      'override_credit_limit': overrideCreditLimit,
    };
  }

  static Map<String, dynamic> confirmKeyPayload(
    StockExchange exchange,
    Map<String, dynamic> body,
  ) {
    return {
      'stock_exchange_id': exchange.id,
      'updated_at': exchange.updatedAt,
      ...body,
    };
  }

  Future<ExchangeConfirmOutcome> confirm(
    StockExchange exchange, {
    ExchangeSettlement? settlement,
    DateTime? dueDate,
    bool applyCredit = false,
    bool overrideCreditLimit = false,
  }) async {
    final body = confirmBody(
      settlement: settlement ?? exchange.settlement,
      dueDate: dueDate,
      applyCredit: applyCredit,
      overrideCreditLimit: overrideCreditLimit,
    );
    final intent = confirmIntent(exchange.id);

    busy = true;
    notifyListeners();
    try {
      final key = await _pendingStore.keyFor(
        intent,
        confirmKeyPayload(exchange, body),
      );
      try {
        final confirmed = await _exchanges.confirm(
          exchange.id,
          idempotencyKey: key,
          options: body,
        );
        await _pendingStore.complete(intent);
        return ExchangeConfirmed(confirmed);
      } on ApiException catch (e) {
        if (!e.isOutcomeUnknown) {
          await _pendingStore.complete(intent);
        }
        return _outcome(e);
      }
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  static ExchangeConfirmOutcome _outcome(ApiException e) {
    final d = e.details;
    switch (e.code) {
      case 'insufficient_stock':
        final items = d['items'];
        if (items is List) {
          return ExchangeStockShort(
            items
                .map(
                  (i) =>
                      ShortItem.fromJson(Map<String, dynamic>.from(i as Map)),
                )
                .toList(),
          );
        }
      case 'credit_limit_exceeded':
        return ExchangeCreditWarning(
          creditLimit: d['credit_limit'] as int? ?? 0,
          outstanding: d['outstanding'] as int? ?? 0,
          saleTotal: d['sale_total'] as int? ?? 0,
          creditApplied: d['credit_applied'] as int? ?? 0,
          projectedBalance: d['projected_balance'] as int? ?? 0,
        );
    }
    return ExchangeConfirmFailed(e);
  }
}
