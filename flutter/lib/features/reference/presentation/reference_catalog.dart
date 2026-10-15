import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/reference/data/reference_cache_store.dart';
import 'package:schoolbook/features/reference/data/reference_repository.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';
import 'package:schoolbook/features/reference/domain/reference_search.dart';

/// The approved list on the device: loaded from the stored copy, refreshed from the
/// server when its ETag changes (at login and on pull-to-refresh), searched offline.
/// Stock per title comes from the server too and is shown "as of" the last sync.
class ReferenceCatalog extends ChangeNotifier {
  ReferenceCatalog({
    required this._repository,
    required this._cache,
    DateTime Function()? clock,
  }) : _clock = clock ?? DateTime.now;

  final ReferenceRepository _repository;
  final ReferenceCacheStore _cache;
  final DateTime Function() _clock;

  List<ReferenceBook> _books = const [];
  ReferenceSearchIndex _index = ReferenceSearchIndex(const []);
  Map<int, TitleStock> _stock = const {};
  String? _etag;
  String? editionLabel;
  DateTime? syncedAt;
  bool loaded = false;
  bool syncing = false;

  /// The last sync could not reach the server; the stored copy is in use.
  bool offline = false;
  String? error;

  List<ReferenceBook> get books => _books;
  String? get etag => _etag;
  bool get isEmpty => _books.isEmpty;

  List<ReferenceBook> search(String query, {int limit = 50}) =>
      _index.search(query, limit: limit);

  /// Null when the shop has no product for the title.
  TitleStock? stockFor(int bookId) => _stock[bookId];

  ReferenceBook? byId(int id) => _books.where((b) => b.id == id).firstOrNull;

  /// Hint for an unknown scanned code: an approved title that already knows this ISBN.
  ReferenceBook? byIsbn(String code) {
    final digits = code.replaceAll(RegExp(r'[\s-]'), '');
    return _books.where((b) => b.isbn != null && b.isbn == digits).firstOrNull;
  }

  /// Reads the stored copy once.
  Future<void> load() async {
    if (loaded) return;
    final raw = await _cache.read();
    if (raw != null) {
      try {
        _apply(jsonDecode(raw) as Map<String, dynamic>);
      } on FormatException {
        // Corrupt copy: ignore it, the next sync downloads a fresh one.
      }
    }
    loaded = true;
    notifyListeners();
  }

  static const _freshFor = Duration(minutes: 30);

  bool get _isFresh =>
      syncedAt != null && _clock().difference(syncedAt!) < _freshFor;

  /// Snapshot of the approved list. Stock counts are optional — they paginate
  /// `/reference-books` and should only run when the approved-list UI opens.
  Future<void> sync({bool includeStock = false, bool force = false}) async {
    if (syncing) return;
    await load();
    if (!force && _isFresh && _books.isNotEmpty && !includeStock) {
      return;
    }
    if (!force && _isFresh && includeStock && _stock.isNotEmpty) {
      return;
    }
    syncing = true;
    error = null;
    notifyListeners();
    try {
      final snapshot = await _repository.fetchSnapshot(
        etag: _books.isEmpty ? null : _etag,
      );
      final stored = _stored();
      if (!snapshot.notModified && snapshot.data != null) {
        stored
          ..['etag'] = snapshot.etag
          ..['edition'] = snapshot.data!['edition']
          ..['books'] = snapshot.data!['books'];
      }
      if (includeStock) {
        final stock = await _repository.fetchStockedTitles();
        stored['stock'] = {
          for (final e in stock.entries)
            '${e.key}': [e.value.productsCount, e.value.stockOnHand],
        };
      } else {
        // Keep existing stock map when we only refresh titles.
        stored['stock'] = {
          for (final e in _stock.entries)
            '${e.key}': [e.value.productsCount, e.value.stockOnHand],
        };
      }
      stored['synced_at'] = _clock().toIso8601String();
      _apply(stored);
      await _cache.write(jsonEncode(stored));
      offline = false;
    } on ApiException catch (e) {
      offline = e.isNetworkError;
      error = e.isNetworkError ? null : e.message;
    } finally {
      syncing = false;
      notifyListeners();
    }
  }

  /// After a product is created for a title, until the next sync.
  void noteProductAdded(int bookId, int openingStock) {
    final current = _stock[bookId];
    _stock = {
      ..._stock,
      bookId: TitleStock(
        productsCount: (current?.productsCount ?? 0) + 1,
        stockOnHand: (current?.stockOnHand ?? 0) + openingStock,
      ),
    };
    notifyListeners();
  }

  Map<String, dynamic> _stored() => {
    'etag': _etag,
    'edition': editionLabel == null ? null : {'label': editionLabel},
    'books': [for (final b in _books) b.toJson()],
  };

  void _apply(Map<String, dynamic> json) {
    _etag = json['etag'] as String?;
    editionLabel =
        (json['edition'] as Map<String, dynamic>?)?['label'] as String?;
    _books = [
      for (final b in (json['books'] as List<dynamic>? ?? const []))
        ReferenceBook.fromJson(b as Map<String, dynamic>),
    ];
    _index = ReferenceSearchIndex(_books);
    final stock = json['stock'] as Map<String, dynamic>? ?? const {};
    _stock = {
      for (final e in stock.entries)
        int.parse(e.key): TitleStock(
          productsCount: (e.value as List)[0] as int,
          stockOnHand: (e.value as List)[1] as int,
        ),
    };
    syncedAt = json['synced_at'] == null
        ? null
        : DateTime.tryParse(json['synced_at'] as String);
  }
}
