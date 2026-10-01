import 'package:dio/dio.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/core/config/api_config.dart';
import 'package:schoolbook/core/errors.dart';

typedef UnauthorizedHandler = void Function();

/// Shared Dio client with auth header injection and error mapping.
class ApiClient {
  ApiClient({
    required AuthTokenStore tokenStore,
    String baseUrl = ApiConfig.defaultBaseUrl,
    UnauthorizedHandler? onUnauthorized,
  })  : _tokenStore = tokenStore,
        _onUnauthorized = onUnauthorized {
    _dio = Dio(
      BaseOptions(
        baseUrl: baseUrl,
        connectTimeout: const Duration(seconds: 15),
        receiveTimeout: const Duration(seconds: 30),
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      ),
    );

    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await _tokenStore.readToken();
          if (token != null && token.isNotEmpty) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          handler.next(options);
        },
        onError: (error, handler) async {
          final status = error.response?.statusCode;
          if (status == 401) {
            await _tokenStore.clearToken();
            _onUnauthorized?.call();
          }
          handler.next(error);
        },
      ),
    );
  }

  final AuthTokenStore _tokenStore;
  UnauthorizedHandler? _onUnauthorized;

  late final Dio _dio;

  Dio get dio => _dio;

  set onUnauthorized(UnauthorizedHandler? handler) {
    _onUnauthorized = handler;
  }

  Future<Response<T>> get<T>(
    String path, {
    Map<String, dynamic>? queryParameters,
  }) async {
    return _request(() => _dio.get<T>(path, queryParameters: queryParameters));
  }

  Future<Response<T>> post<T>(
    String path, {
    Object? data,
    Map<String, dynamic>? queryParameters,
    Options? options,
  }) async {
    return _request(
      () => _dio.post<T>(
        path,
        data: data,
        queryParameters: queryParameters,
        options: options,
      ),
    );
  }

  Future<Response<T>> put<T>(
    String path, {
    Object? data,
  }) async {
    return _request(() => _dio.put<T>(path, data: data));
  }

  Future<Response<T>> delete<T>(String path) async {
    return _request(() => _dio.delete<T>(path));
  }

  Future<Response<T>> _request<T>(
    Future<Response<T>> Function() call,
  ) async {
    try {
      return await call();
    } on DioException catch (e) {
      throw ApiException.fromDio(
        e.response?.data,
        statusCode: e.response?.statusCode,
      );
    }
  }
}
