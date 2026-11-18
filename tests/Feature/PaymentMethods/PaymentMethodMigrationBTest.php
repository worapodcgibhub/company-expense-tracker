<?php

use App\Models\ChargeReceipt;
use App\Models\CreditCard;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Support\ChargeOverview;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Phase 3.1b: credit_card_id becomes nullable and payment_method_id gets its
// foreign key. SQLite rebuilds both tables, so the round trip (migrate ->
// rollback -> migrate) is tested on a throw-away SQLite FILE, never on the
// project database. Everything else uses the normal in-memory test database.

const MIGRATION_B = '2026_11_21_000000_make_card_optional_and_link_payment_method';

/** Point the default connection at a new, empty temporary SQLite file. */
function useTemporaryDatabase(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'migration-b-'.bin2hex(random_bytes(6)).'.sqlite';
    touch($path);

    config(['database.connections.migb' => array_merge(config('database.connections.sqlite'), ['database' => $path, 'url' => null])]);
    DB::purge('migb');
    DB::setDefaultConnection('migb');

    return $path;
}

function dropTemporaryDatabase(string $path): void
{
    DB::setDefaultConnection('sqlite');
    DB::purge('migb');
    @unlink($path);
}

function runMigrations(string $command, array $options = []): void
{
    Artisan::call($command, array_merge(['--database' => 'migb', '--force' => true], $options));
}

/**
 * How many migrations must be rolled back to undo B: B itself plus every
 * migration added after it (later phases), so this keeps working when new
 * migrations are added.
 */
function stepsBackToBeforeB(): int
{
    $names = collect(glob(database_path('migrations/*.php')))->map(fn ($file) => basename($file))->sort()->values();
    $b = $names->search(fn ($name) => str_contains($name, 'make_card_optional_and_link_payment_method'));

    return $names->count() - $b;
}

/** A fresh temporary database with every migration run, then B (and anything after it) rolled back (the state after Phase 3.1a). */
function databaseAtPhase31a(): void
{
    runMigrations('migrate:fresh');
    runMigrations('migrate:rollback', ['--step' => stepsBackToBeforeB()]);
}

/**
 * @return array{columns: array, indexes: array, fks: array}
 */
function tableStructure(string $table): array
{
    $db = DB::connection('migb');

    return [
        // `is_sample` belongs to a migration after B: rolling back to before B removes it first, so it is not part of B's comparison.
        'columns' => collect($db->select("pragma table_info('{$table}')"))
            ->reject(fn ($c) => $c->name === 'is_sample')
            ->values()
            ->map(fn ($c) => [$c->name, $c->type, (int) $c->notnull, $c->dflt_value, (int) $c->pk])->all(),
        'indexes' => collect($db->select("pragma index_list('{$table}')"))
            ->map(fn ($i) => [$i->name, (int) $i->unique])->sortBy(0)->values()->all(),
        'fks' => collect($db->select("pragma foreign_key_list('{$table}')"))
            ->map(fn ($f) => [$f->from, $f->table, $f->to, $f->on_delete])->sortBy(0)->values()->all(),
    ];
}

/**
 * Every row of every table that matters, plus the id counters and a month total.
 *
 * @return array{rows: array<string, array>, sequence: array<string, int>, month: array}
 */
function dataSnapshot(): array
{
    $db = DB::connection('migb');
    $rows = [];

    foreach (['users', 'credit_cards', 'payment_methods', 'subscriptions', 'subscription_charges', 'charge_receipts', 'subscription_charge_revisions', 'subscription_status_changes', 'accounting_period_closures'] as $table) {
        $rows[$table] = $db->table($table)->orderBy('id')->get()->map(fn ($r) => Arr::except((array) $r, ['is_sample']))->all();
    }

    return [
        'rows' => $rows,
        'sequence' => $db->table('sqlite_sequence')->whereIn('name', ['subscriptions', 'subscription_charges'])->orderBy('name')->pluck('seq', 'name')->all(),
        // Computed without the data-set filter: `is_sample` does not exist in the rolled-back states this test compares.
        'month' => ChargeOverview::totals(SubscriptionCharge::query()->chargedInMonth(CarbonImmutable::parse('2026-11-01'))->get()),
    ];
}

