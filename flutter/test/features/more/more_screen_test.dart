import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/core/pending_registration_store.dart';
import 'package:schoolbook/core/server_url_store.dart';
import 'package:schoolbook/features/auth/data/auth_repository.dart';
import 'package:schoolbook/features/auth/domain/auth_user.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';
import 'package:schoolbook/features/more/presentation/more_screen.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
  });

  Future<void> pumpMore(WidgetTester tester) async {
    final tokens = AuthTokenStore();
    final api = ApiClient(tokenStore: tokens);
    final auth = AuthProvider(
      repository: AuthRepository(
        apiClient: api,
        tokenStore: tokens,
      ),
      pendingStore: PendingRegistrationStore(),
    );
    auth.user = const AuthUser(
      id: 1,
      name: 'Ama Owner',
      email: 'ama@schoolbook.test',
      role: 'owner',
      isActive: true,
    );
    auth.status = AuthStatus.authenticated;

    final router = GoRouter(
      initialLocation: '/more',
      routes: [
        GoRoute(path: '/more', builder: (_, _) => const MoreScreen()),
        GoRoute(path: '/products', builder: (_, _) => const SizedBox()),
        GoRoute(path: '/stock', builder: (_, _) => const SizedBox()),
        GoRoute(path: '/payments', builder: (_, _) => const SizedBox()),
        GoRoute(path: '/reports', builder: (_, _) => const SizedBox()),
        GoRoute(
          path: '/customers/directory',
          builder: (_, _) => const SizedBox(),
        ),
        GoRoute(
          path: '/products/approved',
          builder: (_, _) => const SizedBox(),
        ),
      ],
    );

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider<AuthProvider>.value(value: auth),
          Provider<ApiClient>.value(value: api),
          Provider<ServerUrlStore>.value(value: ServerUrlStore()),
        ],
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('more screen shows profile and destinations, hides server UI', (
    tester,
  ) async {
    await pumpMore(tester);

    expect(find.text('More'), findsOneWidget);
    expect(find.text('Ama Owner'), findsOneWidget);
    expect(find.text('ama@schoolbook.test'), findsOneWidget);
    expect(find.text('AO'), findsOneWidget);
    expect(find.byKey(const Key('more_products')), findsOneWidget);
    expect(find.byKey(const Key('more_stock')), findsOneWidget);

    await tester.drag(find.byType(ListView), const Offset(0, -800));
    await tester.pumpAndSettle();

    expect(find.text('Sign out'), findsOneWidget);
    expect(find.byKey(const Key('more_sign_out')), findsOneWidget);
    // Discrete: no server chrome on the page until long-press.
    expect(find.text('SERVER'), findsNothing);
    expect(find.byKey(const Key('more_server_url')), findsNothing);
  });

  testWidgets('long-press More title opens hidden host drawer', (tester) async {
    await pumpMore(tester);

    await tester.longPress(find.byKey(const Key('more_title')));
    await tester.pumpAndSettle();

    // Debug skips PIN and opens the host drawer directly.
    expect(find.byKey(const Key('more_server_url')), findsOneWidget);
    expect(find.byKey(const Key('more_server_save')), findsOneWidget);
  });

  testWidgets('seven taps on More title opens hidden host drawer', (
    tester,
  ) async {
    await pumpMore(tester);

    final title = find.byKey(const Key('more_title'));
    for (var i = 0; i < 7; i++) {
      await tester.tap(title);
      await tester.pump(const Duration(milliseconds: 50));
    }
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('more_server_url')), findsOneWidget);
  });
}
