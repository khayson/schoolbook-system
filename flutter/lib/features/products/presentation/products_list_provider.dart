import 'package:flutter/foundation.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/catalog/data/lookups_repository.dart';
import 'package:schoolbook/features/catalog/domain/lookup_models.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';

class ProductsListProvider extends ChangeNotifier {
  ProductsListProvider({
    required ProductsRepository productsRepository,
    required LookupsRepository lookupsRepository,
  })  : _productsRepository = productsRepository,
        _lookupsRepository = lookupsRepository;

  final ProductsRepository _productsRepository;
  final LookupsRepository _lookupsRepository;

  final List<Product> products = [];
  List<LevelLookup> levels = [];
  List<NamedLookup> subjects = [];
  List<LanguageLookup> languages = [];

  bool isLoading = false;
  bool isLoadingMore = false;
  String? errorMessage;
  String searchQuery = '';
  int? levelId;
  int? subjectId;
  int? languageId;
  int _currentPage = 1;
  int _lastPage = 1;

  bool get canLoadMore => _currentPage < _lastPage;

  Future<void> initialize() async {
    if (levels.isNotEmpty) {
      return;
    }
    isLoading = true;
    notifyListeners();
    try {
      final fetchedLevels = _lookupsRepository.fetchLevels();
      final fetchedSubjects = _lookupsRepository.fetchSubjects();
      final fetchedLanguages = _lookupsRepository.fetchLanguages();
      levels = await fetchedLevels;
      subjects = await fetchedSubjects;
      languages = await fetchedLanguages;
    } on ApiException catch (e) {
      errorMessage = e.message;
    } finally {
      isLoading = false;
      notifyListeners();
    }
    await refresh();
  }

  Future<void> refresh() async {
    _currentPage = 1;
    isLoading = true;
    errorMessage = null;
    notifyListeners();
    try {
      final page = await _productsRepository.listProducts(
        page: 1,
        search: searchQuery,
        levelId: levelId,
        subjectId: subjectId,
        languageId: languageId,
      );
      products
        ..clear()
        ..addAll(page.data);
      _lastPage = page.meta.lastPage;
    } on ApiException catch (e) {
      errorMessage = e.message;
      products.clear();
    } finally {
      isLoading = false;
      notifyListeners();
    }
  }

  Future<void> loadMore() async {
    if (isLoadingMore || !canLoadMore) {
      return;
    }
    isLoadingMore = true;
    notifyListeners();
    try {
      final nextPage = _currentPage + 1;
      final page = await _productsRepository.listProducts(
        page: nextPage,
        search: searchQuery,
        levelId: levelId,
        subjectId: subjectId,
        languageId: languageId,
      );
      products.addAll(page.data);
      _currentPage = nextPage;
      _lastPage = page.meta.lastPage;
    } on ApiException catch (e) {
      errorMessage = e.message;
    } finally {
      isLoadingMore = false;
      notifyListeners();
    }
  }

  void setSearch(String value) {
    searchQuery = value;
  }

  void setLevelFilter(int? id) {
    levelId = id;
    refresh();
  }

  void setSubjectFilter(int? id) {
    subjectId = id;
    refresh();
  }

  void setLanguageFilter(int? id) {
    languageId = id;
    refresh();
  }
}
