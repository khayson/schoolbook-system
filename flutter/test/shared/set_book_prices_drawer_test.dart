import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/features/exchanges/domain/stock_exchange.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/shared/widgets/set_book_prices_drawer.dart';

import '../support/fakes.dart';

StockExchangeItem item({
  required int productId,
  required ExchangeDirection direction,
  int qty = 1,
  int? unitPrice,
  int? unitCost,
  int productCost = 0,
  int productSell = 0,
}) {
  return StockExchangeItem(
    id: productId,
    direction: direction,
    productId: productId,
    quantity: qty,
    unitPrice: unitPrice,
    unitCost: unitCost,
    product: testProduct(id: productId).copyWithPrices(
      cost: productCost,
      selling: productSell,
    ),
  );
}

extension on Product {
  Product copyWithPrices({required int cost, required int selling}) =>
      Product.fromJson({
        ...toCacheJson(),
        'cost_price': cost,
        'selling_price': selling,
      });
}

void main() {
  test('booksNeedingPrices picks out without selling and in without cost', () {
    final needing = booksNeedingPrices([
      item(productId: 1, direction: ExchangeDirection.out, productSell: 0),
      item(productId: 2, direction: ExchangeDirection.out, productSell: 1500),
      item(productId: 3, direction: ExchangeDirection.inn, productCost: 0),
      item(productId: 4, direction: ExchangeDirection.inn, productCost: 800),
      item(
        productId: 5,
        direction: ExchangeDirection.out,
        unitPrice: 2000,
        productSell: 0,
      ),
    ]);

    expect(needing.map((b) => b.productId), [1, 3]);
  });

  testWidgets('drawer lists books and requires save before continue', (
    tester,
  ) async {
    final products = FakeProductsRepository([
      testProduct(id: 11, title: 'Victory OWOP KG1'),
    ]);

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          Provider<ProductsRepository>.value(value: products),
        ],
        child: MaterialApp(
          theme: buildLightTheme(),
          home: Scaffold(
            body: SetBookPricesDrawer(
              books: [
                PriceDraftBook(
                  productId: 11,
                  title: 'Victory OWOP KG1',
                  sku: 'SKU-11',
                  direction: ExchangeDirection.out,
                  quantity: 25,
                  costPesewas: 0,
                  sellingPesewas: 0,
                ),
              ],
            ),
          ),
        ),
      ),
    );

    expect(find.byKey(const Key('set_book_prices_drawer')), findsOneWidget);
    expect(find.text('Victory OWOP KG1'), findsOneWidget);
    expect(find.text('Save prices'), findsOneWidget);

    final continueBtn = tester.widget<FilledButton>(
      find.byKey(const Key('set_book_prices_continue')),
    );
    expect(continueBtn.onPressed, isNull);
  });
}
