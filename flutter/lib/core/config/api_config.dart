import 'package:flutter/foundation.dart';

/// REST API configuration for the Schoolbook mobile app.
///
/// Filament and Flutter must hit the **same Laravel host** (same MySQL).
/// - Filament: `APP_URL/admin`
/// - Flutter:  `APP_URL/api/v1`  ← this value
///
/// ## One APK, many clients
///
/// Ship **one** release APK. On each phone: long-press the **More** title →
/// enter setup PIN → paste that client's Laravel host. Nothing shows on More
/// until that gesture. Override lives in secure storage on that device only.
///
/// ```
/// flutter build apk --release ^
///   --dart-define=API_BASE_URL=https://fallback.example.com/api/v1 ^
///   --dart-define=DEV_SERVER_PIN=your-long-secret
/// ```
///
/// `API_BASE_URL` is only the fallback before a device is configured.
/// Debug runs skip the PIN after the long-press.
abstract final class ApiConfig {
  /// Emulator default: `10.0.2.2` is the host machine's localhost.
  static const String defaultBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  static const String _setupPin = String.fromEnvironment(
    'DEV_SERVER_PIN',
    defaultValue: '',
  );

  /// Always on — every build can point at a different client server.
  static bool get serverToolsAvailable => true;

  /// Release builds require the compile-time setup PIN. Debug skips it.
  static bool get serverToolsPinRequired => !kDebugMode;

  /// True when this release binary was built with a non-empty PIN.
  static bool get serverToolsPinConfigured => _setupPin.isNotEmpty;

  static String get serverToolsPin => _setupPin;

  /// Turn free-form input into a usable `…/api/v1` base URL, or null if invalid.
  static String? normalizeBaseUrl(String raw) {
    var input = raw.trim();
    if (input.isEmpty || RegExp(r'\s').hasMatch(input)) return null;

    if (!input.contains('://')) {
      input = 'https://$input';
    }

    final uri = Uri.tryParse(input);
    if (uri == null ||
        (uri.scheme != 'http' && uri.scheme != 'https') ||
        !_isPlausibleHost(uri.host)) {
      return null;
    }

    var path = uri.path;
    while (path.endsWith('/') && path.length > 1) {
      path = path.substring(0, path.length - 1);
    }
    if (path.isEmpty || path == '/') {
      path = '/api/v1';
    } else if (!path.endsWith('/api/v1')) {
      path = '$path/api/v1';
    }

    return Uri(
      scheme: uri.scheme,
      host: uri.host,
      port: uri.hasPort ? uri.port : null,
      path: path,
    ).toString();
  }

  static bool _isPlausibleHost(String host) {
    if (host.isEmpty || host.contains(' ')) return false;
    if (host == 'localhost') return true;
    // IPv4
    if (RegExp(r'^\d{1,3}(\.\d{1,3}){3}$').hasMatch(host)) return true;
    // Hostname needs at least one dot (e.g. app.example.com, 10.0.2.2 already matched).
    return host.contains('.') &&
        !host.startsWith('.') &&
        !host.endsWith('.') &&
        RegExp(r'^[a-zA-Z0-9.-]+$').hasMatch(host);
  }
}
