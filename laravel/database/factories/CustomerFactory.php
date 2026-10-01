<?php

namespace Database\Factories;

use App\Enums\CustomerType;
use App\Enums\GhanaRegion;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'code' => 'CUS-'.fake()->unique()->numerify('####'),
            'name' => fake()->company().' School',
            'type' => CustomerType::School,
            'region' => GhanaRegion::GreaterAccra,
            'district' => 'Accra Metro',
            'address' => fake()->address(),
            'contact_person' => fake()->name(),
            'phone' => '0'.fake()->numerify('#########'),
            'email' => fake()->unique()->safeEmail(),
            'credit_limit' => 500000,
            'credit_balance' => 0,
            'notes' => null,
            'is_active' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Customer::unguarded(fn () => parent::create($attributes, $parent));
    }
}
