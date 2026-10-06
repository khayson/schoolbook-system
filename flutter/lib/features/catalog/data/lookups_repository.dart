import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/pagination.dart';
import 'package:schoolbook/features/catalog/domain/lookup_models.dart';

class LookupsRepository {
  LookupsRepository({required ApiClient apiClient}) : _api = apiClient;

  final ApiClient _api;

  Future<List<LevelLookup>> fetchLevels() async {
    return _fetchAll('/levels', (json) => LevelLookup.fromJson(json));
  }

  Future<List<NamedLookup>> fetchSubjects() async {
    return _fetchAll('/subjects', (json) => NamedLookup.fromJson(json));
  }

  Future<List<LanguageLookup>> fetchLanguages() async {
    return _fetchAll('/languages', (json) => LanguageLookup.fromJson(json));
  }

  Future<List<NamedLookup>> fetchPublishers() async {
    return _fetchAll('/publishers', (json) => NamedLookup.fromJson(json));
  }

  Future<List<T>> _fetchAll<T>(
    String path,
    T Function(Map<String, dynamic>) fromJson,
  ) async {
    final items = <T>[];
    var page = 1;
    while (true) {
      final response = await _api.get<Map<String, dynamic>>(
        path,
        queryParameters: {'page': page, 'per_page': 100},
      );
      final body = response.data!;
      final data = body['data'] as List<dynamic>;
      items.addAll(data.map((e) => fromJson(e as Map<String, dynamic>)));
      final meta = PaginatedMeta.fromJson(body['meta'] as Map<String, dynamic>);
      if (page >= meta.lastPage) {
        break;
      }
      page++;
    }
    return items;
  }
}
