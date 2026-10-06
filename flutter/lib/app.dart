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
import 'package:schoolbook/features/customers/data/school_directory_repository.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/presentation/products_list_provider.dart';
import 'package:schoolbook/features/reference/data/reference_cache_store.dart';
import 'package:schoolbook/features/reference/data/reference_repository.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';
import 'package:schoolbook/features/sales/data/sales_repository.dart';
import 'package:schoolbook/features/reports/data/reports_repository.dart';
import 'package:schoolbook/features/stock/data/stock_counts_repository.dart';
import 'package:schoolbook/features/stock/data/stock_repository.dart';

class SchoolbookApp extends StatefulWidget {
  const SchoolbookApp({super.key, this.pdfSharer});

  /// Replaces the system share sheet (acceptance runs record what would be shared).
  final PdfSharer? pdfSharer;

  @override
  State<SchoolbookApp> createState() => _SchoolbookAppState();
}

class _SchoolbookAppState extends State<SchoolbookApp> {
  late final AuthTokenStore _tokenStore;
  late final ApiClient _apiClient;
  late final AuthProvider _authProvider;
  late final GoRouter _router;
  late final ReferenceCatalog _referenceCatalog;
  bool _wasAuthenticated = false;

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
    _referenceCatalog = ReferenceCatalog(
      repository: ReferenceRepository(apiClient: _apiClient),
      cache: FileReferenceCacheStore(),
    );
    // Approved list: refreshed whenever a session starts (login or app start with a
    // stored token); 304 when unchanged, so this is cheap.
    _authProvider.addListener(_syncReferenceOnLogin);
    _authProvider.bootstrap();
  }

  void _syncReferenceOnLogin() {
    final authed = _authProvider.isAuthenticated;
    if (authed && !_wasAuthenticated) {
      _referenceCatalog.sync();
    }
    _wasAuthenticated = authed;
  }

  @override
  void dispose() {
    _router.dispose();
    _authProvider.removeListener(_syncReferenceOnLogin);
    _authProvider.dispose();
    _referenceCatalog.dispose();
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
        Provider<SchoolDirectoryRepository>(
          create: (context) =>
              SchoolDirectoryRepository(apiClient: context.read<ApiClient>()),
        ),
        Provider<StockCountsRepository>(
          create: (context) =>
              StockCountsRepository(apiClient: context.read<ApiClient>()),
        ),
        Provider<ReportsRepository>(
          create: (context) =>
              ReportsRepository(apiClient: context.read<ApiClient>()),
        ),
        Provider<CustomersRepository>(
          create: (context) =>
              CustomersRepository(apiClient: context.read<ApiClient>()),
        ),
        Provider<PaymentsRepository>(
          create: (context) =>
              PaymentsRepository(apiClient: context.read<ApiClient>()),
        ),
        Provider<SalesRepository>(
          create: (context) =>
              SalesRepository(apiClient: context.read<ApiClient>()),
        ),
        ChangeNotifierProvider<ReferenceCatalog>.value(
          value: _referenceCatalog,
        ),
        Provider<PendingSubmissionStore>(
          create: (_) => PendingSubmissionStore(),
        ),
        Provider<PdfSharer>(
          create: (_) => widget.pdfSharer ?? const SystemPdfSharer(),
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
