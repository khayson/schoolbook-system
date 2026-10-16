<?php

use App\Actions\Users\CreateStaffUser;
use App\Actions\Users\UpdateStaffUser;
use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create([
        'email' => 'boss@schoolbook.test',
        'is_active' => true,
    ]);
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

test('owner can list and create a staff user from Filament', function () {
    Livewire::test(ListUsers::class)->assertSuccessful();

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Cashier Ama',
            'email' => 'ama@schoolbook.test',
            'phone' => '0244000999',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $ama = User::query()->where('email', 'ama@schoolbook.test')->sole();

    expect($ama->name)->toBe('Cashier Ama')
        ->and($ama->role)->toBe(UserRole::Owner)
        ->and($ama->is_active)->toBeTrue()
        ->and(Hash::check('password123', $ama->password))->toBeTrue();
});

test('owner can deactivate another user but not themselves', function () {
    $other = app(CreateStaffUser::class)->execute([
        'name' => 'Other',
        'email' => 'other@schoolbook.test',
        'password' => 'password123',
        'is_active' => true,
    ]);

    Livewire::test(EditUser::class, ['record' => $other->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($other->refresh()->is_active)->toBeFalse();

    expect(fn () => app(UpdateStaffUser::class)->execute($this->owner, $this->owner, [
        'is_active' => false,
    ]))->toThrow(ValidationException::class);

    expect($this->owner->refresh()->is_active)->toBeTrue();
});

test('update action refuses deactivating the last active owner', function () {
    $inactive = User::factory()->owner()->create([
        'email' => 'dormant@schoolbook.test',
        'is_active' => false,
    ]);

    expect(fn () => app(UpdateStaffUser::class)->execute($inactive, $this->owner, [
        'is_active' => false,
    ]))->toThrow(RuntimeException::class);
});
