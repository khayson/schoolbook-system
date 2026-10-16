<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema, bool $isCreate = false): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('phone')
                    ->tel()
                    ->maxLength(255),
                TextInput::make('password')
                    ->label($isCreate ? 'Password' : 'New password')
                    ->password()
                    ->revealable()
                    ->required($isCreate)
                    ->rule(Password::min(8))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText($isCreate
                        ? 'At least 8 characters. Tap the eye icon to show the password while typing.'
                        : 'Leave blank to keep the current password. Tap the eye icon to show what you type.'),
                TextInput::make('password_confirmation')
                    ->label('Confirm password')
                    ->password()
                    ->revealable()
                    ->required(fn (callable $get): bool => $isCreate || filled($get('password')))
                    ->same('password')
                    ->dehydrated(false)
                    ->visible(fn (callable $get): bool => $isCreate || filled($get('password'))),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Inactive users cannot sign in. Pending self-registrations stay off until you turn this on.'),
            ]);
    }
}
