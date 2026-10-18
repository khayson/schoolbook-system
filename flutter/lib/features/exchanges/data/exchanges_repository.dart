import 'package:dio/dio.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/pagination.dart';
import 'package:schoolbook/features/exchanges/domain/stock_exchange.dart';

class ExchangesRepository {
  ExchangesRepository({required ApiClient apiClient}) : _api = apiClient;

  final ApiClient _api;

  Future<PaginatedResponse<StockExchange>> listExchanges({
    int page = 1,
    String? status,
    int? customerId,
  }) async {
    final response = await _api.get<Map<String, dynamic>>(
      '/stock/exchanges',
      queryParameters: {
        'page': page,
        'per_page': 25,
        'status': ?status,
        'customer_id': ?customerId,
      },
    );
    final body = response.data!;
    return PaginatedResponse(
      data: (body['data'] as List<dynamic>)
          .map((e) => StockExchange.fromJson(e as Map<String, dynamic>))
          .toList(),
      meta: PaginatedMeta.fromJson(body['meta'] as Map<String, dynamic>),
    );
  }

  Future<StockExchange> getExchange(int id) async {
    final response = await _api.get<Map<String, dynamic>>(
      '/stock/exchanges/$id',
    );
    return _exchange(response.data!);
  }

  Future<StockExchange> createDraft({
    required String idempotencyKey,
    required Map<String, dynamic> payload,
  }) async {
    final response = await _api.post<Map<String, dynamic>>(
      '/stock/exchanges',
      data: payload,
      options: Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    return _exchange(response.data!);
  }

  Future<StockExchange> updateDraft(
    int id,
    Map<String, dynamic> payload,
  ) async {
    final response = await _api.put<Map<String, dynamic>>(
      '/stock/exchanges/$id',
      data: payload,
    );
    return _exchange(response.data!);
  }

  Future<StockExchange> confirm(
    int id, {
    required String idempotencyKey,
    required Map<String, dynamic> options,
  }) async {
    final response = await _api.post<Map<String, dynamic>>(
      '/stock/exchanges/$id/confirm',
      data: options,
      options: Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    return _exchange(response.data!);
  }

  Future<void> cancel(int id) async {
    await _api.post<void>('/stock/exchanges/$id/cancel');
  }

  Future<StockExchange> voidExchange(int id, String reason) async {
    final response = await _api.post<Map<String, dynamic>>(
      '/stock/exchanges/$id/void',
      data: {'reason': reason},
    );
    return _exchange(response.data!);
  }

  StockExchange _exchange(Map<String, dynamic> body) {
    final data = body['data'];
    if (data is Map<String, dynamic>) {
      return StockExchange.fromJson(data);
    }
    return StockExchange.fromJson(body);
  }
}
