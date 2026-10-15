import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/pagination.dart';
import 'package:schoolbook/features/catalog/domain/lookup_models.dart';

/// Catalog lookups with an in-memory cache so opening Products / Add books
/// does not re-hit `/levels`, `/subjects`, `/languages` every time.
class LookupsRepository {
  LookupsRepository({required ApiClient apiClient}) : _api = apiClient;

  final ApiClient _api;

  List<LevelLookup>? _levels;
  List<NamedLookup>? _subjects;
  List<LanguageLookup>? _languages;
  List<NamedLookup>? _publishers;

  Future<List<LevelLookup>>? _levelsInFlight;
  Future<List<NamedLookup>>? _subjectsInFlight;
  Future<List<LanguageLookup>>? _languagesInFlight;
  Future<List<NamedLookup>>? _publishersInFlight;

  Future<List<LevelLookup>> fetchLevels({bool force = false}) {
    if (!force && _levels != null) return Future.value(_levels!);
    if (!force && _levelsInFlight != null) return _levelsInFlight!;
    final future = _fetchAll('/levels', LevelLookup.fromJson).then((items) {
      _levels = items;
      return items;
    });
    _levelsInFlight = future;
    return future.whenComplete(() {
      if (identical(_levelsInFlight, future)) _levelsInFlight = null;
    });
  }

  Future<List<NamedLookup>> fetchSubjects({bool force = false}) {
    if (!force && _subjects != null) return Future.value(_subjects!);
    if (!force && _subjectsInFlight != null) return _subjectsInFlight!;
    final future = _fetchAll('/subjects', NamedLookup.fromJson).then((items) {
      _subjects = items;
      return items;
    });
    _subjectsInFlight = future;
    return future.whenComplete(() {
      if (identical(_subjectsInFlight, future)) _subjectsInFlight = null;
    });
  }

  Future<List<LanguageLookup>> fetchLanguages({bool force = false}) {
    if (!force && _languages != null) return Future.value(_languages!);
    if (!force && _languagesInFlight != null) return _languagesInFlight!;
    final future = _fetchAll(
      '/languages',
      LanguageLookup.fromJson,
    ).then((items) {
      _languages = items;
      return items;
    });
    _languagesInFlight = future;
    return future.whenComplete(() {
      if (identical(_languagesInFlight, future)) _languagesInFlight = null;
    });
  }

  Future<List<NamedLookup>> fetchPublishers({bool force = false}) {
    if (!force && _publishers != null) return Future.value(_publishers!);
    if (!force && _publishersInFlight != null) return _publishersInFlight!;
    final future = _fetchAll('/publishers', NamedLookup.fromJson).then((items) {
      _publishers = items;
      return items;
    });
    _publishersInFlight = future;
    return future.whenComplete(() {
      if (identical(_publishersInFlight, future)) _publishersInFlight = null;
    });
  }

  /// Warm the cache in the background (levels / subjects / languages).
  Future<void> prefetchProductFilters() => Future.wait([
        fetchLevels(),
        fetchSubjects(),
        fetchLanguages(),
      ]).then((_) {});

  void clearCache() {
    _levels = null;
    _subjects = null;
    _languages = null;
    _publishers = null;
    _levelsInFlight = null;
    _subjectsInFlight = null;
    _languagesInFlight = null;
    _publishersInFlight = null;
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
