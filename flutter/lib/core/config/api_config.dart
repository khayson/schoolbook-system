/// REST API configuration for the Schoolbook mobile app.
abstract final class ApiConfig {
  /// Default base URL for the Android emulator (`10.0.2.2` → host machine localhost).
  /// Override at build time, e.g. for a real phone or the acceptance run:
  /// `flutter run --dart-define=API_BASE_URL=http://192.168.1.20:8000/api/v1`
  static const String defaultBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );
}
