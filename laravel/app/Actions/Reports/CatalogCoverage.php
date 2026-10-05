<?php

namespace App\Actions\Reports;

use App\Models\Level;
use App\Models\Product;
use App\Models\ReferenceBook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How much of the approved list the shop carries.
 *
 * - "In my products": an approved title with at least one product (not deleted) linked
 *   to it, whatever its stock. "Missing": approved, no product.
 * - Grouped by level (Primary 4, ...); titles listed only for a band are grouped under the
 *   band ("Lower Primary"), and readers / guidance / e-learning under their category.
 * - "Not on the list": active products with no approved title, or whose title was withdrawn.
 */
class CatalogCoverage
{
    private const BAND_LABELS = [
        'kg' => 'Creche/Nursery/Kindergarten',
        'lower_primary' => 'Lower Primary',
        'upper_primary' => 'Upper Primary',
        'jhs' => 'Junior High School',
        'shs' => 'Senior High School',
    ];

    /**
     * @return array{
     *     totals: array{approved: int, in_products: int, missing: int, not_on_list: int},
     *     levels: list<array{group: string, approved: int, in_products: int, missing: int}>,
     *     subjects: list<array{group: string, subject: string, approved: int, in_products: int, missing: int}>
     * }
     */
    public function summary(): array
    {
        $stocked = DB::table('products')->select('reference_book_id')
            ->whereNotNull('reference_book_id')->whereNull('deleted_at')->distinct();

        $rows = DB::table('reference_books as b')
            ->leftJoinSub($stocked, 'p', 'p.reference_book_id', '=', 'b.id')
            ->leftJoin('subjects as s', 's.id', '=', 'b.subject_id')
            ->where('b.status', 'approved')
            ->groupBy('b.level_id', 'b.band', 'b.category', 's.name', 'b.subject_label')
            ->selectRaw('b.level_id, b.band, b.category, s.name as subject_name, b.subject_label,
                count(*) as approved, count(p.reference_book_id) as in_products')
            ->get();

        $levels = Level::query()->with('levelGroup')->get()->sortBy([
            fn ($a, $b) => ($a->levelGroup?->sort_order ?? 0) <=> ($b->levelGroup?->sort_order ?? 0),
            fn ($a, $b) => $a->sort_order <=> $b->sort_order,
        ])->values();
        $levelOrder = $levels->pluck('id')->flip()->all();
        $levelName = $levels->pluck('name', 'id')->all();
        $groupOf = function ($row) use ($levelName, $levelOrder): array {
            if ($row->level_id !== null) {
                return [$levelName[$row->level_id] ?? '?', $levelOrder[$row->level_id] ?? 999];
            }
            if ($row->band !== null) {
                return [self::BAND_LABELS[$row->band] ?? $row->band, 1000 + array_search($row->band, array_keys(self::BAND_LABELS), true)];
            }

            return [ReferenceBook::categoryLabel($row->category), 2000 + (int) array_search($row->category, ReferenceBook::CATEGORIES, true)];
        };

        $add = function (array &$bucket, string $key, int $order, string $group, string $subject, object $row): void {
            $bucket[$key] ??= ['order' => $order, 'group' => $group, 'subject' => $subject, 'approved' => 0, 'in_products' => 0];
            $bucket[$key]['approved'] += (int) $row->approved;
            $bucket[$key]['in_products'] += (int) $row->in_products;
        };

        $byLevel = [];
        $bySubject = [];
        foreach ($rows as $row) {
            [$group, $order] = $groupOf($row);
            $subject = $row->subject_name ?? $row->subject_label ?? '(no subject)';
            $add($byLevel, $group, $order, $group, $subject, $row);
            $add($bySubject, $group.'|'.$subject, $order, $group, $subject, $row);
        }

        $finish = fn (array $bucket, bool $withSubject): array => collect($bucket)
            ->sortBy([['order', 'asc'], ['subject', 'asc']])
            ->map(fn (array $r) => array_filter([
                'group' => $r['group'],
                'subject' => $withSubject ? $r['subject'] : null,
                'approved' => $r['approved'],
                'in_products' => $r['in_products'],
                'missing' => $r['approved'] - $r['in_products'],
            ], fn ($v) => $v !== null))
            ->values()->all();

        $approved = (int) $rows->sum('approved');
        $inProducts = (int) $rows->sum('in_products');

        return [
            'totals' => [
                'approved' => $approved,
                'in_products' => $inProducts,
                'missing' => $approved - $inProducts,
                'not_on_list' => $this->notOnList()->count(),
            ],
            'levels' => $finish($byLevel, false),
            'subjects' => $finish($bySubject, true),
        ];
    }

    /** Approved titles with no product. */
    public function missing(): Builder
    {
        return ReferenceBook::query()->approved()->doesntHave('products');
    }

    /** Active products without an approved title (none linked, or the title was withdrawn). */
    public function notOnList(): Builder
    {
        return Product::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('reference_book_id')
                ->orWhereHas('referenceBook', fn (Builder $b) => $b->where('status', '!=', 'approved')));
    }

    public static function bandLabel(?string $band): ?string
    {
        return $band === null ? null : (self::BAND_LABELS[$band] ?? $band);
    }
}
