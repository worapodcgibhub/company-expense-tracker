<?php

use Illuminate\Support\Facades\Route;

// Internal company app: guests are sent on to the login page by the
// dashboard's `auth` middleware; signed-in users land on the dashboard.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
