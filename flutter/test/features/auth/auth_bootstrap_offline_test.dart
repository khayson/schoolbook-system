import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/onboarding_store.dart';
import 'package:schoolbook/core/pending_registration_store.dart';
import 'package:schoolbook/features/auth/data/auth_repository.dart';
import 'package:schoolbook/features/auth/domain/auth_user.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';

class _FakeAuthRepository extends AuthRepository {
  _FakeAuthRepository(this.tokens)
    : super(apiClient: ApiClient(tokenStore: tokens), tokenStore: tokens);

  final AuthTokenStore tokens;
  ApiException? fetchMeError;
  AuthUser? meUser;

  @override
  Future<AuthUser?> fetchMe() async {
    if (fetchMeError != null) throw fetchMeError!;
    final user = meUser!;
    await tokens.writeCachedUser(user);
    return user;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late AuthTokenStore tokens;
  late _FakeAuthRepository repo;
  late AuthProvider auth;

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
    tokens = AuthTokenStore();
    repo = _FakeAuthRepository(tokens);
    auth = AuthProvider(
      repository: repo,
      pendingStore: PendingRegistrationStore(),
      onboardingStore: OnboardingStore(),
    );
  });

  test('bootstrap keeps session when /auth/me fails with network error', () async {
    const cached = AuthUser(
      id: 1,
      name: 'Owner',
      email: 'owner@example.com',
      role: 'owner',
      isActive: true,
    );
    await tokens.writeToken('kept-token');
    await tokens.writeCachedUser(cached);
    repo.fetchMeError = ApiException.network();

    await auth.bootstrap();

    expect(auth.status, AuthStatus.authenticated);
    expect(auth.user?.email, 'owner@example.com');
    expect(await tokens.readToken(), 'kept-token');
  });

  test('bootstrap clears session on definitive 401', () async {
    await tokens.writeToken('bad-token');
    await tokens.writeCachedUser(
      const AuthUser(
        id: 1,
        name: 'Owner',
        email: 'owner@example.com',
        role: 'owner',
        isActive: true,
      ),
    );
    repo.fetchMeError = ApiException(
      message: 'Unauthenticated.',
      statusCode: 401,
      code: 'unauthenticated',
    );

    await auth.bootstrap();

    expect(auth.status, AuthStatus.unauthenticated);
    expect(auth.user, isNull);
    expect(await tokens.readToken(), isNull);
  });

  test('bootstrap succeeds online and caches user', () async {
    await tokens.writeToken('ok-token');
    repo.meUser = const AuthUser(
      id: 2,
      name: 'Cashier',
      email: 'c@example.com',
      role: 'owner',
      isActive: true,
    );

    await auth.bootstrap();

    expect(auth.status, AuthStatus.authenticated);
    expect(auth.user?.email, 'c@example.com');
    expect((await tokens.readCachedUser())?.email, 'c@example.com');
  });
}
