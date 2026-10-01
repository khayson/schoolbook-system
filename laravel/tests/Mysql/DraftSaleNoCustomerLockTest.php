<?php

uses()->group('mysql');

use App\Actions\Sales\CreateDraftSale;
use App\Actions\Sales\UpdateDraftSale;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Drafts no longer take lockForUpdate on the customer. Note the InnoDB limit:
 * inserting a sales row still takes a SHARED lock on the parent customers row
 * for the foreign-key check. Shared locks do not conflict with each other, so
 * concurrent drafts for one customer no longer serialize, but a new draft still
 * waits (briefly) behind a payment/confirm that holds the customer row exclusively.
 */

function lockHolder(): PDO
{
    $config = config('database.connections.mysql_testing');

    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

test('drafts for the same customer do not serialize on the customer row', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['selling_price' => 1000]);

    // Another in-flight draft for this customer holds a shared lock (its FK check).
    $holder = lockHolder();
    $holder->beginTransaction();
    $holder->prepare('SELECT id FROM customers WHERE id = ? LOCK IN SHARE MODE')->execute([$customer->id]);

    DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        $sale = app(CreateDraftSale::class)->execute($user, [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);
    } finally {
        $holder->rollBack();
    }

    expect($sale->total)->toBe(2000);
});

test('editing an existing draft does not wait on an exclusively locked customer', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['selling_price' => 1000]);
    $sale = app(CreateDraftSale::class)->execute($user, [
        'customer_id' => $customer->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ]);

    // Simulates a payment holding the customer lock.
    $holder = lockHolder();
    $holder->beginTransaction();
    $holder->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE')->execute([$customer->id]);

    DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        $updated = app(UpdateDraftSale::class)->execute($user, $sale, [
            'notes' => 'Deliver Friday',
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ]);
    } finally {
        $holder->rollBack();
    }

    expect($updated->total)->toBe(4000);
});

test('documented limit: a new draft waits behind an exclusive customer lock via the FK check', function () {
    $user = User::factory()->owner()->create();
    $customer = Customer::factory()->create();
    $product = Product::factory()->create();

    $holder = lockHolder();
    $holder->beginTransaction();
    $holder->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE')->execute([$customer->id]);

    DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 1');

    $caught = null;
    try {
        app(CreateDraftSale::class)->execute($user, [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    } finally {
        $holder->rollBack();
    }

    expect((int) ($caught?->errorInfo[1] ?? 0))->toBe(1205);
});
