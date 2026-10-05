// Phase 3.A acceptance, step 5 (docs/acceptance-phase3a.md): the approved list on the
// phone, driven through the real app on the Pixel_9a against the owner-reviewed list, on
// the owner's own dev server (port 8000, the app's default; no second server).
//
//   .\scripts\phone-offline-window.ps1 -Watch run.txt        (second window)
//   flutter test integration_test/phase3a_acceptance_test.dart -d emulator-5554 | Tee-Object run.txt
//
// Offline: the test prints "ACCEPTANCE-GO-OFFLINE"; the host script sees it in the test
// output and turns the emulator's airplane mode on for 30 s, then off. The server keeps
// running: only the phone loses its network.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:integration_test/integration_test.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/app.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/products/data/products_repository.dart';
import 'package:schoolbook/features/products/domain/product.dart';
import 'package:schoolbook/features/reference/data/reference_cache_store.dart';
import 'package:schoolbook/features/reference/data/reference_repository.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';

const ownerEmail = String.fromEnvironment('OWNER_EMAIL', defaultValue: 'owner@schoolbook.test');
const ownerPassword = String.fromEnvironment('OWNER_PASSWORD', defaultValue: 'password');

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('Phase 3.A: offline list, 10 products from the list, receive via the list, scan-attach', (tester) async {
    final runId = DateTime.now().millisecondsSinceEpoch.toString().substring(5);
    void step(String s) => debugPrint('ACCEPTANCE: $s');

    await tester.pumpWidget(const SchoolbookApp());
    await _waitFor(
      tester,
      find.byWidgetPredicate((w) => w.key == const Key('login_email') || (w is Text && w.data == 'Quick actions')),
    );
    if (find.byTooltip('Sign out').evaluate().isNotEmpty) {
      await tester.tap(find.byTooltip('Sign out'));
      await _settle(tester);
    }

    // 1. Log in: the approved list downloads -----------------------------------------
    await _waitFor(tester, find.byKey(const Key('login_email')));
    await tester.enterText(find.byKey(const Key('login_email')), ownerEmail);
    await tester.enterText(find.byKey(const Key('login_password')), ownerPassword);
    await tester.tap(find.byKey(const Key('login_submit')));
    await _waitFor(tester, find.text('Quick actions'));

    final catalog = tester.element(find.byType(Scaffold).first).read<ReferenceCatalog>();
    await _until(tester, () => catalog.books.isNotEmpty && !catalog.syncing, 'approved list downloaded');
    final etag = catalog.etag;
    step('1 logged in; approved list on the phone: ${catalog.books.length} titles, edition "${catalog.editionLabel}", ETag $etag');
    expect(catalog.offline, isFalse);

    _go(tester, '/products/approved');
    await _waitFor(tester, find.byKey(const Key('ref_search')));
    await tester.enterText(find.byKey(const Key('ref_search')), 'maths p4');
    await _settle(tester);
    final p4 = catalog.search('maths p4');
    expect(p4, isNotEmpty);
    expect(p4.every((b) => b.level == 'Primary 4' || b.band == 'upper_primary'), isTrue);
    expect(find.byKey(Key('ref_book_${p4.first.id}')), findsOneWidget);
    step('2 online search "maths p4": ${p4.length} titles, all Primary 4 (first: ${p4.first.title})');

    // A second sync is a 304: nothing downloaded again.
    await catalog.sync();
    expect(catalog.etag, etag);
    step('2b re-sync: ETag unchanged ($etag), list kept');

    // 2. Offline: the host script puts the phone in airplane mode for 30 s ------------------
    debugPrint('ACCEPTANCE-GO-OFFLINE');
    await _until(tester, () async {
      await catalog.sync();
      return catalog.offline;
    }, 'phone offline (airplane mode)', timeout: const Duration(seconds: 90));
    await _settle(tester);
    expect(find.textContaining('Offline: using the copy on this phone'), findsOneWidget);

    // A fresh start with no server: the stored file is loaded and searched.
    final cold = ReferenceCatalog(
      repository: ReferenceRepository(apiClient: ApiClient(tokenStore: AuthTokenStore())),
      cache: FileReferenceCacheStore(),
    );
    await cold.sync();
    expect(cold.offline, isTrue);
    expect(cold.books.length, catalog.books.length);
    final offlineHits = cold.search('maths p4');
    expect(offlineHits.map((b) => b.id), p4.map((b) => b.id));
    await tester.enterText(find.byKey(const Key('ref_search')), 'science jhs 2');
    await _settle(tester);
    final offlineScience = catalog.search('science jhs 2');
    expect(offlineScience, isNotEmpty);
    expect(find.byKey(Key('ref_book_${offlineScience.first.id}')), findsOneWidget);
    step('3 offline: screen says "Offline"; cold start loads ${cold.books.length} titles from the phone; '
        '"maths p4" gives the same ${offlineHits.length} titles; "science jhs 2" gives ${offlineScience.length}');

    await _until(tester, () async {
      await catalog.sync();
      return !catalog.offline;
    }, 'server back', timeout: const Duration(seconds: 120));
    step('3b server back: online again, ETag ${catalog.etag}');

    // The copy used offline is the server's list: download it fresh (no ETag) and compare.
    final fresh = await ReferenceRepository(apiClient: ApiClient(tokenStore: AuthTokenStore())).fetchSnapshot();
    final serverIds = [for (final b in fresh.data!['books'] as List<dynamic>) (b as Map<String, dynamic>)['id'] as int];
    final phoneIds = [for (final b in cold.books) b.id];
    expect(fresh.etag, cold.etag);
    expect(phoneIds, serverIds);
    step('3c phone copy used offline = server list: same ETag ${fresh.etag}, same ${serverIds.length} title ids in the same order');

    // 3. Ten products from the list ------------------------------------------------------
    final products = ProductsRepository(apiClient: ApiClient(tokenStore: AuthTokenStore()));
    bool unstocked(ReferenceBook b) => catalog.stockFor(b.id) == null;
    final textbooks = <ReferenceBook>[];
    final seenSubjects = <int>{};
    for (final b in catalog.books) {
      if (b.category == 'textbook' && !b.needsLevel && b.subjectId != null && b.languageId != null && unstocked(b) &&
          seenSubjects.add(b.subjectId!)) {
        textbooks.add(b);
      }
      if (textbooks.length == 8) break;
    }
    final band = catalog.books.firstWhere(
      (b) => b.band == 'lower_primary' && b.subjectId != null && b.languageId != null && unstocked(b),
    );
    final code = _ean13('29${runId.padLeft(10, '0').substring(0, 10)}');

    final plan = <({ReferenceBook book, String? variant, String? opening, String? code, String? level})>[
      (book: textbooks[0], variant: "Learner's Book", opening: null, code: null, level: null),
      (book: textbooks[0], variant: "Teacher's Guide", opening: null, code: null, level: null),
      (book: textbooks[1], variant: null, opening: '5', code: null, level: null),
      (book: textbooks[2], variant: null, opening: null, code: code, level: null),
      (book: band, variant: null, opening: '3', code: null, level: 'Primary 2'),
      for (final b in textbooks.sublist(3, 8)) (book: b, variant: null, opening: null, code: null, level: null),
    ];
    expect(plan, hasLength(10));

    final created = <Product>[];
    for (final (i, p) in plan.indexed) {
      _go(tester, '/products/approved/${p.book.id}/new');
      await _waitFor(tester, find.byKey(const Key('qc_save')));
      if (p.variant != null) await tester.enterText(find.byKey(const Key('qc_variant')), p.variant!);
      if (p.level != null) {
        // The class list is fetched when the form opens: open the menu once it has the option.
        await _until(tester, () async {
          await tester.tap(find.byKey(const Key('qc_level')));
          await _settle(tester);
          if (find.text(p.level!).evaluate().isNotEmpty) return true;
          await tester.tapAt(const Offset(10, 10)); // close the empty menu, try again
          await _settle(tester);
          return false;
        }, 'class list loaded', timeout: const Duration(seconds: 30));
        await tester.tap(find.text(p.level!).last);
        await _settle(tester);
      }
      await tester.enterText(find.byKey(const Key('qc_cost')), '27.50');
      await tester.enterText(find.byKey(const Key('qc_price')), '1,040.00');
      if (p.opening != null) await tester.enterText(find.byKey(const Key('qc_opening')), p.opening!);
      if (p.code != null) await tester.enterText(find.byKey(const Key('qc_code')), p.code!);
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await _settle(tester);
      await tester.scrollUntilVisible(find.byKey(const Key('qc_save')), 200, scrollable: find.byType(Scrollable).last);
      await tester.tap(find.byKey(const Key('qc_save')));
      // Saved: the app leaves the form (back to the approved list it was opened from).
      await _until(tester, () => !_location(tester).endsWith('/new'), 'quick-create closed', timeout: const Duration(seconds: 30));
      await _settle(tester);

      final expectedTitle = p.variant == null ? p.book.title : '${p.book.title} (${p.variant})';
      final product = (await products.listProducts(search: expectedTitle))
          .data
          .where((x) => x.referenceBookId == p.book.id && x.title == expectedTitle && !created.any((c) => c.id == x.id))
          .reduce((a, b) => a.id > b.id ? a : b);
      created.add(product);
      expect(product.referenceBookId, p.book.id);
      expect(product.costPrice, 2750);
      expect(product.sellingPrice, 104000);
      expect(product.stockOnHand, int.tryParse(p.opening ?? '') ?? 0);
      expect(product.title, p.variant == null ? p.book.title : '${p.book.title} (${p.variant})');
      if (p.code != null) expect(product.barcode, code);
      if (p.level != null) expect(product.level?.name, p.level);
      step('4.${i + 1} added ${product.sku} "${product.title}" stock ${product.stockOnHand}'
          '${p.code != null ? ' barcode ${product.barcode}' : ''}${p.level != null ? ' class ${product.level?.name}' : ''}');
    }

    // 4. Receive stock through the list ----------------------------------------------------
    final toReceive = catalog.books.firstWhere(
      (b) => b.category == 'textbook' && !b.needsLevel && b.subjectId != null && unstocked(b) &&
          !plan.any((p) => p.book.id == b.id) && catalog.search(b.title).first.id == b.id,
    );
    _go(tester, '/stock/receive');
    await _waitFor(tester, find.byType(SearchBar));
    await tester.enterText(find.byType(SearchBar), toReceive.title);
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await _waitFor(tester, find.byKey(Key('ref_book_${toReceive.id}')));
    await tester.tap(find.byKey(Key('ref_book_${toReceive.id}')));
    await _waitFor(tester, find.byKey(const Key('qc_save')));
    expect(find.byKey(const Key('qc_opening')), findsNothing);
    await tester.enterText(find.byKey(const Key('qc_cost')), '30.00');
    await tester.enterText(find.byKey(const Key('qc_price')), '45.00');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await _settle(tester);
    await tester.tap(find.byKey(const Key('qc_save')));
    await _waitFor(tester, find.widgetWithText(TextField, 'Qty'));
    await tester.enterText(find.widgetWithText(TextField, 'Qty'), '7');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await _settle(tester);
    await tester.scrollUntilVisible(find.text('Submit receipt'), 200, scrollable: find.byType(Scrollable).last);
    await tester.tap(find.text('Submit receipt'));
    await _waitFor(tester, find.text('Quick actions'));
    final received = (await products.listProducts(search: toReceive.title)).data.firstWhere((p) => p.referenceBookId == toReceive.id);
    expect(received.stockOnHand, 7);
    expect(received.costPrice, 3000);
    step('5 received 7 x ${received.sku} "${received.title}" through the list (created from the approved list in the receipt)');

    // 5. Scan-attach -------------------------------------------------------------------------
    final unknown = _ean13('28${runId.padLeft(10, '0').substring(0, 10)}');
    final target = created.last;
    _go(tester, '/products/scan');
    await _waitFor(tester, find.byKey(const Key('scan_manual')));
    await tester.enterText(find.byKey(const Key('scan_manual')), unknown);
    await tester.tap(find.byKey(const Key('scan_lookup')));
    await _waitFor(tester, find.byKey(const Key('scan_unknown')));
    await tester.tap(find.byKey(const Key('scan_attach_product')));
    await _waitFor(tester, find.byKey(const Key('picker_search')));
    await tester.enterText(find.byKey(const Key('picker_search')), target.sku);
    await _waitFor(tester, find.byKey(Key('picker_product_${target.id}')));
    await tester.tap(find.byKey(Key('picker_product_${target.id}')));
    await _waitFor(tester, find.byWidgetPredicate((w) => w is Text && (w.data ?? '').startsWith('Stock on hand')));
    expect(_location(tester), '/products/${target.id}');
    expect((await products.getProduct(target.id)).barcode, unknown);

    _go(tester, '/products/scan');
    await _waitFor(tester, find.byKey(const Key('scan_manual')));
    await tester.enterText(find.byKey(const Key('scan_manual')), unknown);
    await tester.tap(find.byKey(const Key('scan_lookup')));
    await _waitFor(tester, find.byWidgetPredicate((w) => w is Text && (w.data ?? '').startsWith('Stock on hand')));
    expect(_location(tester), '/products/${target.id}');
    step('6 unknown code $unknown attached to ${target.sku}; scanning it again opens that product');

    // A code already used is refused with a clear message.
    try {
      await products.attachCode(code: unknown, productId: created.first.id);
      fail('duplicate code was accepted');
    } on ApiException catch (e) {
      expect(e.code, 'duplicate_code');
      step('6b the same code on another product: 409 duplicate_code ("${e.message}")');
    }

    step('DONE run $runId: products ${created.map((p) => p.id).join(',')},${received.id}');
  });
}

