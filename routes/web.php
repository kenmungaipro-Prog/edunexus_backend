<?php

use Illuminate\Support\Facades\Route;

// Ensure root path serves the SPA index for tests expecting GET /
Route::get('/', function () {
    return response()->file(public_path('index.html'));
});

// Named SPA auth routes for Laravel helpers/middleware.
Route::view('/login', 'app')->name('login');
Route::view('/register', 'app')->name('register');

Route::view('/{any}', 'app')
    ->where('any', '.*');




Route::get('/{any?}', function () {
    return response()->file(public_path('index.html'));
})->where('any', '.*');