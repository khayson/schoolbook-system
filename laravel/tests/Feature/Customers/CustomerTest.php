<?php

use App\Actions\Customers\CreateCustomer;
use App\Enums\CustomerType;
use App\Enums\GhanaRegion;
use App\Models\Customer;
use App\Models\User;

test('create customer allocates CUS code from year-0 sequence', function () {
    $first = app(CreateCustomer::class)->execute([
        'name' => 'Accra Primary',
        'type' => CustomerType::School->value,
        'region' => GhanaRegion::GreaterAccra->value,
    ]);

    $second = app(CreateCustomer::class)->execute([
        'name' => 'Kumasi JHS',
        'type' => CustomerType::School->value,
        'region' => GhanaRegion::Ashanti->value,
    ]);

    expect($first->code)->toBe('CUS-0001')
        ->and($second->code)->toBe('CUS-0002')
        ->and($first->credit_balance)->toBe(0);
});

test('owner can crud customers via api', function () {
    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;

    $create = $this->withToken($token)->postJson('/api/v1/customers', [
        'name' => 'Tema School',
        'type' => 'school',
        'region' => 'Greater Accra',
        'phone' => '0244000000',
    ]);

    $create->assertCreated()
        ->assertJsonPath('data.code', 'CUS-0001')
        ->assertJsonPath('data.name', 'Tema School');

    $id = $create->json('data.id');

    $this->withToken($token)
        ->putJson("/api/v1/customers/{$id}", ['name' => 'Tema International'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Tema International');

    $this->withToken($token)
        ->getJson('/api/v1/customers?search=Tema')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withToken($token)
        ->deleteJson("/api/v1/customers/{$id}")
        ->assertOk();

    expect(Customer::withTrashed()->find($id)?->trashed())->toBeTrue();
});
