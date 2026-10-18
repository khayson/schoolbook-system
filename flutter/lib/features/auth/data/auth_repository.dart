import 'dart:io';

import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/features/auth/domain/auth_user.dart';

class AuthRepository {
  AuthRepository({required ApiClient apiClient, required this._tokenStore})
    : _api = apiClient;

  final ApiClient _api;
  final AuthTokenStore _tokenStore;

  Future<AuthUser> login({
    required String email,
    required String password,
  }) async {
    final deviceName = Platform.isAndroid
        ? 'android'
        : Platform.isIOS
        ? 'ios'
        : 'mobile';

    final response = await _api.post<Map<String, dynamic>>(
      '/auth/login',
      data: {
        'email': email.trim(),
        'password': password,
        'device_name': deviceName,
      },
    );

    final body = response.data!;
    final token = body['token'] as String;
    final user = AuthUser.fromJson(body['user'] as Map<String, dynamic>);
    await _tokenStore.writeToken(token);
    await _tokenStore.writeCachedUser(user);
    return user;
  }

  Future<void> logout() async {
    try {
      await _api.post<void>('/auth/logout');
    } finally {
      await _tokenStore.clearToken();
    }
  }

  Future<AuthUser?> fetchMe() async {
    final token = await _tokenStore.readToken();
    if (token == null || token.isEmpty) {
      return null;
    }
    final response = await _api.get<Map<String, dynamic>>('/auth/me');
    final data = response.data!['data'] as Map<String, dynamic>;
    final user = AuthUser.fromJson(data);
    await _tokenStore.writeCachedUser(user);
    return user;
  }

  Future<bool> hasStoredToken() async {
    final token = await _tokenStore.readToken();
    return token != null && token.isNotEmpty;
  }

  Future<AuthUser?> readCachedUser() => _tokenStore.readCachedUser();

  Future<void> clearLocalSession() => _tokenStore.clearToken();

  /// Register a new user (pending admin approval).
  Future<void> register({
    required String name,
    required String email,
    required String password,
  }) async {
    await _api.post<void>(
      '/auth/register',
      data: {
        'name': name.trim(),
        'email': email.trim(),
        'password': password,
        'password_confirmation': password,
      },
    );
  }

  /// Check account approval status for [email] via `GET /auth/status`.
  /// Returns `data`: `status` (pending | active), `message`, and optionally
  /// `name` / `registered_at`.
  Future<Map<String, dynamic>> checkAccountStatus(String email) async {
    final response = await _api.get<Map<String, dynamic>>(
      '/auth/status',
      queryParameters: {'email': email.trim()},
    );
    final body = response.data ?? {};
    final data = body['data'];
    if (data is Map<String, dynamic>) return data;
    return body;
  }
}
