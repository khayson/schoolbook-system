import 'package:dio/dio.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';

/// Result of `GET /reference-books/snapshot`.
class SnapshotResult {
  const SnapshotResult.notModified(this.etag) : notModified = true, data = null;

  const SnapshotResult.downloaded(this.etag, this.data) : notModified = false;

  final bool notModified;
  final String? etag;

  /// `{edition, count, books}` when downloaded.
  final Map<String, dynamic>? data;
}

class ReferenceRepository {
  ReferenceRepository({required ApiClient apiClient}) : _api = apiClient;

  final ApiClient _api;

  /// The whole live list. With [etag] (the stored copy's), an unchanged list answers
  /// 304 and nothing is downloaded. The HTTP client asks for gzip and inflates it.
  Future<SnapshotResult> fetchSnapshot({String? etag}) async {
    final response = await _api.get<Map<String, dynamic>>(
      '/reference-books/snapshot',
      options: Options(
        headers: {'If-None-Match': ?etag},
        validateStatus: (status) =>
            status != null && (status < 300 || status == 304),
      ),
    );
    final newEtag = response.headers.value('etag');
    if (response.statusCode == 304) {
      return SnapshotResult.notModified(newEtag ?? etag);
    }

    return SnapshotResult.downloaded(
      newEtag,
      response.data!['data'] as Map<String, dynamic>,
    );
  }

  /// Titles the shop has products for, with their total stock (`stocked=1`).
  Future<Map<int, TitleStock>> fetchStockedTitles() async {
    final stock = <int, TitleStock>{};
    var page = 1;
    while (true) {
      final response = await _api.get<Map<String, dynamic>>(
        '/reference-books',
        queryParameters: {'stocked': 1, 'per_page': 100, 'page': page},
      );
      final body = response.data!;
      for (final item in body['data'] as List<dynamic>) {
        final json = item as Map<String, dynamic>;
        stock[json['id'] as int] = TitleStock(
          productsCount: json['products_count'] as int? ?? 0,
          stockOnHand: json['stock_on_hand'] as int? ?? 0,
        );
      }
      final meta = body['meta'] as Map<String, dynamic>;
      if (page >= (meta['last_page'] as int? ?? 1)) {
        return stock;
      }
      page++;
    }
  }
}
