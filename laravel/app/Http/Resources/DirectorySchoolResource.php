<?php

namespace App\Http\Resources;

use App\Models\DirectorySchool;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DirectorySchool */
class DirectorySchoolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'region' => $this->region,
            'district' => $this->district,
            'town' => $this->town,
            'phone' => $this->phone,
            'levels' => $this->levelList(),
            'level_label' => $this->levelLabel(),
            'ownership' => $this->ownership,
            'customer_id' => $this->customer_id,
            'source_ref' => $this->source_ref,
        ];
    }
}
