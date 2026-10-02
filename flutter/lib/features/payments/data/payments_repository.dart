import 'package:dio/dio.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/pagination.dart';
import 'package:schoolbook/features/payments/domain/payment.dart';

class PaymentsRepository {
  PaymentsRepository({required ApiClient apiClient}) : _api = apiClient;

  final ApiClient _api;

  Future<PaginatedResponse<Payment>> listPayments({
    int page = 1,
    int perPage = 25,
    int? customerId,
    String? status,
  }) async {
    final response = await _api.get<Map<String, dynamic>>(
      '/payments',
      queryParameters: {
        'page': page,
        'per_page': perPage,
        'customer_id': ?customerId,
        'status': ?status,
      },
    );
    final body = response.data!;
    return PaginatedResponse(
      data: (body['data'] as List<dynamic>)
          .map((e) => Payment.fromJson(e as Map<String, dynamic>))
          .toList(),
      meta: PaginatedMeta.fromJson(body['meta'] as Map<String, dynamic>),
    );
  }

  Future<Payment> getPayment(int id) async {
    final response = await _api.get<Map<String, dynamic>>('/payments/$id');
    return Payment.fromJson(response.data!['data'] as Map<String, dynamic>);
  }

  /// `POST /payments`. [payload] is exactly the request body (amount in pesewas).
  Future<Payment> recordPayment({
    required String idempotencyKey,
    required Map<String, dynamic> payload,
  }) async {
    final response = await _api.post<Map<String, dynamic>>(
      '/payments',
      data: payload,
      options: Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    return Payment.fromJson(response.data!['data'] as Map<String, dynamic>);
  }

  Future<Payment> voidPayment(int id, String reason) async {
    final response = await _api.post<Map<String, dynamic>>(
      '/payments/$id/void',
      data: {'reason': reason},
    );
    return Payment.fromJson(response.data!['data'] as Map<String, dynamic>);
  }

  /// Oldest invoices first (no explicit allocations from the phone).
  Future<CreditApplication> applyCredit({
    required int customerId,
    required String idempotencyKey,
  }) async {
    final response = await _api.post<Map<String, dynamic>>(
      '/customers/$customerId/apply-credit',
      data: const <String, dynamic>{},
      options: Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    return CreditApplication.fromJson(response.data!['data'] as Map<String, dynamic>);
  }

  Future<List<int>> receiptPdf(int paymentId) => _api.getBytes('/payments/$paymentId/receipt');
}
