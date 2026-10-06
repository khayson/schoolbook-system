import 'package:flutter/foundation.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/stock/data/stock_counts_repository.dart';
import 'package:schoolbook/features/stock/domain/stock_count.dart';

enum CountFilter { all, uncounted, variances }

/// One open count on the phone: load, search, enter quantities, progress, variances.
/// Every figure (system quantity, variance) comes back from the server.
class StockCountController extends ChangeNotifier {
  StockCountController({required this._repository, required this.countId});

  final StockCountsRepository _repository;
  final int countId;

  StockCount? count;
  String? loadError;
  bool loading = false;
  String query = '';
  CountFilter filter = CountFilter.all;
  int? savingProductId;

  Future<void> load() async {
    loading = true;
    loadError = null;
    notifyListeners();
    try {
      count = await _repository.get(countId);
    } on ApiException catch (e) {
      loadError = describeApiError(e).body;
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  int get countedItems => count?.items.where((i) => i.isCounted).length ?? 0;

  int get totalItems => count?.items.length ?? 0;

  double get progress => totalItems == 0 ? 0 : countedItems / totalItems;

  List<StockCountItem> get visibleItems {
    final q = query.trim().toLowerCase();
    return (count?.items ?? const <StockCountItem>[]).where((i) {
      final matchesFilter = switch (filter) {
        CountFilter.all => true,
        CountFilter.uncounted => !i.isCounted,
        CountFilter.variances => i.hasVariance,
      };
      return matchesFilter &&
          (q.isEmpty ||
              i.sku.toLowerCase().contains(q) ||
              i.title.toLowerCase().contains(q));
    }).toList();
  }

  StockCountItem? itemFor(int productId) {
    for (final item in count?.items ?? const <StockCountItem>[]) {
      if (item.productId == productId) {
        return item;
      }
    }
    return null;
  }

  void setQuery(String value) {
    query = value;
    notifyListeners();
  }

  void setFilter(CountFilter value) {
    filter = value;
    notifyListeners();
  }

  /// Saves one counted quantity. Returns an error message, or null on success.
  Future<String?> enter(int productId, int? countedQty) async {
    savingProductId = productId;
    notifyListeners();
    try {
      count = await _repository.enter(countId, productId, countedQty);
      return null;
    } on ApiException catch (e) {
      final d = describeApiError(e);
      return '${d.title}: ${d.body}';
    } finally {
      savingProductId = null;
      notifyListeners();
    }
  }
}
