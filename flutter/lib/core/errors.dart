/// API error mapped from the Laravel error envelope:
/// `{ message, code, errors: {field: [..]}, details?: {...} }` (docs/api.md, Errors).
class ApiException implements Exception {
  ApiException({
    required this.message,
    this.statusCode,
    this.code,
    this.fieldErrors = const {},
    this.details = const {},
    this.isNetworkError = false,
  });

  /// No response reached us (offline, timeout, connection reset). The request may or
  /// may not have been processed, so a retry must reuse the same idempotency key.
  factory ApiException.network([String? message]) {
    return ApiException(
      message: message ??
          'No connection to the server. Nothing is lost: retrying is safe.',
      code: 'network_error',
      isNetworkError: true,
    );
  }

  factory ApiException.fromDio(dynamic responseData, {int? statusCode}) {
    if (responseData is Map<String, dynamic>) {
      final message = responseData['message']?.toString() ?? 'Request failed';
      final code = responseData['code']?.toString();
      final rawErrors = responseData['errors'];
      final fieldErrors = <String, List<String>>{};
      if (rawErrors is Map) {
        for (final entry in rawErrors.entries) {
          final key = entry.key.toString();
          final value = entry.value;
          if (value is List) {
            fieldErrors[key] = value.map((e) => e.toString()).toList();
          } else if (value != null) {
            fieldErrors[key] = [value.toString()];
          }
        }
      }
      final rawDetails = responseData['details'];
      return ApiException(
        message: message,
        statusCode: statusCode,
        code: code,
        fieldErrors: fieldErrors,
        details: rawDetails is Map
            ? rawDetails.map((k, v) => MapEntry(k.toString(), v))
            : const {},
      );
    }
    return ApiException(
      message: 'Request failed',
      statusCode: statusCode,
    );
  }

  final String message;
  final int? statusCode;
  final String? code;
  final Map<String, List<String>> fieldErrors;

  /// Machine-readable extras for business errors (e.g. `existing_receipt_no`).
  final Map<String, dynamic> details;
  final bool isNetworkError;

  /// True when the server may or may not have done the work: no response at all, a
  /// 5xx, or the first attempt still running (`request_in_progress`). Only then is the
  /// idempotency key kept for a retry. Every other error is definitive: nothing was
  /// written and the key can be forgotten.
  bool get isOutcomeUnknown =>
      isNetworkError ||
      statusCode == null ||
      statusCode! >= 500 ||
      code == 'request_in_progress';

  /// First message for [field], e.g. `reference` or `allocations.0.amount`.
  String? fieldError(String field) => fieldErrors[field]?.first;

  @override
  String toString() => message;
}
