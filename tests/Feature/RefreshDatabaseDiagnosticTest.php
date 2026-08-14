<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

test('refresh database diagnostic', function () {
    expect(trait_exists(RefreshDatabase::class))->toBeTrue();
})->uses(RefreshDatabase::class);
