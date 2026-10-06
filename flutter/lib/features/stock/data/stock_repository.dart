import 'package:dio/dio.dart';
import 'package:schoolbook/core/api_client.dart';

class StockRepository {
  StockRepository({required ApiClient apiClient}) : _api = apiClient;

  final ApiClient _api;

  Future<Map<String, dynamic>> createReceipt({
    required String idempotencyKey,
    required List<Map<String, dynamic>> items,
    String? supplierReference,
    String? notes,
    DateTime? receivedAt,
  }) async {
    final response = await _api.post<Map<String, dynamic>>(
      '/stock/receipts',
      data: {
        'supplier_id': null,
        'supplier_reference': supplierReference,
        'received_at': (receivedAt ?? DateTime.now()).toUtc().toIso8601String(),
        'notes': notes,
        'items': items,
      },
      options: Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    return response.data!;
  }
}
