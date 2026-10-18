import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:schoolbook/features/auth/domain/auth_user.dart';

/// Persists the Sanctum bearer token (and last known user for offline bootstrap).
class AuthTokenStore {
  AuthTokenStore({FlutterSecureStorage? storage})
    : _storage = storage ?? const FlutterSecureStorage();

  static const _tokenKey = 'auth_token';
  static const _userKey = 'auth_user_cache';

  final FlutterSecureStorage _storage;

  Future<String?> readToken() => _storage.read(key: _tokenKey);

  Future<void> writeToken(String token) =>
      _storage.write(key: _tokenKey, value: token);

  Future<void> clearToken() async {
    await _storage.delete(key: _tokenKey);
    await _storage.delete(key: _userKey);
  }

  Future<void> writeCachedUser(AuthUser user) =>
      _storage.write(key: _userKey, value: jsonEncode(user.toJson()));

  Future<AuthUser?> readCachedUser() async {
    final raw = await _storage.read(key: _userKey);
    if (raw == null || raw.isEmpty) return null;
    try {
      final map = jsonDecode(raw);
      if (map is Map<String, dynamic>) return AuthUser.fromJson(map);
      if (map is Map) {
        return AuthUser.fromJson(Map<String, dynamic>.from(map));
      }
    } catch (_) {
      return null;
    }
    return null;
  }
}
