import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/core/pending_registration_store.dart';
import 'package:schoolbook/features/auth/data/auth_repository.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
  });

  AuthProvider buildAuth() {
    final tokens = AuthTokenStore();
    return AuthProvider(
      repository: AuthRepository(
        apiClient: ApiClient(tokenStore: tokens),
        tokenStore: tokens,
      ),
      pendingStore: PendingRegistrationStore(),
    );
  }

  test('clearPendingRegistration unlocks login (leaves pendingApproval)',
      () async {
    final auth = buildAuth();
    await auth.setPendingRegistration('pending@school.gh');

    expect(auth.status, AuthStatus.pendingApproval);
    expect(auth.pendingEmail, 'pending@school.gh');

    await auth.clearPendingRegistration();

    expect(auth.status, AuthStatus.unauthenticated);
    expect(auth.pendingEmail, isNull);
  });
}
