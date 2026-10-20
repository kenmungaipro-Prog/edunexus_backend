<?php

use Illuminate\Support\Facades\Route;

// Serve the compiled React Router SPA for direct browser navigations.
$serveSpa = function (?string $any = null) {
    return response()->file(public_path('index.html'));
};

Route::get('/', $serveSpa);
Route::get('/login', $serveSpa)->name('login');
Route::get('/register', $serveSpa)->name('register');
Route::get('/{any}', $serveSpa)->where('any', '(?!api(?:/|$)).*');