<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Books',
            'contact_person' => fake()->name(),
            'phone' => '0'.fake()->numerify('#########'),
            'email' => fake()->unique()->safeEmail(),
            'notes' => null,
        ];
    }
}
