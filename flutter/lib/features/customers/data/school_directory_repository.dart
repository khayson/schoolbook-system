import 'package:dio/dio.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/pagination.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/customers/domain/directory_school.dart';

class DirectorySearchResult {
  const DirectorySearchResult({required this.page, required this.attribution});

  final PaginatedResponse<DirectorySchool> page;
  final String attribution;
}

/// School directory: search, and add a school as a customer (docs/api.md).
class SchoolDirectoryRepository {
  SchoolDirectoryRepository({required ApiClient apiClient}) : _api = apiClient;

  final ApiClient _api;

  Future<DirectorySearchResult> search({
    String? search,
    String? region,
    int page = 1,
  }) async {
    final response = await _api.get<Map<String, dynamic>>(
      '/school-directory',
      queryParameters: {
        'page': page,
        'per_page': 25,
        if (search != null && search.trim().isNotEmpty) 'search': search.trim(),
        'region': ?region,
      },
    );
    final body = response.data!;
    final meta = body['meta'] as Map<String, dynamic>;
    return DirectorySearchResult(
      page: PaginatedResponse(
        data: (body['data'] as List<dynamic>)
            .map((e) => DirectorySchool.fromJson(e as Map<String, dynamic>))
            .toList(),
        meta: PaginatedMeta.fromJson(meta),
      ),
      attribution: meta['attribution'] as String? ?? '',
    );
  }

  /// Creates a customer from the directory entry, or links [linkCustomerId] to it.
  Future<Customer> addAsCustomer(
    int schoolId, {
    required String idempotencyKey,
    int? linkCustomerId,
    String? contactPerson,
    String? phone,
  }) async {
    final response = await _api.post<Map<String, dynamic>>(
      '/school-directory/$schoolId/customer',
      data: {
        'link_customer_id': ?linkCustomerId,
        if (contactPerson != null && contactPerson.trim().isNotEmpty)
          'contact_person': contactPerson.trim(),
        if (phone != null && phone.trim().isNotEmpty) 'phone': phone.trim(),
      },
      options: Options(headers: {'Idempotency-Key': idempotencyKey}),
    );
    return Customer.fromJson(response.data!['data'] as Map<String, dynamic>);
  }
}
