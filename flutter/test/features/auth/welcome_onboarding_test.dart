import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/core/onboarding_store.dart';
import 'package:schoolbook/core/pending_registration_store.dart';
import 'package:schoolbook/features/auth/data/auth_repository.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';
import 'package:schoolbook/features/auth/presentation/welcome_screen.dart';

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
      onboardingStore: OnboardingStore(),
    );
  }

  testWidgets('welcome screen shows product jobs and sign-in CTA',
      (tester) async {
    final auth = buildAuth();
    final router = GoRouter(
      initialLocation: '/welcome',
      routes: [
        GoRoute(
          path: '/welcome',
          builder: (context, state) => const WelcomeScreen(),
        ),
        GoRoute(
          path: '/login',
          builder: (context, state) => const Scaffold(body: Text('Login')),
        ),
        GoRoute(
          path: '/register',
          builder: (context, state) => const Scaffold(body: Text('Register')),
        ),
      ],
    );

    await tester.pumpWidget(
      ChangeNotifierProvider<AuthProvider>.value(
        value: auth,
        child: MaterialApp.router(routerConfig: router),
      ),
    );

    expect(find.text('Schoolbook Supply'), findsOneWidget);
    expect(
      find.text('Record bulk sales and share invoices'),
      findsOneWidget,
    );
    expect(find.byKey(const Key('onboarding_next')), findsOneWidget);
    expect(find.byKey(const Key('onboarding_skip')), findsOneWidget);
  });

  test('completeOnboarding flips hasSeenOnboarding', () async {
    final auth = buildAuth();
    expect(auth.hasSeenOnboarding, isFalse);
    await auth.completeOnboarding();
    expect(auth.hasSeenOnboarding, isTrue);
  });
}
