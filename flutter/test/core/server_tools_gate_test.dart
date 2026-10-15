import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/config/api_config.dart';

void main() {
  test('server tools always available; PIN skipped in debug', () {
    expect(kDebugMode, isTrue);
    expect(ApiConfig.serverToolsAvailable, isTrue);
    expect(ApiConfig.serverToolsPinRequired, isFalse);
  });
}