String _ean13(String twelve) {
  var sum = 0;
  for (var i = 0; i < 12; i++) {
    sum += int.parse(twelve[i]) * (i.isEven ? 1 : 3);
  }
  return '$twelve${(10 - sum % 10) % 10}';
}

String _location(WidgetTester tester) =>
    GoRouter.of(tester.element(find.byType(Navigator).first)).routerDelegate.currentConfiguration.uri.toString();

void _go(WidgetTester tester, String location) {
  GoRouter.of(tester.element(find.byType(Navigator).first)).go(location);
}

Future<void> _settle(WidgetTester tester) async {
  try {
    await tester.pumpAndSettle(const Duration(milliseconds: 100), EnginePhase.sendSemanticsUpdate, const Duration(seconds: 10));
  } on FlutterError {
    // still animating
  }
}

Future<void> _waitFor(WidgetTester tester, Finder finder, {Duration timeout = const Duration(seconds: 30)}) async {
  final end = DateTime.now().add(timeout);
  while (DateTime.now().isBefore(end)) {
    await tester.pump(const Duration(milliseconds: 200));
    if (finder.evaluate().isNotEmpty) {
      await _settle(tester);
      return;
    }
  }
  throw TestFailure('Timed out waiting for $finder');
}

Future<void> _until(WidgetTester tester, Object Function() condition, String what,
    {Duration timeout = const Duration(seconds: 60)}) async {
  final end = DateTime.now().add(timeout);
  while (DateTime.now().isBefore(end)) {
    final result = condition();
    if (result is Future ? await result as bool : result as bool) return;
    await tester.pump(const Duration(seconds: 1));
  }
  throw TestFailure('Timed out waiting for: $what');
}
