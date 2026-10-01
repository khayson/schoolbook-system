<?php

namespace Database\Factories;

use App\Models\Language;
use App\Models\Level;
use App\Models\LevelGroup;
use App\Models\Product;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $catalog = $this->catalogIds();

        return [
            'sku' => fake()->unique()->bothify('SKU-####-??'),
            'isbn' => fake()->optional(0.7)->isbn13(),
            'barcode' => fake()->optional(0.5)->ean13(),
            'title' => fake()->sentence(4),
            'level_id' => $catalog['level_id'],
            'subject_id' => $catalog['subject_id'],
            'language_id' => $catalog['language_id'],
            'publisher_id' => null,
            'edition' => fake()->optional()->word(),
            'cost_price' => fake()->numberBetween(500, 50000),
            'selling_price' => fake()->numberBetween(1000, 80000),
            'reorder_level' => 0,
            'stock_on_hand' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Allow factories/tests to seed stock_on_hand; production mass-assignment still blocks it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return Product::unguarded(fn () => parent::create($attributes, $parent));
    }

    public function lowStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'reorder_level' => 10,
            'stock_on_hand' => 5,
        ]);
    }

    /**
     * @return array{level_id: int, subject_id: int, language_id: int}
     */
    private function catalogIds(): array
    {
        $group = LevelGroup::query()->firstOrCreate(
            ['slug' => 'primary'],
            ['name' => 'Primary', 'sort_order' => 3],
        );

        $level = Level::query()->firstOrCreate(
            ['slug' => 'primary-1-factory'],
            [
                'level_group_id' => $group->id,
                'name' => 'Primary 1',
                'sort_order' => 1,
            ],
        );

        $subject = Subject::query()->firstOrCreate(
            ['slug' => 'mathematics-factory'],
            ['name' => 'Mathematics', 'is_active' => true],
        );

        $language = Language::query()->firstOrCreate(
            ['code' => 'en-factory'],
            ['name' => 'English', 'is_active' => true],
        );

        return [
            'level_id' => $level->id,
            'subject_id' => $subject->id,
            'language_id' => $language->id,
        ];
    }
}
