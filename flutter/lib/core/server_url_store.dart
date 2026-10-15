import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:schoolbook/core/config/api_config.dart';

/// Device-local API base URL override (developer builds only).
class ServerUrlStore {
  ServerUrlStore({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  static const _key = 'api_base_url_override';

  final FlutterSecureStorage _storage;

  Future<String?> read() async {
    final raw = await _storage.read(key: _key);
    if (raw == null || raw.trim().isEmpty) return null;
    return ApiConfig.normalizeBaseUrl(raw);
  }

  Future<void> write(String url) async {
    final normalized = ApiConfig.normalizeBaseUrl(url);
    if (normalized == null) {
      throw ArgumentError('Invalid API base URL');
    }
    await _storage.write(key: _key, value: normalized);
  }

  Future<void> clear() => _storage.delete(key: _key);
}
