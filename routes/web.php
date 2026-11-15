<?php

use App\Models\CreditCard;
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

    // Owner and Accountant may view; only the Owner manages (CreditCardPolicy).
    Route::livewire('credit-cards', 'pages::credit-cards.index')
        ->middleware('can:viewAny,'.CreditCard::class)
        ->name('credit-cards.index');
});

require __DIR__.'/settings.php';
