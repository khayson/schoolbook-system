import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/exchanges/data/exchanges_repository.dart';
import 'package:schoolbook/features/exchanges/presentation/exchange_form_screen.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/reference/data/reference_cache_store.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';

import '../../support/fakes.dart';

void main() {
  testWidgets('new exchange form shows Taken and They took directions', (
    tester,
  ) async {
    final catalog = ReferenceCatalog(
      repository: FakeReferenceRepository(books: const []),
      cache: InMemoryReferenceCacheStore(),
    );

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          Provider<ExchangesRepository>(
            create: (_) => ExchangesRepository(
              apiClient: ApiClient(tokenStore: AuthTokenStore()),
            ),
          ),
          Provider<CustomersRepository>(
            create: (_) => FakeCustomersRepository(),
          ),
          Provider<ProductsRepository>(
            create: (_) => FakeProductsRepository([]),
          ),
          ChangeNotifierProvider<ReferenceCatalog>.value(value: catalog),
        ],
        child: MaterialApp(
          theme: buildLightTheme(),
          home: const ExchangeFormScreen(),
        ),
      ),
    );

    expect(find.textContaining('Who is exchanging'), findsOneWidget);
    expect(find.textContaining('Books each side took'), findsOneWidget);
    expect(find.text('Taken'), findsOneWidget);
    expect(find.text('They took'), findsOneWidget);
    expect(find.textContaining('Pick or save a person'), findsOneWidget);
    expect(find.textContaining('Save new person here'), findsOneWidget);
    expect(find.textContaining('Name on the paper'), findsOneWidget);

    await tester.tap(find.text('They took'));
    await tester.pumpAndSettle();

    // Settlement lives below the fold in the scrollable form.
    await tester.drag(find.byType(ListView).first, const Offset(0, -800));
    await tester.pumpAndSettle();

    expect(find.textContaining('How they pay'), findsOneWidget);
    expect(find.text('Cash and carry'), findsOneWidget);
    expect(find.text('Installment'), findsOneWidget);
    expect(find.text('Pay later'), findsOneWidget);
  });
}
