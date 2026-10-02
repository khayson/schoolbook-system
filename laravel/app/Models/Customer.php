<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\GhanaRegion;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code',
    'name',
    'type',
    'region',
    'district',
    'address',
    'contact_person',
    'phone',
    'email',
    'credit_limit',
    'notes',
    'is_active',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'region' => GhanaRegion::class,
            'credit_limit' => 'integer',
            'credit_balance' => 'integer',
            'outstanding_balance' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
