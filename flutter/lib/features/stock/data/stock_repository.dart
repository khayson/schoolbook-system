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

  Future<List<Map<String, dynamic>>> listReceipts({int page = 1}) async {
    final response = await _api.get<Map<String, dynamic>>(
      '/stock/receipts',
      queryParameters: {'page': page},
    );
    final data = response.data!['data'];
    if (data is List) {
      return data.cast<Map<String, dynamic>>();
    }
    return const [];
  }

  Future<Map<String, dynamic>> getReceipt(int id) async {
    final response = await _api.get<Map<String, dynamic>>('/stock/receipts/$id');
    final body = response.data!;
    final data = body['data'];
    if (data is Map<String, dynamic>) return data;
    return body;
  }

  Future<Map<String, dynamic>> adjustStock({
    required int productId,
    required int quantity,
    required String type,
    required String note,
  }) async {
    final response = await _api.post<Map<String, dynamic>>(
      '/stock/adjustments',
      data: {
        'product_id': productId,
        'quantity': quantity,
        'type': type,
        'note': note,
      },
    );
    final body = response.data!;
    final data = body['data'];
    if (data is Map<String, dynamic>) return data;
    return body;
  }
}
