import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/reference/data/reference_cache_store.dart';
import 'package:schoolbook/features/reference/domain/reference_book.dart';
import 'package:schoolbook/features/reference/presentation/reference_catalog.dart';

import '../../support/fakes.dart';

void main() {
  late FakeReferenceRepository api;
  late InMemoryReferenceCacheStore cache;

  ReferenceCatalog catalog() => ReferenceCatalog(
        repository: api,
        cache: cache,
        clock: () => DateTime.utc(2026, 10, 5, 9),
      );

  setUp(() {
    api = FakeReferenceRepository(books: [
      testBook(id: 1),
      testBook(id: 2, title: 'Discover Science', subject: 'Science', isbn: '9789988012342'),
    ]);
    api.stock = {2: const TitleStock(productsCount: 2, stockOnHand: 15)};
    cache = InMemoryReferenceCacheStore();
  });

  test('first sync downloads the list and the stock, and keeps both on the device', () async {
    final c = catalog();
    await c.sync();

    expect(api.sentEtags, [null]);
    expect(c.books.map((b) => b.id), [1, 2]);
    expect(c.editionLabel, 'NaCCA Test Edition');
    expect(c.etag, '"v1"');
    expect(c.stockFor(2)?.stockOnHand, 15);
    expect(c.stockFor(1), isNull);
    expect(c.offline, isFalse);
    expect(cache.value, contains('"etag":"\\"v1\\""'));
  });

  test('later syncs send the ETag: unchanged lists are not downloaded again, changed ones are', () async {
    await catalog().sync();

    final next = catalog(); // e.g. after an app restart
    await next.sync();
    expect(api.sentEtags, [null, '"v1"']);
    expect(api.downloads, 1);
    expect(next.books, hasLength(2)); // from the stored copy

    api
      ..books = [...api.books, testBook(id: 3, title: 'Ocean Science')]
      ..etag = '"v2"';
    await next.sync();
    expect(api.downloads, 2);
    expect(next.etag, '"v2"');
    expect(next.search('ocean').single.id, 3);
  });

  test('offline: the stored copy is searched and the screen can say it is offline', () async {
    await catalog().sync();
    api.failWith = ApiException.network();

    final offline = catalog();
    await offline.sync();

    expect(offline.offline, isTrue);
    expect(offline.error, isNull);
    expect(offline.search('discover').single.id, 2);
    expect(offline.stockFor(2)?.productsCount, 2);
    expect(offline.syncedAt, DateTime.utc(2026, 10, 5, 9));
  });

  test('offline with nothing stored: empty, not an error', () async {
    api.failWith = ApiException.network();
    final c = catalog();
    await c.sync();

    expect(c.isEmpty, isTrue);
    expect(c.offline, isTrue);
  });

  test('a server error is reported; a corrupt stored copy is ignored', () async {
    cache.value = '{not json';
    api.failWith = ApiException(message: 'Server error', statusCode: 500);
    final c = catalog();
    await c.sync();

    expect(c.isEmpty, isTrue);
    expect(c.offline, isFalse);
    expect(c.error, 'Server error');
  });

  test('ISBN hint and local stock update after adding a product', () async {
    final c = catalog();
    await c.sync();

    expect(c.byIsbn('978-9988-0-1234-2')?.id, 2);
    expect(c.byIsbn('0000'), isNull);

    c.noteProductAdded(1, 12);
    expect(c.stockFor(1)?.productsCount, 1);
    expect(c.stockFor(1)?.stockOnHand, 12);
  });
}
