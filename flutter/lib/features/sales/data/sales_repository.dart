import 'package:dio/dio.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/pagination.dart';
import 'package:schoolbook/features/sales/domain/sale.dart';

class SalesRepository {
  SalesRepository({required ApiClient apiClient}) : _api = apiClient;

  final ApiClient _api;

  Future<PaginatedResponse<Sale>> listSales({
    int page = 1,
    String? status,
    String? paymentStatus,
  }) async {
    final response = await _api.get<Map<String, dynamic>>(
      '/sales',
      queryParameters: {
        'page': page,
        'per_page': 25,
        'status': ?status,
        'payment_status': ?paymentStatus,
      },
    );
    final body = response.data!;
    return PaginatedResponse(
      data: (body['data'] as List<dynamic>).map((e) => Sale.fromJson(e as Map<String, dynamic>)).toList(),
      meta: PaginatedMeta.fromJson(body['meta'] as Map<String, dynamic>),
    );
  }

  Future<Sale> getSale(int id) async {
    final response = await _api.get<Map<String, dynamic>>('/sales/$id');
    return _sale(response.data!);
  }

  /// Server-side pricing for live totals; the app never computes money.
  Future<PricedOrder> preview({int? customerId, required List<Map<String, dynamic>> items}) async {
    final response = await _api.post<Map<String, dynamic>>(
      '/pricing/preview',
      data: {'customer_id': customerId, 'items': items},
    );
    return PricedOrder.fromJson(response.data!['data'] as Map<String, dynamic>);
  }

  /// `POST /sales` (idempotent).
  Future<Sale> createDraft({required String idempotencyKey, required Map<String, dynamic> payload}) async {
    final response = await _api.post<Map<String, dynamic>>(
      '/sales',
      data: payload,
      options: Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    return _sale(response.data!);
  }

  /// `PUT /sales/{id}`; `{}` re-prices the draft at current prices.
  Future<Sale> updateDraft(int id, Map<String, dynamic> payload) async {
    final response = await _api.put<Map<String, dynamic>>('/sales/$id', data: payload);
    return _sale(response.data!);
  }

  /// `POST /sales/{id}/confirm` (idempotent).
  Future<Sale> confirm(int id, {required String idempotencyKey, required Map<String, dynamic> options}) async {
    final response = await _api.post<Map<String, dynamic>>(
      '/sales/$id/confirm',
      data: options,
      options: Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    return _sale(response.data!);
  }

  Future<Sale> cancel(int id, {String? reason}) async {
    final response = await _api.post<Map<String, dynamic>>('/sales/$id/cancel', data: {'reason': reason});
    return _sale(response.data!);
  }

  Future<Sale> voidSale(int id, String reason) async {
    final response = await _api.post<Map<String, dynamic>>('/sales/$id/void', data: {'reason': reason});
    return _sale(response.data!);
  }

  Future<Sale> deliver(int id) async {
    final response = await _api.post<Map<String, dynamic>>('/sales/$id/deliver');
    return _sale(response.data!);
  }

  Future<List<int>> invoicePdf(int id) => _api.getBytes('/sales/$id/invoice');

  Sale _sale(Map<String, dynamic> body) => Sale.fromJson(body['data'] as Map<String, dynamic>);
}
