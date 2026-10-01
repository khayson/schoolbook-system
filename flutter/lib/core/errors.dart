/// API error mapped from Laravel JSON error responses.
class ApiException implements Exception {
  ApiException({
    required this.message,
    this.statusCode,
    this.code,
    this.fieldErrors = const {},
  });

  final String message;
  final int? statusCode;
  final String? code;
  final Map<String, List<String>> fieldErrors;

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
      return ApiException(
        message: message,
        statusCode: statusCode,
        code: code,
        fieldErrors: fieldErrors,
      );
    }
    return ApiException(
      message: 'Request failed',
      statusCode: statusCode,
    );
  }

  @override
  String toString() => message;
}
