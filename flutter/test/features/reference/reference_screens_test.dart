import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/features/catalog/data/lookups_repository.dart';
import 'package:schoolbook/features/catalog/domain/lookup_models.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/products/presentation/product_scan_screen.dart';
import 'package:schoolbook/features/reference/data/reference_cache_store.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';
import 'package:schoolbook/features/reference/presentation/quick_create_product_screen.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';
import 'package:schoolbook/features/reference/presentation/reference_search_screen.dart';
import 'package:schoolbook/features/stock/data/stock_repository.dart';
import 'package:schoolbook/features/stock/presentation/receive_stock_screen.dart';

import '../../support/fakes.dart';

class _Lookups extends FakeLookupsRepository {
  @override
  Future<List<LevelLookup>> fetchLevels() async => const [
        LevelLookup(id: 6, name: 'Primary 1'),
        LevelLookup(id: 7, name: 'Primary 2'),
        LevelLookup(id: 9, name: 'Primary 4'),
      ];
}

void main() {
  late RecordingProductsRepository products;
  late FakeReferenceRepository referenceApi;
  late ReferenceCatalog catalog;
  late Product? scannedResult;

  final sunrise = testBook(id: 1, title: 'Sunrise Mathematics for Basic Schools');
  final discover = testBook(id: 2, title: 'Discover Science', subject: 'Science', isbn: '9789988012342');
  final band = testBook(id: 3, title: 'Number Games Activity Book', level: 'Lower Primary', levelId: null, band: 'lower_primary');

  setUp(() async {
    products = RecordingProductsRepository([testProduct(id: 77, title: 'Old English Reader')]);
    referenceApi = FakeReferenceRepository(books: [sunrise, discover, band])
      ..stock = {2: const TitleStock(productsCount: 2, stockOnHand: 15)};
    catalog = ReferenceCatalog(repository: referenceApi, cache: InMemoryReferenceCacheStore());
    await catalog.sync();
    scannedResult = null;
  });

  Future<void> pump(WidgetTester tester, String location, {Widget Function(void Function(String))? scanner}) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.625;
    addTearDown(tester.view.reset);

    final router = GoRouter(
      initialLocation: '/home',
      routes: [
        GoRoute(
          path: '/home',
          builder: (context, _) => Scaffold(
            body: Column(children: [
              TextButton(
                onPressed: () async => scannedResult = await context.push<Product>(location),
                child: const Text('open'),
              ),
              Text('result ${scannedResult?.id}'),
            ]),
          ),
        ),
        GoRoute(
          path: '/products/scan',
          builder: (_, s) => ProductScanScreen(
            pickMode: s.uri.queryParameters['pick'] == '1',
            captureMode: s.uri.queryParameters['capture'] == '1',
            scannerBuilder: scanner ?? (onCode) => const SizedBox(),
          ),
        ),
        GoRoute(
          path: '/products/approved',
          builder: (_, s) => ReferenceSearchScreen(pickMode: s.uri.queryParameters['pick'] == '1'),
          routes: [
            GoRoute(
              path: ':bookId/new',
              builder: (_, s) => QuickCreateProductScreen(
                bookId: int.parse(s.pathParameters['bookId']!),
                code: s.uri.queryParameters['code'],
                forReceive: s.uri.queryParameters['receive'] == '1',
              ),
            ),
          ],
        ),
        GoRoute(path: '/stock/receive', builder: (_, _) => const ReceiveStockScreen()),
        GoRoute(path: '/products/:id', builder: (_, s) => Scaffold(body: Text('product ${s.pathParameters['id']}'))),
      ],
    );

    await tester.pumpWidget(MultiProvider(
      providers: [
        Provider<ProductsRepository>.value(value: products),
        Provider<LookupsRepository>.value(value: _Lookups()),
        Provider<PendingSubmissionStore>.value(value: PendingSubmissionStore(store: InMemoryKeyValueStore())),
        Provider<StockRepository>.value(value: StockRepository(apiClient: unusedApiClient())),
        ChangeNotifierProvider<ReferenceCatalog>.value(value: catalog),
      ],
      child: MaterialApp.router(routerConfig: router),
    ));
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
  }

  testWidgets('the approved list shows whether the shop carries each title', (tester) async {
    await pump(tester, '/products/approved');

    await tester.enterText(find.byKey(const Key('ref_search')), 'p4');
    await tester.pumpAndSettle();

    expect(find.text('NaCCA Test Edition · 3 titles · stock as of'), findsNothing);
    expect(find.textContaining('NaCCA Test Edition · 3 titles'), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('ref_book_2')), matching: find.text('In stock 15')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('ref_book_1')), matching: find.text('Not in your products')), findsOneWidget);
    expect(find.byKey(const Key('ref_book_3')), findsNothing); // Lower Primary does not cover P4
  });

  testWidgets('offline, the stored copy is searched and the screen says so', (tester) async {
    referenceApi.failWith = ApiException.network();
    await catalog.sync();
    await pump(tester, '/products/approved');

    expect(find.textContaining('Offline: using the copy on this phone'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('ref_search')), 'discover');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('ref_book_2')), findsOneWidget);
  });

  testWidgets('quick-create of a band-only title asks for the class, then returns the product', (tester) async {
    await pump(tester, '/products/approved/3/new');

    expect(find.text('Number Games Activity Book'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('qc_cost')), '27.50');
    await tester.enterText(find.byKey(const Key('qc_price')), '1,040');
    await tester.enterText(find.byKey(const Key('qc_opening')), '12');
    await tester.tap(find.byKey(const Key('qc_save')));
    await tester.pumpAndSettle();

    expect(find.text('Choose the class'), findsOneWidget);
    expect(products.created, isEmpty);

    await tester.tap(find.byKey(const Key('qc_level')));
    await tester.pumpAndSettle();
    expect(find.text('Primary 4'), findsNothing); // only classes in the band
    await tester.tap(find.text('Primary 2').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('qc_save')));
    await tester.pumpAndSettle();

    expect(products.created.single.$1, {
      'reference_book_id': 3,
      'level_id': 7,
      'cost_price': 2750,
      'selling_price': 104000,
      'opening_stock': 12,
    });
    expect(products.created.single.$2, isNotNull); // idempotency key sent
    expect(find.text('result 501'), findsOneWidget);
    expect(catalog.stockFor(3)?.stockOnHand, 12);
  });

  testWidgets('an unknown scanned code is attached to one of my products; the next scan finds it', (tester) async {
    late void Function(String) scan;
    await pump(tester, '/products/scan?pick=1', scanner: (onCode) {
      scan = onCode;
      return const SizedBox();
    });

    scan('5012345678900');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('scan_unknown')), findsOneWidget);
    expect(find.byKey(const Key('scan_add_hinted')), findsNothing); // no approved title knows it

    await tester.tap(find.byKey(const Key('scan_attach_product')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('picker_search')), 'old');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('picker_product_77')));
    await tester.pumpAndSettle();

    expect(products.attached.single, ('5012345678900', 77));
    expect(find.text('result 77'), findsOneWidget);

    // Next scan: straight to the product.
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
    scan('5012345678900');
    await tester.pumpAndSettle();
    expect(find.text('result 77'), findsOneWidget);
    expect(products.attached, hasLength(1));
  });

  testWidgets('a code an approved title knows is offered as a new product with the code attached', (tester) async {
    late void Function(String) scan;
    await pump(tester, '/products/scan?pick=1', scanner: (onCode) {
      scan = onCode;
      return const SizedBox();
    });

    scan('9789988012342');
    await tester.pumpAndSettle();
    expect(find.text('On the approved list: ${discover.subtitle}'), findsOneWidget);

    await tester.tap(find.byKey(const Key('scan_add_hinted')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('qc_title')), findsOneWidget);
    expect(tester.widget<TextFormField>(find.byKey(const Key('qc_code'))).controller!.text, '9789988012342');

    await tester.enterText(find.byKey(const Key('qc_variant')), "Teacher's Guide");
    await tester.enterText(find.byKey(const Key('qc_cost')), '30');
    await tester.enterText(find.byKey(const Key('qc_price')), '45');
    await tester.tap(find.byKey(const Key('qc_save')));
    await tester.pumpAndSettle();

    expect(products.created.single.$1['variant_label'], "Teacher's Guide");
    expect(products.attached.single, ('9789988012342', 501));
    expect(find.text('result 501'), findsOneWidget);
  });

  testWidgets('receive stock: an approved title not carried yet is created and added as a line', (tester) async {
    await pump(tester, '/stock/receive');

    await tester.enterText(find.byType(SearchBar), 'sunrise');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    expect(find.text('On the approved list, not in your products yet'), findsOneWidget);
    await tester.tap(find.byKey(const Key('ref_book_1')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('qc_opening')), findsNothing); // the receipt line is the stock
    await tester.enterText(find.byKey(const Key('qc_cost')), '27.50');
    await tester.enterText(find.byKey(const Key('qc_price')), '40');
    await tester.tap(find.byKey(const Key('qc_save')));
    await tester.pumpAndSettle();

    expect(products.created.single.$1, {'reference_book_id': 1, 'cost_price': 2750, 'selling_price': 4000});
    expect(find.text('Receipt lines'), findsOneWidget);
    expect(find.text('Created 1'), findsOneWidget); // the new product is now a line
    expect(find.text('On the approved list, not in your products yet'), findsNothing);
  });
}
