import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/features/reference/presentation/quick_create_controller.dart';

import '../../support/fakes.dart';

void main() {
  late RecordingProductsRepository products;
  late PendingSubmissionStore pending;
  final book = testBook(id: 41);

  QuickCreateController controller() => QuickCreateController(
    products: products,
    pendingStore: pending,
    book: book,
  );

  setUp(() {
    products = RecordingProductsRepository();
    pending = PendingSubmissionStore(store: InMemoryKeyValueStore());
  });

  test(
    'payload: only what the list does not give, money already in pesewas',
    () {
      expect(
        QuickCreateController.buildPayload(
          book: book,
          costPesewas: 2750,
          pricePesewas: 104000,
          variant: "  Learner's Book ",
        ),
        {
          'reference_book_id': 41,
          'variant_label': "Learner's Book",
          'cost_price': 2750,
          'selling_price': 104000,
        },
      );
      expect(
        QuickCreateController.buildPayload(
          book: book,
          costPesewas: 1,
          pricePesewas: 2,
          levelId: 7,
          languageId: 3,
          openingStock: 12,
          variant: '',
        ),
        {
          'reference_book_id': 41,
          'level_id': 7,
          'language_id': 3,
          'cost_price': 1,
          'selling_price': 2,
          'opening_stock': 12,
        },
      );
    },
  );

  test(
    'saves with an idempotency key, then attaches the scanned code',
    () async {
      final c = controller();
      final payload = QuickCreateController.buildPayload(
        book: book,
        costPesewas: 2750,
        pricePesewas: 4000,
      );

      final product = await c.submit(payload, code: ' 9789988012342 ');

      expect(product, isNotNull);
      expect(products.created.single.$1, payload);
      expect(products.created.single.$2, isNotEmpty);
      expect(products.attached.single, ('9789988012342', product!.id));
      expect(c.codeWarning, isNull);
      expect(await pending.pending(c.intent), isNull); // done: key forgotten
    },
  );

  test(
    'a lost connection keeps the key: the retry sends the same one',
    () async {
      final c = controller();
      final payload = QuickCreateController.buildPayload(
        book: book,
        costPesewas: 2750,
        pricePesewas: 4000,
      );
      products.createError = ApiException.network();

      expect(await c.submit(payload), isNull);
      expect(c.error!.isNetworkError, isTrue);
      final firstKey = products.created.single.$2;
      expect((await pending.pending(c.intent))?.idempotencyKey, firstKey);

      products.createError = null;
      expect(await c.submit(payload), isNotNull);
      expect(products.created.last.$2, firstKey);
    },
  );

  test('a definitive error forgets the key and exposes field errors', () async {
    final c = controller();
    products.createError = ApiException(
      message: 'The given data was invalid.',
      statusCode: 422,
      code: 'validation_failed',
      fieldErrors: {
        'level_id': [
          'This approved title is listed for a range of classes, not one level. Choose the level.',
        ],
      },
    );

    expect(
      await c.submit(
        QuickCreateController.buildPayload(
          book: book,
          costPesewas: 1,
          pricePesewas: 2,
        ),
      ),
      isNull,
    );
    expect(
      c.error!.fieldError('level_id'),
      startsWith('This approved title is listed for a range'),
    );
    expect(await pending.pending(c.intent), isNull);
  });

  test('a code that belongs to another product: the product is still saved, with a warning', () async {
    final c = controller();
    products.attachError = ApiException(
      message: 'Code 5012345678900 already belongs to Other Book (BK-000009).',
      statusCode: 409,
      code: 'duplicate_code',
    );

    final product = await c.submit(
      QuickCreateController.buildPayload(
        book: book,
        costPesewas: 1,
        pricePesewas: 2,
      ),
      code: '5012345678900',
    );

    expect(product, isNotNull);
    expect(c.error, isNull);
    expect(
      c.codeWarning,
      'Product saved, but the code was not attached: Code 5012345678900 already belongs to Other Book (BK-000009).',
    );
  });
}
