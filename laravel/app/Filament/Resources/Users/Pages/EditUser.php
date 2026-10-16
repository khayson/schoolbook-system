<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\UpdateStaffUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public function form(Schema $schema): Schema
    {
        return UserForm::configure($schema, isCreate: false);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        /** @var User $actor */
        $actor = auth()->user();

        return app(UpdateStaffUser::class)->execute($actor, $record, $data);
    }
}
