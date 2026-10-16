<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\CreateStaffUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    public function form(Schema $schema): Schema
    {
        return UserForm::configure($schema, isCreate: true);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateStaffUser::class)->execute($data);
    }
}
