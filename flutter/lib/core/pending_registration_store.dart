import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Persists a pending-registration email so the waiting-room survives
/// app restarts between registration and admin approval.
class PendingRegistrationStore {
  PendingRegistrationStore({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  static const _key = 'pending_registration_email';

  final FlutterSecureStorage _storage;

  Future<String?> readEmail() => _storage.read(key: _key);

  Future<void> writeEmail(String email) =>
      _storage.write(key: _key, value: email);

  Future<void> clear() => _storage.delete(key: _key);
}
