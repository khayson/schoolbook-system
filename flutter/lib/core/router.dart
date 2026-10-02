import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';
import 'package:schoolbook/features/auth/presentation/login_screen.dart';
import 'package:schoolbook/features/customers/presentation/customer_detail_screen.dart';
import 'package:schoolbook/features/customers/presentation/customer_form_screen.dart';
import 'package:schoolbook/features/customers/presentation/customers_list_screen.dart';
import 'package:schoolbook/features/dashboard/presentation/dashboard_screen.dart';
import 'package:schoolbook/features/payments/presentation/payment_detail_screen.dart';
import 'package:schoolbook/features/payments/presentation/payments_list_screen.dart';
import 'package:schoolbook/features/payments/presentation/record_payment_screen.dart';
import 'package:schoolbook/features/products/presentation/product_detail_screen.dart';
import 'package:schoolbook/features/products/presentation/product_form_screen.dart';
import 'package:schoolbook/features/products/presentation/product_scan_screen.dart';
import 'package:schoolbook/features/products/presentation/products_list_screen.dart';
import 'package:schoolbook/features/stock/presentation/receive_stock_screen.dart';
import 'package:schoolbook/shared/widgets/async_state_widgets.dart';

GoRouter createAppRouter(AuthProvider authProvider) {
  return GoRouter(
    initialLocation: '/',
    refreshListenable: authProvider,
    redirect: (context, state) {
      final status = authProvider.status;
      if (status == AuthStatus.unknown) {
        return null;
      }
      final loggingIn = state.matchedLocation == '/login';
      final authed = authProvider.isAuthenticated;
      if (!authed && !loggingIn) {
        return '/login';
      }
      if (authed && loggingIn) {
        return '/';
      }
      return null;
    },
    routes: [
      GoRoute(
        path: '/login',
        builder: (context, state) => const LoginScreen(),
      ),
      GoRoute(
        path: '/',
        builder: (context, state) {
          if (authProvider.status == AuthStatus.unknown) {
            return const Scaffold(body: LoadingBody(message: 'Starting…'));
          }
          return const DashboardScreen();
        },
      ),
      GoRoute(
        path: '/products',
        builder: (context, state) => const ProductsListScreen(),
        routes: [
          GoRoute(
            path: 'new',
            builder: (context, state) => const ProductFormScreen(),
          ),
          GoRoute(
            path: 'scan',
            builder: (context, state) => const ProductScanScreen(),
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
        path: '/stock/receive',
        builder: (context, state) => const ReceiveStockScreen(),
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
  );
}

/// Reads [GoRouter] from context — useful in tests.
extension GoRouterContext on BuildContext {
  GoRouter get appRouter => GoRouter.of(this);
}
