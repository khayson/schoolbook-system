<?php

namespace Database\Seeders;

use App\Actions\Inventory\ReceiveStock;
use App\Models\Language;
use App\Models\Level;
use App\Models\Product;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Catalog + opening stock for the Phase 2 acceptance run (docs/acceptance-phase2.md).
 * Not called by DatabaseSeeder. Run on a throwaway database only:
 *   php artisan db:seed --class=AcceptanceSeeder
 * Stock comes in through ReceiveStock, so the ledger is real.
 */
class AcceptanceSeeder extends Seeder
{
    public const BOOKS = [
        ['sku' => 'ACC-ENG-P4', 'title' => 'Acceptance English Reader P4', 'price' => 2500, 'cost' => 1500, 'stock' => 200],
        ['sku' => 'ACC-MTH-P4', 'title' => 'Acceptance Mathematics P4', 'price' => 4000, 'cost' => 2600, 'stock' => 100],
        ['sku' => 'ACC-SCI-P4', 'title' => 'Acceptance Science P4', 'price' => 1500, 'cost' => 900, 'stock' => 100],
    ];

    public function run(): void
    {
        $owner = User::query()->where('role', 'owner')->firstOrFail();
        $level = Level::query()->where('name', 'Primary 4')->first() ?? Level::query()->firstOrFail();
        $subject = Subject::query()->firstOrFail();
        $language = Language::query()->where('name', 'English')->first() ?? Language::query()->firstOrFail();

        $items = [];
        foreach (self::BOOKS as $book) {
            $product = Product::query()->updateOrCreate(['sku' => $book['sku']], [
                'title' => $book['title'],
                'level_id' => $level->id,
                'subject_id' => $subject->id,
                'language_id' => $language->id,
                'cost_price' => $book['cost'],
                'selling_price' => $book['price'],
                'reorder_level' => 10,
                'is_active' => true,
            ]);
            $items[] = ['product_id' => $product->id, 'quantity' => $book['stock'], 'unit_cost' => $book['cost']];
        }

        app(ReceiveStock::class)->execute($owner, [
            'received_at' => now(),
            'supplier_reference' => 'ACCEPTANCE-OPENING',
            'items' => $items,
        ]);
    }
}
