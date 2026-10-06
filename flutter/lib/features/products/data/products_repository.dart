import 'package:dio/dio.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/pagination.dart';
import 'package:schoolbook/features/products/domain/product.dart';

class ProductsRepository {
  ProductsRepository({required ApiClient apiClient}) : _api = apiClient;

  final ApiClient _api;

  Future<PaginatedResponse<Product>> listProducts({
    int page = 1,
    String? search,
    int? levelId,
    int? subjectId,
    int? languageId,
  }) async {
    final query = <String, dynamic>{'page': page, 'per_page': 25};
    if (search != null && search.trim().isNotEmpty) {
      query['search'] = search.trim();
    }
    if (levelId != null) {
      query['level_id'] = levelId;
    }
    if (subjectId != null) {
      query['subject_id'] = subjectId;
    }
    if (languageId != null) {
      query['language_id'] = languageId;
    }

    final response = await _api.get<Map<String, dynamic>>(
      '/products',
      queryParameters: query,
    );
    final body = response.data!;
    final data = (body['data'] as List<dynamic>)
        .map((e) => Product.fromJson(e as Map<String, dynamic>))
        .toList();
    final meta = PaginatedMeta.fromJson(body['meta'] as Map<String, dynamic>);
    return PaginatedResponse(data: data, meta: meta);
  }

  Future<Product> getProduct(int id) async {
    final response = await _api.get<Map<String, dynamic>>('/products/$id');
    return Product.fromJson(_unwrapResource(response.data!));
  }

  Future<Product> getByCode(String code) async {
    final encoded = Uri.encodeComponent(code);
    final response = await _api.get<Map<String, dynamic>>(
      '/products/by-code/$encoded',
    );
    return Product.fromJson(_unwrapResource(response.data!));
  }

  /// [idempotencyKey]: sent by the quick-create (a retried save returns the first
  /// product); the older product form sends none.
  Future<Product> createProduct(
    Map<String, dynamic> payload, {
    String? idempotencyKey,
  }) async {
    final response = await _api.post<Map<String, dynamic>>(
      '/products',
      data: payload,
      options: idempotencyKey == null
          ? null
          : Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    return Product.fromJson(_unwrapResource(response.data!));
  }

  /// "Scan to learn": attaches an unknown scanned [code] to a product. 409
  /// `duplicate_code` when another product has it, `code_slot_taken` when the product
  /// already has a different code there.
  Future<Product> attachCode({
    required String code,
    required int productId,
  }) async {
    final response = await _api.post<Map<String, dynamic>>(
      '/products/attach-code',
      data: {'code': code, 'product_id': productId},
    );
    return Product.fromJson(_unwrapResource(response.data!));
  }

  Future<Product> updateProduct(int id, Map<String, dynamic> payload) async {
    final response = await _api.put<Map<String, dynamic>>(
      '/products/$id',
      data: payload,
    );
    return Product.fromJson(_unwrapResource(response.data!));
  }

  Map<String, dynamic> _unwrapResource(Map<String, dynamic> json) {
    final data = json['data'];
    if (data is Map<String, dynamic>) {
      return data;
    }
    return json;
  }

  Future<PaginatedResponse<StockMovement>> listMovements(
    int productId, {
    int page = 1,
  }) async {
    final response = await _api.get<Map<String, dynamic>>(
      '/products/$productId/movements',
      queryParameters: {'page': page, 'per_page': 25},
    );
    final body = response.data!;
    final data = (body['data'] as List<dynamic>)
        .map((e) => StockMovement.fromJson(e as Map<String, dynamic>))
        .toList();
    final meta = PaginatedMeta.fromJson(body['meta'] as Map<String, dynamic>);
    return PaginatedResponse(data: data, meta: meta);
  }
}
