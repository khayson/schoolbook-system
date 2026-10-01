<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| MySQL group — real locking / sequences
|--------------------------------------------------------------------------
|
| Excluded from the default phpunit run. Execute with:
|   php artisan test --group=mysql
|
| Requires schoolbook_test (DB_TEST_DATABASE) with the same DB_* credentials.
|
*/

pest()->extend(TestCase::class)
    ->beforeEach(function () {
        config(['database.default' => 'mysql_testing']);
        $this->artisan('migrate:fresh');
    })
    ->group('mysql')
    ->in('Mysql');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
