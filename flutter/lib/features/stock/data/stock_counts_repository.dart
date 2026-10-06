import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/features/stock/domain/stock_count.dart';

/// Stock-take entry from the phone. Applying a count is web only (docs/decisions.md).
class StockCountsRepository {
  StockCountsRepository({required ApiClient apiClient}) : _api = apiClient;

  final ApiClient _api;

  Future<List<StockCount>> openCounts() async {
    final response = await _api.get<Map<String, dynamic>>(
      '/stock/counts',
      queryParameters: {'status': 'open', 'per_page': 25},
    );
    return (response.data!['data'] as List<dynamic>)
        .map((e) => StockCount.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  /// A new count of every active product.
  Future<StockCount> create() async {
    final response = await _api.post<Map<String, dynamic>>(
      '/stock/counts',
      data: const <String, dynamic>{},
    );
    return StockCount.fromJson(response.data!['data'] as Map<String, dynamic>);
  }

  Future<StockCount> get(int id) async {
    final response = await _api.get<Map<String, dynamic>>('/stock/counts/$id');
    return StockCount.fromJson(response.data!['data'] as Map<String, dynamic>);
  }

  /// Records one counted quantity (null clears it). Entering again is safe: the server
  /// recomputes from the current stock, so a retried request gives the same result.
  Future<StockCount> enter(int countId, int productId, int? countedQty) async {
    final response = await _api.put<Map<String, dynamic>>(
      '/stock/counts/$countId/items',
      data: {
        'items': [
          {'product_id': productId, 'counted_qty': countedQty},
        ],
      },
    );
    return StockCount.fromJson(response.data!['data'] as Map<String, dynamic>);
  }
}
