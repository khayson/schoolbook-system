import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/features/catalog/data/lookups_repository.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/presentation/products_list_provider.dart';
import 'package:schoolbook/features/products/presentation/products_list_screen.dart';

import '../../support/fakes.dart';

void main() {
  testWidgets('products list shows NOTEE chrome and catalog rows', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.625;
    addTearDown(tester.view.reset);

    final products = RecordingProductsRepository([
      testProduct(id: 11, title: 'Sunrise Maths P4'),
    ]);
    final router = GoRouter(
      initialLocation: '/products',
      routes: [
        GoRoute(
          path: '/products',
          builder: (_, _) => const ProductsListScreen(),
          routes: [
            GoRoute(
              path: ':id',
              builder: (_, s) =>
                  Scaffold(body: Text('detail ${s.pathParameters['id']}')),
            ),
            GoRoute(
              path: 'scan',
              builder: (_, _) => const SizedBox(),
            ),
            GoRoute(
              path: 'approved',
              builder: (_, _) => const SizedBox(),
            ),
            GoRoute(
              path: 'new',
              builder: (_, _) => const SizedBox(),
            ),
          ],
        ),
      ],
    );

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          Provider<ProductsRepository>.value(value: products),
          Provider<LookupsRepository>.value(value: FakeLookupsRepository()),
          ChangeNotifierProvider(
            create: (context) => ProductsListProvider(
              productsRepository: context.read<ProductsRepository>(),
              lookupsRepository: context.read<LookupsRepository>(),
            ),
          ),
        ],
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Products'), findsOneWidget);
    expect(find.byKey(const Key('products_search')), findsOneWidget);
    expect(find.byKey(const Key('products_scan')), findsOneWidget);
    expect(find.byKey(const Key('products_from_list')), findsOneWidget);
    expect(find.byKey(const Key('products_new')), findsOneWidget);
    expect(find.text('Sunrise Maths P4'), findsOneWidget);
    expect(find.byKey(const Key('product_11')), findsOneWidget);
  });
}
