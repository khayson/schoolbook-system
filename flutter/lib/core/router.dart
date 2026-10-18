import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';
import 'package:schoolbook/features/auth/presentation/forgot_password_screen.dart';
import 'package:schoolbook/features/auth/presentation/login_screen.dart';
import 'package:schoolbook/features/auth/presentation/register_screen.dart';
import 'package:schoolbook/features/auth/presentation/splash_screen.dart';
import 'package:schoolbook/features/auth/presentation/waiting_room_screen.dart';
import 'package:schoolbook/features/auth/presentation/welcome_screen.dart';
import 'package:schoolbook/features/customers/presentation/customer_detail_screen.dart';
import 'package:schoolbook/features/customers/presentation/customer_form_screen.dart';
import 'package:schoolbook/features/customers/presentation/customers_list_screen.dart';
import 'package:schoolbook/features/customers/presentation/school_directory_screen.dart';
import 'package:schoolbook/features/dashboard/presentation/dashboard_screen.dart';
import 'package:schoolbook/features/more/presentation/more_screen.dart';
import 'package:schoolbook/features/payments/presentation/payment_detail_screen.dart';
import 'package:schoolbook/features/payments/presentation/payments_list_screen.dart';
import 'package:schoolbook/features/payments/presentation/record_payment_screen.dart';
import 'package:schoolbook/features/products/presentation/product_detail_screen.dart';
import 'package:schoolbook/features/products/presentation/product_form_screen.dart';
import 'package:schoolbook/features/products/presentation/product_scan_screen.dart';
import 'package:schoolbook/features/products/presentation/products_list_screen.dart';
import 'package:schoolbook/features/reference/presentation/quick_create_product_screen.dart';
import 'package:schoolbook/features/reference/presentation/reference_search_screen.dart';
import 'package:schoolbook/features/reports/presentation/light_reports_screens.dart';
import 'package:schoolbook/features/reports/presentation/reports_hub_screen.dart';
import 'package:schoolbook/features/sales/presentation/new_sale_screen.dart';
import 'package:schoolbook/features/sales/presentation/sale_detail_screen.dart';
import 'package:schoolbook/features/sales/presentation/sales_list_screen.dart';
import 'package:schoolbook/features/exchanges/presentation/exchange_detail_screen.dart';
import 'package:schoolbook/features/exchanges/presentation/exchange_form_screen.dart';
import 'package:schoolbook/features/exchanges/presentation/exchanges_list_screen.dart';
import 'package:schoolbook/features/stock/presentation/adjust_stock_screen.dart';
import 'package:schoolbook/features/stock/presentation/goods_receipts_screen.dart';
import 'package:schoolbook/features/stock/presentation/receive_stock_screen.dart';
import 'package:schoolbook/features/stock/presentation/stock_count_screen.dart';
import 'package:schoolbook/features/stock/presentation/stock_counts_screen.dart';
import 'package:schoolbook/features/stock/presentation/stock_hub_screen.dart';
import 'package:schoolbook/shared/widgets/main_shell.dart';

