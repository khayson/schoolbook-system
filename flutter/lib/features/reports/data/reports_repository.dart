import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/features/reports/domain/report_models.dart';

/// Owner reports (`/reports/*`). Dates default to today on the server (Africa/Accra).
class ReportsRepository {
  ReportsRepository({required ApiClient apiClient}) : _api = apiClient;

  final ApiClient _api;

  Future<DashboardFigures> dashboard({String? date}) async {
    final response = await _api.get<Map<String, dynamic>>(
      '/reports/dashboard',
      queryParameters: {'date': ?date},
    );
    return DashboardFigures.fromJson(
      response.data!['data'] as Map<String, dynamic>,
    );
  }

  Future<AgingReport> receivablesAging({String? asOf}) async {
    final response = await _api.get<Map<String, dynamic>>(
      '/reports/receivables-aging',
      queryParameters: {'as_of': ?asOf},
    );
    return AgingReport.fromJson(response.data!['data'] as Map<String, dynamic>);
  }

  Future<List<LowStockRow>> lowStock() async {
    final response = await _api.get<Map<String, dynamic>>('/reports/low-stock');
    final data = response.data!['data'] as Map<String, dynamic>;
    return (data['rows'] as List<dynamic>)
        .map((e) => LowStockRow.fromJson(e as Map<String, dynamic>))
        .toList();
  }
}
