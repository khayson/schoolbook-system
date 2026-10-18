import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/pagination.dart';
import 'package:schoolbook/features/exchanges/data/exchanges_repository.dart';
import 'package:schoolbook/features/exchanges/domain/stock_exchange.dart';
import 'package:schoolbook/features/exchanges/presentation/exchange_actions_controller.dart';

ApiClient _unused() => ApiClient(tokenStore: AuthTokenStore());

StockExchange draftExchange() => StockExchange.fromJson({
  'id': 5,
  'customer_id': 1,
  'counterparty_name': 'Kojo',
  'status': 'draft',
  'created_by': 1,
  'updated_at': '2026-10-18T10:00:00Z',
  'items': [],
});

class FakeExchangesRepository extends ExchangesRepository {
  FakeExchangesRepository({List<Object>? confirmResults})
    : confirmResults = confirmResults ?? [],
      super(apiClient: _unused());

  final List<Object> confirmResults;
  final List<({String key, Map<String, dynamic> options})> confirmCalls = [];

  @override
  Future<StockExchange> confirm(
    int id, {
    required String idempotencyKey,
    required Map<String, dynamic> options,
  }) async {
    confirmCalls.add((key: idempotencyKey, options: options));
    final next = confirmResults.isEmpty
        ? StockExchange.fromJson({
            'id': id,
            'exchange_no': 'EXC-2026-000001',
            'customer_id': 1,
            'counterparty_name': 'Kojo',
            'status': 'confirmed',
            'sale_id': 40,
            'created_by': 1,
            'updated_at': '2026-10-18T10:01:00Z',
            'sale': {'invoice_no': 'INV-2026-000040', 'total': 2000},
            'items': [],
          })
        : confirmResults.removeAt(0);
    if (next is ApiException) throw next;
    return next as StockExchange;
  }

  @override
  Future<PaginatedResponse<StockExchange>> listExchanges({
    int page = 1,
    String? status,
    int? customerId,
  }) async =>
      throw UnimplementedError();
}

void main() {
  late FakeExchangesRepository exchanges;
  late PendingSubmissionStore store;
  late ExchangeActionsController controller;

  setUp(() {
    exchanges = FakeExchangesRepository();
    store = PendingSubmissionStore(store: InMemoryKeyValueStore());
    controller = ExchangeActionsController(
      exchanges: exchanges,
      pendingStore: store,
    );
  });

  test('confirm sends body and clears key on success', () async {
    final draft = draftExchange();
    final outcome = await controller.confirm(draft);

    expect(outcome, isA<ExchangeConfirmed>());
    expect(exchanges.confirmCalls, hasLength(1));
    expect(exchanges.confirmCalls.first.options['override_credit_limit'], false);
    expect(
      await store.pending(ExchangeActionsController.confirmIntent(5)),
      isNull,
    );
  });

  test('credit limit warning surfaces figures', () async {
    exchanges.confirmResults.add(
      ApiException(
        message: 'Over limit',
        code: 'credit_limit_exceeded',
        statusCode: 409,
        details: const {
          'credit_limit': 1000,
          'outstanding': 0,
          'sale_total': 2000,
          'credit_applied': 0,
          'projected_balance': 2000,
        },
      ),
    );

    final outcome = await controller.confirm(draftExchange());
    expect(outcome, isA<ExchangeCreditWarning>());
    final warning = outcome as ExchangeCreditWarning;
    expect(warning.saleTotal, 2000);
    expect(warning.projectedBalance, 2000);
  });

  test('override retry uses override_credit_limit true', () async {
    exchanges.confirmResults.add(
      ApiException(
        message: 'Over limit',
        code: 'credit_limit_exceeded',
        statusCode: 409,
        details: const {
          'credit_limit': 1000,
          'outstanding': 0,
          'sale_total': 2000,
          'credit_applied': 0,
          'projected_balance': 2000,
        },
      ),
    );

    await controller.confirm(draftExchange());
    final retry = await controller.confirm(
      draftExchange(),
      overrideCreditLimit: true,
    );

    expect(retry, isA<ExchangeConfirmed>());
    expect(exchanges.confirmCalls.last.options['override_credit_limit'], true);
  });
}
