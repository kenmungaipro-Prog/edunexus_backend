<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('testing environment uses isolated database', function () {
    expect(config('database.connections.mysql.database'))
        ->toBe('edunexus_test');

    expect(config('app.env'))
        ->toBe('testing');

    expect(DB::connection()->getDatabaseName())
        ->toBe('edunexus_test');
});