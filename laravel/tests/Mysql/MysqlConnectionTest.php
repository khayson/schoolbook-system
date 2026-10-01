<?php

uses()->group('mysql');

test('mysql testing connection uses schoolbook_test', function () {
    expect(config('database.default'))->toBe('mysql_testing');
    expect(config('database.connections.mysql_testing.database'))->toBe('schoolbook_test');
    expect(DB::connection()->getDriverName())->toBe('mysql');
    expect((int) DB::selectOne('select 1 as ok')->ok)->toBe(1);
});

test('mysql supports row locking', function () {
    DB::statement('DROP TABLE IF EXISTS mysql_lock_probe');
    DB::statement('CREATE TABLE mysql_lock_probe (id BIGINT UNSIGNED PRIMARY KEY, value INT NOT NULL)');
    DB::table('mysql_lock_probe')->insert(['id' => 1, 'value' => 0]);

    DB::transaction(function () {
        $row = DB::table('mysql_lock_probe')->where('id', 1)->lockForUpdate()->first();
        expect($row)->not->toBeNull();
        DB::table('mysql_lock_probe')->where('id', 1)->update(['value' => $row->value + 1]);
    });

    expect((int) DB::table('mysql_lock_probe')->where('id', 1)->value('value'))->toBe(1);

    DB::statement('DROP TABLE IF EXISTS mysql_lock_probe');
});
