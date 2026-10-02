import 'dart:io';

import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/features/auth/domain/auth_user.dart';

class AuthRepository {
  AuthRepository({
    required ApiClient apiClient,
    required this._tokenStore,
  }) : _api = apiClient;

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
    await _tokenStore.writeToken(token);
    return AuthUser.fromJson(body['user'] as Map<String, dynamic>);
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
    return AuthUser.fromJson(data);
  }

  Future<bool> hasStoredToken() async {
    final token = await _tokenStore.readToken();
    return token != null && token.isNotEmpty;
  }

  Future<void> clearLocalSession() => _tokenStore.clearToken();
}
