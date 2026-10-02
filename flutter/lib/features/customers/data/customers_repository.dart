import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/pagination.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/sales/domain/sale_summary.dart';

class CustomersRepository {
  CustomersRepository({required ApiClient apiClient}) : _api = apiClient;

  final ApiClient _api;

  Future<PaginatedResponse<Customer>> listCustomers({
    int page = 1,
    String? search,
  }) async {
    final response = await _api.get<Map<String, dynamic>>(
      '/customers',
      queryParameters: {
        'page': page,
        'per_page': 25,
        if (search != null && search.trim().isNotEmpty) 'search': search.trim(),
      },
    );
    final body = response.data!;
    return PaginatedResponse(
      data: (body['data'] as List<dynamic>)
          .map((e) => Customer.fromJson(e as Map<String, dynamic>))
          .toList(),
      meta: PaginatedMeta.fromJson(body['meta'] as Map<String, dynamic>),
    );
  }

  Future<Customer> getCustomer(int id) async {
    final response = await _api.get<Map<String, dynamic>>('/customers/$id');
    return Customer.fromJson(response.data!['data'] as Map<String, dynamic>);
  }

  /// [payload] uses API keys; `credit_limit` is pesewas or null.
  Future<Customer> createCustomer(Map<String, dynamic> payload) async {
    final response = await _api.post<Map<String, dynamic>>('/customers', data: payload);
    return Customer.fromJson(response.data!['data'] as Map<String, dynamic>);
  }

  Future<Customer> updateCustomer(int id, Map<String, dynamic> payload) async {
    final response = await _api.put<Map<String, dynamic>>('/customers/$id', data: payload);
    return Customer.fromJson(response.data!['data'] as Map<String, dynamic>);
  }

  /// Newest first.
  Future<List<SaleSummary>> recentSales(int customerId, {int perPage = 10}) async {
    final response = await _api.get<Map<String, dynamic>>(
      '/sales',
      queryParameters: {'customer_id': customerId, 'per_page': perPage},
    );
    return (response.data!['data'] as List<dynamic>)
        .map((e) => SaleSummary.fromJson(e as Map<String, dynamic>))
        .toList();
  }
}
