import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';

void main() {
  const intent = 'record_payment.customer.1';
  final payload = {'customer_id': 1, 'amount': 50000, 'method': 'momo', 'reference': 'MP-1'};

  test('the same payload gets the same key until completed', () async {
    final store = PendingSubmissionStore(store: InMemoryKeyValueStore());

    final first = await store.keyFor(intent, payload);
    final again = await store.keyFor(intent, Map.of(payload));

    expect(again, first);
    expect(first, matches(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')));

    await store.complete(intent);
    expect(await store.pending(intent), isNull);
    expect(await store.keyFor(intent, payload), isNot(first));
  });

  test('changing the form gives a new key', () async {
    final store = PendingSubmissionStore(store: InMemoryKeyValueStore());

    final first = await store.keyFor(intent, payload);
    final changed = await store.keyFor(intent, {...payload, 'amount': 50001});

    expect(changed, isNot(first));
    expect((await store.pending(intent))!.payload['amount'], 50001);
  });

  test('key order in the payload does not matter', () {
    expect(
      PendingSubmissionStore.payloadHash({'a': 1, 'b': {'y': 2, 'x': 1}}),
      PendingSubmissionStore.payloadHash({'b': {'x': 1, 'y': 2}, 'a': 1}),
    );
    expect(
      PendingSubmissionStore.payloadHash({'a': 1}),
      isNot(PendingSubmissionStore.payloadHash({'a': 2})),
    );
  });

  test('survives an app restart (a new store over the same storage)', () async {
    final backing = InMemoryKeyValueStore();
    final before = PendingSubmissionStore(store: backing);
    final key = await before.keyFor(intent, payload, now: DateTime(2026, 10, 2, 9, 30));

    // App killed; started again.
    final after = PendingSubmissionStore(store: backing);
    final unfinished = await after.pending(intent);

    expect(unfinished!.idempotencyKey, key);
    expect(unfinished.payload, payload);
    expect(unfinished.startedAt, DateTime(2026, 10, 2, 9, 30));
    expect(await after.keyFor(intent, payload), key);
  });

  test('intents are independent', () async {
    final store = PendingSubmissionStore(store: InMemoryKeyValueStore());
    final a = await store.keyFor('record_payment.customer.1', payload);
    final b = await store.keyFor('record_payment.customer.2', payload);

    expect(a, isNot(b));
  });

  test('corrupt stored data is discarded, not crashed on', () async {
    final backing = InMemoryKeyValueStore()..values['pending_submission.$intent'] = '{not json';
    final store = PendingSubmissionStore(store: backing);

    expect(await store.pending(intent), isNull);
    expect(backing.values, isEmpty);
  });
}
