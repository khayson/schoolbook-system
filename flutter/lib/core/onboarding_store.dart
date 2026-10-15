import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Remembers whether the user finished first-launch onboarding.
class OnboardingStore {
  OnboardingStore({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  static const _key = 'onboarding_completed';

  final FlutterSecureStorage _storage;

  Future<bool> hasCompleted() async {
    final value = await _storage.read(key: _key);
    return value == '1';
  }

  Future<void> markCompleted() => _storage.write(key: _key, value: '1');

  Future<void> clear() => _storage.delete(key: _key);
}
