<?php

use App\Http\Controllers\ChargeReceiptController;
use App\Models\AccountingPeriodClosure;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
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

    // Owner and Accountant may view; only the Owner manages (BankAccountPolicy).
    Route::livewire('bank-accounts', 'pages::bank-accounts.index')
        ->middleware('can:viewAny,'.BankAccount::class)
        ->name('bank-accounts.index');

    // Owner and Accountant may view; only the Owner manages (PaymentMethodPolicy).
    Route::livewire('payment-methods', 'pages::payment-methods.index')
        ->middleware('can:viewAny,'.PaymentMethod::class)
        ->name('payment-methods.index');

    // SubscriptionPolicy: everyone opens the list (Staff see only their own),
    // only the Owner adds or edits. `create` must stay above `{subscription}`.
    Route::livewire('subscriptions', 'pages::subscriptions.index')
        ->middleware('can:viewAny,'.Subscription::class)
        ->name('subscriptions.index');
    Route::livewire('subscriptions/create', 'pages::subscriptions.form')
        ->middleware('can:create,'.Subscription::class)
        ->name('subscriptions.create');
    Route::livewire('subscriptions/{subscription}', 'pages::subscriptions.show')
        ->middleware('can:view,subscription')
        ->whereNumber('subscription')
        ->name('subscriptions.show');
    Route::livewire('subscriptions/{subscription}/edit', 'pages::subscriptions.form')
        ->middleware('can:update,subscription')
        ->whereNumber('subscription')
        ->name('subscriptions.edit');

    // Actual charges (SubscriptionChargePolicy): everyone opens the list
    // (Staff see only their own subscriptions' charges); only the Owner
    // records or corrects. Recording is authorized again in the page,
    // because it also needs the subscription to be active.
    Route::livewire('subscriptions/{subscription}/charges/create', 'pages::charges.form')
        ->middleware('can:view,subscription')
        ->whereNumber('subscription')
        ->name('charges.create');
    Route::livewire('charges', 'pages::charges.index')
        ->middleware('can:viewAny,'.SubscriptionCharge::class)
        ->name('charges.index');
    Route::livewire('charges/{charge}', 'pages::charges.show')
        ->middleware('can:view,charge')
        ->whereNumber('charge')
        ->name('charges.show');
    Route::livewire('charges/{charge}/edit', 'pages::charges.form')
        ->middleware('can:update,charge')
        ->whereNumber('charge')
        ->name('charges.edit');

    // One-off expenses (ExpensePolicy): everyone adds; Staff see and change
    // only their own, nobody changes one in a closed accounting month.
    // `create` must stay above `{expense}`.
    Route::livewire('expenses', 'pages::expenses.index')
        ->middleware('can:viewAny,'.Expense::class)
        ->name('expenses.index');
    Route::livewire('expenses/create', 'pages::expenses.form')
        ->middleware('can:create,'.Expense::class)
        ->name('expenses.create');
    Route::livewire('expenses/{expense}/edit', 'pages::expenses.form')
        ->middleware('can:update,expense')
        ->whereNumber('expense')
        ->name('expenses.edit');

    // Document files (private disk). The only way to open them: checks
    // ChargeReceiptPolicy::view (same people who may view the charge).
    Route::get('receipts/{receipt}', [ChargeReceiptController::class, 'show'])
        ->middleware('can:view,receipt')
        ->whereNumber('receipt')
        ->name('receipts.show');

    // Owner and Accountant (AccountingPeriodClosurePolicy).
    Route::livewire('accounting-periods', 'pages::accounting-periods.index')
        ->middleware('can:viewAny,'.AccountingPeriodClosure::class)
        ->name('accounting-periods.index');
});

require __DIR__.'/settings.php';
