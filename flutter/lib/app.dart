import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/pdf_sharer.dart';
import 'package:schoolbook/core/router.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/auth/data/auth_repository.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';
import 'package:schoolbook/features/catalog/data/lookups_repository.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/presentation/products_list_provider.dart';
import 'package:schoolbook/features/stock/data/stock_repository.dart';

class SchoolbookApp extends StatefulWidget {
  const SchoolbookApp({super.key});

  @override
  State<SchoolbookApp> createState() => _SchoolbookAppState();
}

class _SchoolbookAppState extends State<SchoolbookApp> {
  late final AuthTokenStore _tokenStore;
  late final ApiClient _apiClient;
  late final AuthProvider _authProvider;
  late final GoRouter _router;

  @override
  void initState() {
    super.initState();
    _tokenStore = AuthTokenStore();
    _apiClient = ApiClient(tokenStore: _tokenStore);
    _authProvider = AuthProvider(
      repository: AuthRepository(
        apiClient: _apiClient,
        tokenStore: _tokenStore,
      ),
    );
    _apiClient.onUnauthorized = _authProvider.handleUnauthorized;
    _router = createAppRouter(_authProvider);
    _authProvider.bootstrap();
  }

  @override
  void dispose() {
    _router.dispose();
    _authProvider.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider<AuthProvider>.value(value: _authProvider),
        Provider<ApiClient>.value(value: _apiClient),
        Provider<AuthTokenStore>.value(value: _tokenStore),
        Provider<AuthRepository>(
          create: (context) => AuthRepository(
            apiClient: context.read<ApiClient>(),
            tokenStore: context.read<AuthTokenStore>(),
          ),
        ),
        Provider<ProductsRepository>(
          create: (context) =>
              ProductsRepository(apiClient: context.read<ApiClient>()),
        ),
        Provider<LookupsRepository>(
          create: (context) =>
              LookupsRepository(apiClient: context.read<ApiClient>()),
        ),
        Provider<StockRepository>(
          create: (context) =>
              StockRepository(apiClient: context.read<ApiClient>()),
        ),
        Provider<CustomersRepository>(
          create: (context) =>
              CustomersRepository(apiClient: context.read<ApiClient>()),
        ),
        Provider<PaymentsRepository>(
          create: (context) =>
              PaymentsRepository(apiClient: context.read<ApiClient>()),
        ),
        Provider<PendingSubmissionStore>(
          create: (_) => PendingSubmissionStore(),
        ),
        Provider<PdfSharer>(
          create: (_) => const SystemPdfSharer(),
        ),
        ChangeNotifierProvider<ProductsListProvider>(
          create: (context) => ProductsListProvider(
            productsRepository: context.read<ProductsRepository>(),
            lookupsRepository: context.read<LookupsRepository>(),
          ),
        ),
      ],
      child: MaterialApp.router(
        title: 'Schoolbook Supply',
        debugShowCheckedModeBanner: false,
        theme: buildLightTheme(),
        darkTheme: buildDarkTheme(),
        themeMode: ThemeMode.system,
        routerConfig: _router,
      ),
    );
  }
}