GoRouter createAppRouter(AuthProvider authProvider) {
  return GoRouter(
    initialLocation: '/',
    refreshListenable: authProvider,
    redirect: (context, state) {
      final status = authProvider.status;
      final loc = state.matchedLocation;

      if (status == AuthStatus.unknown) {
        return loc == '/splash' ? null : '/splash';
      }

      if (status == AuthStatus.pendingApproval) {
        if (loc.startsWith('/waiting-room')) return null;
        final email = authProvider.pendingEmail ?? '';
        return '/waiting-room?email=${Uri.encodeComponent(email)}';
      }

      final authed = authProvider.isAuthenticated;

      if (loc == '/splash') {
        if (authed) return '/';
        if (!authProvider.hasSeenOnboarding) return '/welcome';
        return '/login';
      }

      final isPublicRoute = loc == '/login' ||
          loc == '/register' ||
          loc == '/forgot-password' ||
          loc == '/welcome' ||
          loc.startsWith('/waiting-room');

      if (!authed &&
          !authProvider.hasSeenOnboarding &&
          loc != '/welcome' &&
          !loc.startsWith('/waiting-room')) {
        return '/welcome';
      }

      if (!authed && authProvider.hasSeenOnboarding && loc == '/welcome') {
        return '/login';
      }

      if (!authed && !isPublicRoute) return '/login';

      if (authed && isPublicRoute) return '/';

      return null;
    },
    routes: [
      GoRoute(path: '/login', builder: (context, state) => const LoginScreen()),
      GoRoute(
        path: '/splash',
        builder: (context, state) => const SplashScreen(),
      ),
      GoRoute(
        path: '/welcome',
        builder: (context, state) => const WelcomeScreen(),
      ),
      GoRoute(
        path: '/register',
        builder: (context, state) => const RegisterScreen(),
      ),
      GoRoute(
        path: '/forgot-password',
        builder: (context, state) => const ForgotPasswordScreen(),
      ),
      GoRoute(
        path: '/waiting-room',
        builder: (context, state) {
          final email = state.uri.queryParameters['email'] ?? '';
          return WaitingRoomScreen(email: email);
        },
      ),
      ShellRoute(
        builder: (context, state, child) => MainShell(child: child),
        routes: [
          GoRoute(
            path: '/',
            builder: (context, state) => const DashboardScreen(),
          ),
          GoRoute(
            path: '/more',
            builder: (context, state) => const MoreScreen(),
          ),
          GoRoute(
            path: '/stock',
            builder: (context, state) => const StockHubScreen(),
            routes: [
              GoRoute(
                path: 'receive',
                builder: (context, state) {
                  final id = state.uri.queryParameters['product_id'];
                  return ReceiveStockScreen(
                    productId: id == null ? null : int.tryParse(id),
                  );
                },
              ),
              GoRoute(
                path: 'adjust',
                builder: (context, state) {
                  final id = state.uri.queryParameters['product_id'];
                  return AdjustStockScreen(
                    productId: id == null ? null : int.tryParse(id),
                  );
                },
              ),
              GoRoute(
                path: 'receipts',
                builder: (context, state) => const GoodsReceiptsScreen(),
                routes: [
                  GoRoute(
                    path: ':id',
                    builder: (context, state) => GoodsReceiptDetailScreen(
                      receiptId: int.parse(state.pathParameters['id']!),
                    ),
                  ),
                ],
              ),
              GoRoute(
                path: 'exchanges',
                builder: (context, state) => const ExchangesListScreen(),
                routes: [
                  GoRoute(
                    path: 'new',
                    builder: (context, state) => const ExchangeFormScreen(),
                  ),
                  GoRoute(
                    path: ':id/edit',
                    builder: (context, state) => ExchangeFormScreen(
                      editingExchangeId: int.parse(
                        state.pathParameters['id']!,
                      ),
                    ),
                  ),
                  GoRoute(
                    path: ':id',
                    builder: (context, state) => ExchangeDetailScreen(
                      exchangeId: int.parse(state.pathParameters['id']!),
                    ),
                  ),
                ],
              ),
              GoRoute(
                path: 'counts',
                builder: (context, state) => const StockCountsScreen(),
                routes: [
                  GoRoute(
                    path: ':id',
                    builder: (context, state) => StockCountScreen(
                      countId: int.parse(state.pathParameters['id']!),
                    ),
                  ),
                ],
              ),
            ],
          ),
          GoRoute(
            path: '/reports',
            builder: (context, state) => const ReportsHubScreen(),
            routes: [
              GoRoute(
                path: 'owing',
                builder: (context, state) => const OwingReportScreen(),
              ),
              GoRoute(
                path: 'low-stock',
                builder: (context, state) => const LowStockReportScreen(),
              ),
              GoRoute(
                path: 'sales-summary',
                builder: (context, state) => const SalesSummaryReportScreen(),
              ),
              GoRoute(
                path: 'profit',
                builder: (context, state) => const ProfitReportScreen(),
              ),
              GoRoute(
                path: 'best-sellers',
                builder: (context, state) => const BestSellersReportScreen(),
              ),
              GoRoute(
                path: 'stock-valuation',
                builder: (context, state) => const StockValuationReportScreen(),
              ),
              GoRoute(
                path: 'dead-stock',
                builder: (context, state) => const DeadStockReportScreen(),
              ),
            ],
          ),
          GoRoute(
            path: '/products',
            builder: (context, state) => const ProductsListScreen(),
            routes: [
              GoRoute(
                path: 'new',
                builder: (context, state) {
                  final q = state.uri.queryParameters;
                  return ProductFormScreen(
                    initialTitle: q['title'],
                    returnProduct: q['return'] == '1',
                  );
                },
              ),
              GoRoute(
                path: 'scan',
                builder: (context, state) => ProductScanScreen(
                  pickMode: state.uri.queryParameters['pick'] == '1',
                  captureMode: state.uri.queryParameters['capture'] == '1',
                ),
              ),
              GoRoute(
                path: 'approved',
                builder: (context, state) => ReferenceSearchScreen(
                  pickMode: state.uri.queryParameters['pick'] == '1',
                ),
                routes: [
                  GoRoute(
                    path: ':bookId/new',
                    builder: (context, state) => QuickCreateProductScreen(
                      bookId: int.parse(state.pathParameters['bookId']!),
                      code: state.uri.queryParameters['code'],
                      forReceive: state.uri.queryParameters['receive'] == '1',
                    ),
                  ),
                ],
              ),
              GoRoute(
                path: ':id',
                builder: (context, state) {
                  final id = int.parse(state.pathParameters['id']!);
                  return ProductDetailScreen(productId: id);
                },
                routes: [
                  GoRoute(
                    path: 'edit',
                    builder: (context, state) {
                      final id = int.parse(state.pathParameters['id']!);
                      return ProductFormScreen(productId: id);
                    },
                  ),
                ],
              ),
            ],
          ),
          GoRoute(
            path: '/customers',
            builder: (context, state) => const CustomersListScreen(),
            routes: [
              GoRoute(
                path: 'new',
                builder: (context, state) => const CustomerFormScreen(),
              ),
              GoRoute(
                path: 'directory',
                builder: (context, state) => const SchoolDirectoryScreen(),
              ),
              GoRoute(
                path: ':id',
                builder: (context, state) => CustomerDetailScreen(
                  customerId: int.parse(state.pathParameters['id']!),
                ),
                routes: [
                  GoRoute(
                    path: 'edit',
                    builder: (context, state) => CustomerFormScreen(
                      customerId: int.parse(state.pathParameters['id']!),
                    ),
                  ),
                  GoRoute(
                    path: 'pay',
                    builder: (context, state) => RecordPaymentScreen(
                      customerId: int.parse(state.pathParameters['id']!),
                    ),
                  ),
                ],
              ),
            ],
          ),
          GoRoute(
            path: '/sales',
            builder: (context, state) => const SalesListScreen(),
            routes: [
              GoRoute(
                path: 'new',
                builder: (context, state) {
                  final raw = state.uri.queryParameters['customer_id'];
                  final id = raw == null ? null : int.tryParse(raw);
                  return NewSaleScreen(initialCustomerId: id);
                },
              ),
              GoRoute(
                path: ':id/edit',
                builder: (context, state) => NewSaleScreen(
                  editingSaleId: int.parse(state.pathParameters['id']!),
                ),
              ),
              GoRoute(
                path: ':id',
                builder: (context, state) => SaleDetailScreen(
                  saleId: int.parse(state.pathParameters['id']!),
                  openConfirm: state.uri.queryParameters['confirm'] == '1',
                ),
              ),
            ],
          ),
          GoRoute(
            path: '/payments',
            builder: (context, state) => const PaymentsListScreen(),
            routes: [
              GoRoute(
                path: ':id',
                builder: (context, state) => PaymentDetailScreen(
                  paymentId: int.parse(state.pathParameters['id']!),
                ),
              ),
            ],
          ),
        ],
      ),
    ],
  );
}

extension GoRouterContext on BuildContext {
  GoRouter get appRouter => GoRouter.of(this);
}
