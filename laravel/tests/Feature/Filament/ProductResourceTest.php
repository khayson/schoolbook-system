<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

test('owner can render product index in filament', function () {
    $user = User::factory()->owner()->create();

    $this->actingAs($user);

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListProducts::class)
        ->assertSuccessful();
});

test('school user cannot access filament panel', function () {
    $user = User::factory()->school()->create();

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});
