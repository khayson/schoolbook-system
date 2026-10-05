<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ReportsFixture;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->fx = ReportsFixture::build($this->owner);
    Sanctum::actingAs($this->owner);
});

test('every report endpoint answers with its figures under data', function () {
    $this->getJson('/api/v1/reports/sales-summary?from=2026-06-01&to=2026-06-30&group_by=week')
        ->assertOk()
        ->assertJsonPath('data.totals.revenue', 96000)
        ->assertJsonPath('data.rows.1', ['period' => '2026-06-08', 'sales_count' => 2, 'gross' => 87000, 'order_discounts' => 4000, 'revenue' => 83000, 'collections' => 76000]);

    $this->getJson('/api/v1/reports/profit?from=2026-06-01&to=2026-06-30&group_by=product')
        ->assertOk()
        ->assertJsonPath('data.totals.net_profit', 35500)
        ->assertJsonPath('data.totals.order_level_discounts', 4000);

    $this->getJson('/api/v1/reports/profit?from=2026-01-01&to=2026-06-30&group_by=period&period=month')
        ->assertOk()->assertJsonPath('data.totals.net_profit', 48900)->assertJsonCount(6, 'data.rows');

    $this->getJson('/api/v1/reports/best-sellers?from=2026-06-01&to=2026-06-30&by=level')
        ->assertOk()->assertJsonPath('data.rows.0.label', 'Primary 4')->assertJsonPath('data.rows.0.quantity', 17);

    $this->getJson('/api/v1/reports/stock-valuation')
        ->assertOk()->assertJsonPath('data.totals.value_at_cost', 535400)->assertJsonPath('data.totals.negative_stock_count', 1);

    $this->getJson('/api/v1/reports/low-stock')->assertOk()->assertJsonPath('data.count', 3);

    $this->getJson('/api/v1/reports/dead-stock?as_of=2026-06-30')
        ->assertOk()->assertJsonPath('data.cutoff', '2026-04-01')->assertJsonPath('data.totals.count', 2);

    $this->getJson('/api/v1/reports/receivables-aging?as_of=2026-06-30')
        ->assertOk()->assertJsonPath('data.totals.total', 49000)->assertJsonPath('data.totals.days_90_plus', 12000);

    $this->getJson('/api/v1/reports/dashboard?date=2026-06-15')
        ->assertOk()->assertJsonPath('data.sales_today', ['count' => 2, 'revenue' => 10000])->assertJsonPath('data.overdue', 28000);
});

test('dates default to today in Africa/Accra', function () {
    $this->travelTo(now()->setTimezone('Africa/Accra')->setDate(2026, 6, 30)->setTime(18, 0));

    $this->getJson('/api/v1/reports/dashboard')->assertOk()->assertJsonPath('data.date', '2026-06-30')->assertJsonPath('data.sales_month.revenue', 96000);
    $this->getJson('/api/v1/reports/receivables-aging')->assertOk()->assertJsonPath('data.as_of', '2026-06-30');
    $this->getJson('/api/v1/reports/dead-stock')->assertOk()->assertJsonPath('data.days', 90)->assertJsonPath('data.cutoff', '2026-04-01');
});

test('report parameters are validated', function (string $url, string $field) {
    $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'from missing' => ['/api/v1/reports/sales-summary?to=2026-06-30', 'from'],
    'to before from' => ['/api/v1/reports/sales-summary?from=2026-06-30&to=2026-06-01', 'to'],
    'not a date' => ['/api/v1/reports/sales-summary?from=30/06/2026&to=2026-06-30', 'from'],
    'unknown grouping' => ['/api/v1/reports/sales-summary?from=2026-06-01&to=2026-06-30&group_by=year', 'group_by'],
    'daily over a year' => ['/api/v1/reports/sales-summary?from=2025-01-01&to=2026-06-30&group_by=day', 'to'],
    'over two years' => ['/api/v1/reports/sales-summary?from=2023-01-01&to=2026-06-30&group_by=month', 'to'],
    'unknown customer' => ['/api/v1/reports/sales-summary?from=2026-06-01&to=2026-06-30&customer_id=999999', 'customer_id'],
    'profit needs group_by' => ['/api/v1/reports/profit?from=2026-06-01&to=2026-06-30', 'group_by'],
    'best sellers sort' => ['/api/v1/reports/best-sellers?from=2026-06-01&to=2026-06-30&sort=profit', 'sort'],
    'best sellers limit' => ['/api/v1/reports/best-sellers?from=2026-06-01&to=2026-06-30&limit=500', 'limit'],
    'dead stock days' => ['/api/v1/reports/dead-stock?days=0', 'days'],
    'aging as_of' => ['/api/v1/reports/receivables-aging?as_of=yesterday', 'as_of'],
]);

test('reports are for the owner only', function (string $url) {
    Sanctum::actingAs(User::factory()->create(['role' => 'school']));

    $this->getJson($url)->assertForbidden();
})->with([
    '/api/v1/reports/dashboard',
    '/api/v1/reports/sales-summary?from=2026-06-01&to=2026-06-30',
    '/api/v1/reports/profit?from=2026-06-01&to=2026-06-30&group_by=product',
    '/api/v1/reports/best-sellers?from=2026-06-01&to=2026-06-30',
    '/api/v1/reports/stock-valuation',
    '/api/v1/reports/low-stock',
    '/api/v1/reports/dead-stock',
    '/api/v1/reports/receivables-aging',
]);
