import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/pdf_sharer.dart';
import 'package:schoolbook/core/onboarding_store.dart';
import 'package:schoolbook/core/pending_registration_store.dart';
import 'package:schoolbook/core/router.dart';
import 'package:schoolbook/core/server_url_store.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/auth/data/auth_repository.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';
import 'package:schoolbook/features/catalog/data/lookups_repository.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/data/school_directory_repository.dart';
import 'package:schoolbook/features/offline/data/connectivity_service.dart';
import 'package:schoolbook/features/offline/data/offline_store.dart';
import 'package:schoolbook/features/offline/data/sync_worker.dart';
import 'package:schoolbook/features/offline/presentation/offline_controller.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/presentation/products_list_provider.dart';
import 'package:schoolbook/features/reference/data/reference_cache_store.dart';
import 'package:schoolbook/features/reference/data/reference_repository.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';
import 'package:schoolbook/features/exchanges/data/exchanges_repository.dart';
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
  late final ServerUrlStore _serverUrlStore;
  late final ApiClient _apiClient;
  late final AuthProvider _authProvider;
  late final GoRouter _router;
  late final ReferenceCatalog _referenceCatalog;
  late final OfflineController _offlineController;
  bool _wasAuthenticated = false;

  @override
  void initState() {
    super.initState();
    _tokenStore = AuthTokenStore();
    _serverUrlStore = ServerUrlStore();
    _apiClient = ApiClient(tokenStore: _tokenStore);
    _authProvider = AuthProvider(
      repository: AuthRepository(
        apiClient: _apiClient,
        tokenStore: _tokenStore,
      ),
      pendingStore: PendingRegistrationStore(),
      onboardingStore: OnboardingStore(),
    );
    _apiClient.onUnauthorized = _authProvider.handleUnauthorized;
    _router = createAppRouter(_authProvider);
    _referenceCatalog = ReferenceCatalog(
      repository: ReferenceRepository(apiClient: _apiClient),
      cache: FileReferenceCacheStore(),
    );
    final salesRepo = SalesRepository(apiClient: _apiClient);
    final paymentsRepo = PaymentsRepository(apiClient: _apiClient);
    final customersRepo = CustomersRepository(apiClient: _apiClient);
    final productsRepo = ProductsRepository(apiClient: _apiClient);
    final offlineStore = FileOfflineStore();
    _offlineController = OfflineController(
      store: offlineStore,
      syncWorker: SyncWorker(
        store: offlineStore,
        sales: salesRepo,
        payments: paymentsRepo,
      ),
      connectivity: PlusConnectivityService(),
      customers: customersRepo,
      products: productsRepo,
    );
    // Approved list: refreshed whenever a session starts (login or app start with a
    // stored token); 304 when unchanged, so this is cheap.
    _authProvider.addListener(_syncReferenceOnLogin);
    _applyStoredServerUrl();
    _authProvider.bootstrap();
    _offlineController.start();
  }

  Future<void> _applyStoredServerUrl() async {
    final override = await _serverUrlStore.read();
    if (override != null && mounted) {
      _apiClient.setBaseUrl(override);
    }
  }

  void _syncReferenceOnLogin() {
    final authed = _authProvider.isAuthenticated;
    if (authed && !_wasAuthenticated) {
      // Titles only — stock counts load when Approved list opens.
      _referenceCatalog.sync();
      _offlineController.refreshSnapshot();
      _offlineController.kickSync();
    }
    _wasAuthenticated = authed;
  }

  @override
  void dispose() {
    _router.dispose();
    _authProvider.removeListener(_syncReferenceOnLogin);
    _authProvider.dispose();
    _referenceCatalog.dispose();
    _offlineController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider<AuthProvider>.value(value: _authProvider),
        ChangeNotifierProvider<OfflineController>.value(
          value: _offlineController,
        ),
        Provider<ApiClient>.value(value: _apiClient),
        Provider<AuthTokenStore>.value(value: _tokenStore),
        Provider<ServerUrlStore>.value(value: _serverUrlStore),
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
        Provider<ExchangesRepository>(
          create: (context) =>
              ExchangesRepository(apiClient: context.read<ApiClient>()),
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
