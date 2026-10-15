import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/config/api_config.dart';

void main() {
  test('normalizeBaseUrl accepts host and fills /api/v1', () {
    expect(
      ApiConfig.normalizeBaseUrl('app.example.com'),
      'https://app.example.com/api/v1',
    );
    expect(
      ApiConfig.normalizeBaseUrl('https://app.example.com/'),
      'https://app.example.com/api/v1',
    );
    expect(
      ApiConfig.normalizeBaseUrl('http://192.168.0.10:8000'),
      'http://192.168.0.10:8000/api/v1',
    );
    expect(
      ApiConfig.normalizeBaseUrl('https://app.example.com/api/v1'),
      'https://app.example.com/api/v1',
    );
  });

  test('normalizeBaseUrl rejects junk', () {
    expect(ApiConfig.normalizeBaseUrl(''), isNull);
    expect(ApiConfig.normalizeBaseUrl('not a url'), isNull);
    expect(ApiConfig.normalizeBaseUrl('ftp://x'), isNull);
  });
}
