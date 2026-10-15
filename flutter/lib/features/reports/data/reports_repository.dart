import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/features/reports/domain/report_models.dart';

/// Owner reports (`/reports/*`). Dates default to today on the server (Africa/Accra).
class ReportsRepository {
  ReportsRepository({required ApiClient apiClient}) : _api = apiClient;

  final ApiClient _api;

  Future<Map<String, dynamic>> _data(
    String path, {
    Map<String, dynamic>? query,
  }) async {
    final response = await _api.get<Map<String, dynamic>>(
      path,
      queryParameters: query,
    );
    return response.data!['data'] as Map<String, dynamic>;
  }

  Future<DashboardFigures> dashboard({String? date}) async {
    final data = await _data('/reports/dashboard', query: {'date': ?date});
    return DashboardFigures.fromJson(data);
  }

  Future<AgingReport> receivablesAging({String? asOf}) async {
    final data =
        await _data('/reports/receivables-aging', query: {'as_of': ?asOf});
    return AgingReport.fromJson(data);
  }

  Future<List<LowStockRow>> lowStock() async {
    final data = await _data('/reports/low-stock');
    return (data['rows'] as List<dynamic>)
        .map((e) => LowStockRow.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<Map<String, dynamic>> salesSummary({
    required String from,
    required String to,
  }) =>
      _data('/reports/sales-summary', query: {
        'from': from,
        'to': to,
        'group_by': 'day',
      });

  Future<Map<String, dynamic>> profit({
    required String from,
    required String to,
  }) =>
      _data('/reports/profit', query: {
        'from': from,
        'to': to,
        'group_by': 'product',
        'period': 'month',
      });

  Future<Map<String, dynamic>> bestSellers({
    required String from,
    required String to,
  }) =>
      _data('/reports/best-sellers', query: {
        'from': from,
        'to': to,
        'by': 'product',
        'sort': 'quantity',
        'limit': 25,
      });

  Future<Map<String, dynamic>> stockValuation() =>
      _data('/reports/stock-valuation');

  Future<Map<String, dynamic>> deadStock() =>
      _data('/reports/dead-stock', query: {'days': 90});
}
