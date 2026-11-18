<?php

use App\Models\AccountingPeriodClosure;
use App\Models\Expense;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * Phase One-off 1: who may see, add and change one-off expenses, and the
 * existing accounting-period closing (AccountingPeriodClosure).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
    $this->accountant = User::factory()->accountant()->create();
    $this->staff = User::factory()->create();
    $this->otherStaff = User::factory()->create();
});

function closeOctober(): void
{
    AccountingPeriodClosure::close('2026-10', test()->owner);
}

test('Owner and Accountant view every expense, Staff only their own', function () {
    $mine = Expense::factory()->ownedBy($this->staff)->create();
    $theirs = Expense::factory()->ownedBy($this->otherStaff)->create();

    expect($this->owner->can('view', $theirs))->toBeTrue()
        ->and($this->accountant->can('view', $theirs))->toBeTrue()
        ->and($this->staff->can('view', $mine))->toBeTrue()
        ->and($this->staff->can('view', $theirs))->toBeFalse()
        ->and($this->staff->can('viewAny', Expense::class))->toBeTrue();
});

test('Owner, Accountant and Staff may all add expenses', function () {
    expect($this->owner->can('create', Expense::class))->toBeTrue()
        ->and($this->staff->can('create', Expense::class))->toBeTrue()
        ->and($this->accountant->can('create', Expense::class))->toBeTrue();
});

test('Staff change only their own expenses, Owner and Accountant any', function () {
    $mine = Expense::factory()->ownedBy($this->staff)->create();
    $theirs = Expense::factory()->ownedBy($this->otherStaff)->create();

    expect($this->staff->can('update', $mine))->toBeTrue()
        ->and($this->staff->can('update', $theirs))->toBeFalse()
        ->and($this->owner->can('update', $theirs))->toBeTrue()
        ->and($this->accountant->can('update', $theirs))->toBeTrue();
});

test('nobody changes an expense paid in a closed accounting month', function () {
    $staffInOct = Expense::factory()->ownedBy($this->staff)->paidOn(CarbonImmutable::parse('2026-10-20'))->create();
    $staffInNov = Expense::factory()->ownedBy($this->staff)->paidOn(CarbonImmutable::parse('2026-11-05'))->create();

    closeOctober();

    expect($staffInOct->isInClosedPeriod())->toBeTrue()
        ->and($this->staff->can('update', $staffInOct))->toBeFalse()
        ->and($this->owner->can('update', $staffInOct))->toBeFalse()
        ->and($this->staff->can('view', $staffInOct))->toBeTrue()
        ->and($this->staff->can('update', $staffInNov))->toBeTrue();
});

test('reopening the month lets it be changed again', function () {
    $expense = Expense::factory()->ownedBy($this->staff)->paidOn(CarbonImmutable::parse('2026-10-20'))->create();

    closeOctober();
    expect($this->staff->can('update', $expense))->toBeFalse();

    AccountingPeriodClosure::reopen('2026-10', $this->owner, 'แก้ไขรายการที่ลืมบันทึก');

    expect($this->staff->can('update', $expense))->toBeTrue();
});

test('a deactivated account can do nothing with expenses', function () {
    $staff = User::factory()->deactivated()->create();
    $expense = Expense::factory()->ownedBy($staff)->create();

    expect($staff->can('viewAny', Expense::class))->toBeFalse()
        ->and($staff->can('view', $expense))->toBeFalse()
        ->and($staff->can('create', Expense::class))->toBeFalse()
        ->and($staff->can('update', $expense))->toBeFalse();
});
