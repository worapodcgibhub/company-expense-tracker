<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

// Internal company app: guests are sent on to the login page by the
// dashboard's `auth` middleware; signed-in users land on the dashboard.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Owner only (UserPolicy). Each action inside the page is authorized again.
    Route::livewire('users', 'pages::users.index')
        ->middleware('can:viewAny,'.User::class)
        ->name('users.index');
});

require __DIR__.'/settings.php';
