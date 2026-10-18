<?php

use App\Filament\Pages\TestPriceRulePage;
use App\Filament\Resources\PriceRules\Pages\CreatePriceRule;
use App\Filament\Resources\PriceRules\Pages\ListPriceRules;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('price rules list and create pages render', function () {
    Livewire::test(ListPriceRules::class)->assertOk();
    Livewire::test(CreatePriceRule::class)->assertOk();
});

test('creating a price rule via filament persists it', function () {
    Livewire::test(CreatePriceRule::class)
        ->fillForm([
            'name' => 'Filament 15%',
            'type' => 'percent_off',
            'value' => 1500,
            'scope_type' => 'all',
            'customer_scope' => 'all',
            'priority' => 5,
            'stackable' => false,
            'floor_policy' => 'warn',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PriceRule::query()->where('name', 'Filament 15%')->exists())->toBeTrue();
});

test('test pricing page previews applied rules', function () {
    $product = Product::factory()->create(['selling_price' => 10000, 'cost_price' => 2000, 'title' => 'Test Book']);
    PriceRule::factory()->percentOff(1000)->create(['name' => 'Preview 10%', 'priority' => 10]);

    Livewire::test(TestPriceRulePage::class)
        ->fillForm([
            'customer_id' => null,
            'sale_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])
        ->call('runPreview')
        ->assertSet('preview.total', 18000)
        ->assertSet('preview.lines.0.applied_rules.0.name', 'Preview 10%');
});