/** Subscription + 3 charges + receipts + revisions + status change + a closed month. */
function seedBusinessData(): void
{
    $owner = User::factory()->owner()->create();
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-12-14'))->create(['responsible_user_id' => $owner->id]);

    $charges = SubscriptionCharge::factory()->count(3)
        ->sequence(
            ['due_date' => '2026-09-14', 'charged_on' => '2026-09-14', 'amount' => 59_000],
            ['due_date' => '2026-10-14', 'charged_on' => '2026-10-14', 'amount' => 60_000],
            ['due_date' => '2026-11-14', 'charged_on' => '2026-11-14', 'amount' => 61_000],
        )
        ->for($subscription)
        ->create(['recorded_by' => $owner->id]);

    ChargeReceipt::factory()->count(2)->create(['subscription_charge_id' => $charges[0]->id, 'uploaded_by' => $owner->id]);
    ChargeReceipt::factory()->create(['subscription_charge_id' => $charges[2]->id, 'uploaded_by' => $owner->id]);

    foreach ($charges as $charge) {
        DB::table('subscription_charge_revisions')->insert([
            'subscription_charge_id' => $charge->id,
            'action' => 'recorded',
            'diff' => null,
            'reason' => null,
            'in_closed_period' => false,
            'changed_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('subscription_status_changes')->insert([
        'subscription_id' => $subscription->id,
        'from_status' => null,
        'to_status' => 'active',
        'reason' => null,
        'changed_by' => $owner->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('accounting_period_closures')->insert([
        'period' => '2026-09',
        'action' => 'closed',
        'reason' => null,
        'snapshot' => json_encode(['thb_total' => 59_000, 'charge_count' => 1, 'missing_thb_count' => 0, 'missing_thb_usd' => 0]),
        'acted_by' => $owner->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function rawSubscription(array $overrides = []): Subscription
{
    $subscription = new Subscription;
    $subscription->forceFill(Subscription::factory()->raw($overrides))->save();

    return $subscription;
}

test('migrate, rollback and migrate again keep every row, id, index and relation', function () {
    $path = useTemporaryDatabase();

    try {
        databaseAtPhase31a();
        seedBusinessData();

        // A row from before the hook existed: it has a card but no payment method yet.
        DB::table('subscription_charges')->where('id', 1)->update(['payment_method_id' => null]);

        $subsA = tableStructure('subscriptions');
        $chargesA = tableStructure('subscription_charges');
        $dataA = dataSnapshot();
        $methodId = $dataA['rows']['payment_methods'][0]['id'];

        expect($dataA['rows']['subscription_charges'])->toHaveCount(3)
            ->and($dataA['rows']['charge_receipts'])->toHaveCount(3)
            ->and($dataA['rows']['subscription_charge_revisions'])->toHaveCount(3)
            ->and($dataA['rows']['subscription_charges'][0]['payment_method_id'])->toBeNull();

        // What B is expected to change: only that one empty link, filled from the existing card method.
        $expected = $dataA;
        $expected['rows']['subscription_charges'][0]['payment_method_id'] = $methodId;

        $expectedStructure = function (array $before): array {
            $after = $before;
            $after['columns'] = array_map(
                fn (array $c) => $c[0] === 'credit_card_id' ? [$c[0], $c[1], 0, $c[3], $c[4]] : $c,
                $before['columns'],
            );
            $after['fks'] = collect($before['fks'])
                ->push(['payment_method_id', 'payment_methods', 'id', 'RESTRICT'])
                ->sortBy(0)->values()->all();

            return $after;
        };

        // 1) migrate
        runMigrations('migrate');
        expect(tableStructure('subscriptions'))->toEqual($expectedStructure($subsA))
            ->and(tableStructure('subscription_charges'))->toEqual($expectedStructure($chargesA))
            ->and(dataSnapshot())->toEqual($expected)
            ->and(DB::connection('migb')->select('pragma foreign_key_check'))->toBe([])
            ->and(DB::connection('migb')->scalar('pragma integrity_check'))->toBe('ok')
            ->and((int) DB::connection('migb')->scalar('pragma foreign_keys'))->toBe(1);

        // The unique cycle rule and AUTOINCREMENT must survive the rebuild.
        expect(collect($chargesA['indexes'])->contains(fn ($i) => str_contains($i[0], 'subscription_id_due_date_unique') && $i[1] === 1))->toBeTrue();
        expect(DB::connection('migb')->scalar("select sql from sqlite_master where name = 'subscription_charges'"))->toContain('autoincrement');

        // 2) rollback: structure is exactly the Phase 3.1a one again, data unchanged
        runMigrations('migrate:rollback', ['--step' => stepsBackToBeforeB()]);
        expect(tableStructure('subscriptions'))->toEqual($subsA)
            ->and(tableStructure('subscription_charges'))->toEqual($chargesA)
            ->and(dataSnapshot())->toEqual($expected)
            ->and(DB::connection('migb')->select('pragma foreign_key_check'))->toBe([])
            ->and((int) DB::connection('migb')->scalar('pragma foreign_keys'))->toBe(1);

        // 3) migrate again: same result as the first time
        runMigrations('migrate');
        expect(tableStructure('subscriptions'))->toEqual($expectedStructure($subsA))
            ->and(tableStructure('subscription_charges'))->toEqual($expectedStructure($chargesA))
            ->and(dataSnapshot())->toEqual($expected);

        // New rows still get the next id after the old ones.
        $next = SubscriptionCharge::factory()->for(Subscription::first())->create(['due_date' => '2026-12-14', 'charged_on' => '2026-12-14']);
        expect($next->id)->toBe(4);
    } finally {
        dropTemporaryDatabase($path);
    }
});

test('migration B stops without changing anything when a card has no payment method', function () {
    $path = useTemporaryDatabase();

    try {
        databaseAtPhase31a();
        seedBusinessData();

        // Legacy-looking data whose card has no payment method (never created automatically).
        DB::table('subscriptions')->update(['payment_method_id' => null]);
        DB::table('subscription_charges')->update(['payment_method_id' => null]);
        DB::table('payment_methods')->delete();

        $structure = [tableStructure('subscriptions'), tableStructure('subscription_charges')];
        $data = dataSnapshot();

        expect(fn () => runMigrations('migrate'))->toThrow(RuntimeException::class, 'หยุด Migration');

        expect([tableStructure('subscriptions'), tableStructure('subscription_charges')])->toEqual($structure)
            ->and(dataSnapshot())->toEqual($data)
            ->and(DB::connection('migb')->table('payment_methods')->count())->toBe(0)
            ->and((int) DB::connection('migb')->scalar('pragma foreign_keys'))->toBe(1);
    } finally {
        dropTemporaryDatabase($path);
    }
});

test('rollback refuses to run while a row has no card and leaves the data alone', function () {
    $path = useTemporaryDatabase();

    try {
        runMigrations('migrate:fresh');
        seedBusinessData();

        $cash = PaymentMethod::factory()->cash()->create();
        rawSubscription(['credit_card_id' => null, 'payment_method_id' => $cash->id]);

        $structure = [tableStructure('subscriptions'), tableStructure('subscription_charges')];
        $data = dataSnapshot();

        expect(fn () => runMigrations('migrate:rollback', ['--step' => stepsBackToBeforeB()]))
            ->toThrow(RuntimeException::class, 'ย้อนกลับไม่ได้');

        expect([tableStructure('subscriptions'), tableStructure('subscription_charges')])->toEqual($structure)
            ->and(dataSnapshot())->toEqual($data)
            ->and(DB::connection('migb')->table('migrations')->where('migration', MIGRATION_B)->exists())->toBeTrue();
    } finally {
        dropTemporaryDatabase($path);
    }
});

test('after migration B a card is optional on subscriptions and charges', function () {
    foreach (['subscriptions', 'subscription_charges'] as $table) {
        $columns = collect(DB::select("pragma table_info('{$table}')"))->keyBy('name');

        expect((int) $columns['credit_card_id']->notnull)->toBe(0)
            ->and((int) $columns['payment_method_id']->notnull)->toBe(0);

        $fks = collect(DB::select("pragma foreign_key_list('{$table}')"))->keyBy('from');

        expect($fks['payment_method_id']->table)->toBe('payment_methods')
            ->and($fks['payment_method_id']->on_delete)->toBe('RESTRICT')
            ->and($fks['credit_card_id']->table)->toBe('credit_cards')
            ->and($fks['credit_card_id']->on_delete)->toBe('RESTRICT');
    }

    expect(DB::select('pragma foreign_key_check'))->toBe([]);
});

test('a payment method id that does not exist is rejected by the database', function () {
    expect(fn () => rawSubscription(['credit_card_id' => null, 'payment_method_id' => 999_999]))
        ->toThrow(QueryException::class);
});

test('a payment method that is in use cannot be deleted', function () {
    $subscription = Subscription::factory()->create();

    expect(fn () => DB::table('payment_methods')->where('id', $subscription->payment_method_id)->delete())
        ->toThrow(QueryException::class);
});

test('a cycle still cannot be recorded twice for the same subscription', function () {
    $subscription = Subscription::factory()->create();
    SubscriptionCharge::factory()->for($subscription)->create(['due_date' => '2026-11-14', 'charged_on' => '2026-11-14']);

    expect(fn () => SubscriptionCharge::factory()->for($subscription)->create(['due_date' => '2026-11-14', 'charged_on' => '2026-11-14']))
        ->toThrow(QueryException::class);
});

test('a charge that has a receipt cannot be deleted', function () {
    $charge = SubscriptionCharge::factory()->create();
    ChargeReceipt::factory()->create(['subscription_charge_id' => $charge->id]);

    expect(fn () => DB::table('subscription_charges')->where('id', $charge->id)->delete())
        ->toThrow(QueryException::class);
});

test('existing card based data is unchanged by the new structure', function () {
    $card = CreditCard::factory()->create();
    $subscription = Subscription::factory()->chargingOn(CarbonImmutable::parse('2026-11-14'))->create(['credit_card_id' => $card->id]);
    $charge = SubscriptionCharge::factory()->for($subscription)->create();

    expect($subscription->fresh()->creditCard->is($card))->toBeTrue()
        ->and($charge->fresh()->creditCard->is($card))->toBeTrue()
        ->and($charge->fresh()->paymentMethod->is($card->paymentMethod))->toBeTrue();
});
