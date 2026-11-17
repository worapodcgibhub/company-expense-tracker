<?php

use App\Models\AccountingPeriodClosure;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Bangkok'));
    $this->owner = User::factory()->owner()->create();
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'expense-ledger-test-'.bin2hex(random_bytes(6));
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function ledgerCheckCharge(string $chargedOn, int $amount): SubscriptionCharge
{
    $charge = SubscriptionCharge::factory()
        ->for(Subscription::factory()->create())
        ->chargedOn(CarbonImmutable::parse($chargedOn))
        ->create(['amount' => $amount]);

    // Plain YYYY-MM-DD (the factory adds a time; both formats must count).
    DB::table('subscription_charges')->where('id', $charge->id)->update(['charged_on' => $chargedOn]);

    return $charge->refresh();
}

function everyTableAsJson(): string
{
    $tables = collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name"))->pluck('name');

    return $tables->map(fn (string $table) => $table.':'.DB::table($table)->orderBy('id')->get()->toJson())->implode("\n");
}

test('it shows the monthly figures and never changes any data', function () {
    ledgerCheckCharge('2026-10-05', 40_000);
    ledgerCheckCharge('2026-11-02', 15_050);
    AccountingPeriodClosure::close('2026-10', $this->owner);
    $before = everyTableAsJson();

    $this->artisan('app:ledger-check')
        ->expectsTable(['งวด', 'รายการ', 'ยอดรวม (บาท)', 'รอยอดบาท', 'สถานะ'], [
            ['2026-10', 1, '400.00', '-', 'ปิดงวดแล้ว'],
            ['2026-11', 1, '150.50', '-', 'เปิดอยู่'],
        ])
        ->expectsOutputToContain('ไม่พบความผิดปกติ')
        ->assertSuccessful();

    expect(everyTableAsJson())->toBe($before);
});

test('late entries in a closed month are a warning, not an error', function () {
    ledgerCheckCharge('2026-10-05', 40_000);
    AccountingPeriodClosure::close('2026-10', $this->owner);
    ledgerCheckCharge('2026-10-20', 5_000)->forceFill(['entered_after_close' => true])->save();

    $this->artisan('app:ledger-check')
        ->expectsOutputToContain('ต่างจาก Snapshot วันปิดงวด (มีรายการแก้ไขย้อนหลังที่บันทึกไว้ 1 รายการ)')
        ->assertSuccessful();
});

test('a closed month that changed without any recorded late entry is an error', function () {
    ledgerCheckCharge('2026-10-05', 40_000);
    AccountingPeriodClosure::close('2026-10', $this->owner);
    // Added straight into the closed month without the late-entry mark.
    ledgerCheckCharge('2026-10-20', 5_000);

    $this->artisan('app:ledger-check')
        ->expectsOutputToContain('ผิดปกติ: งวด 2026-10')
        ->assertFailed();
});

test('a charged row without a charge date is reported', function () {
    ledgerCheckCharge('2026-10-05', 40_000)->forceFill(['charged_on' => null])->save();

    $this->artisan('app:ledger-check')
        ->expectsOutputToContain('ไม่มีวันที่ตัดเงิน 1 รายการ')
        ->assertFailed();
});

test('month-end charges stored with a time are counted, and the time format is only a note', function () {
    // Stored as "2026-10-31 00:00:00", like the real data.
    SubscriptionCharge::factory()->chargedOn(CarbonImmutable::parse('2026-10-31'))->create(['amount' => 12_300]);

    $this->artisan('app:ledger-check')
        ->expectsTable(['งวด', 'รายการ', 'ยอดรวม (บาท)', 'รอยอดบาท', 'สถานะ'], [
            ['2026-10', 1, '123.00', '-', 'เปิดอยู่'],
        ])
        ->expectsOutputToContain('เก็บแบบมีเวลาปน (รายงานรองรับแล้ว')
        ->doesntExpectOutputToContain('ยอดที่รายงานคำนวณได้ไม่ตรง')
        ->assertSuccessful();
});

test('a saved reference matches until the figures change', function () {
    ledgerCheckCharge('2026-10-05', 40_000);
    AccountingPeriodClosure::close('2026-10', $this->owner);

    $this->artisan('app:ledger-check', ['--save' => 'before', '--dir' => $this->dir])->assertSuccessful();
    expect(File::exists($this->dir.DIRECTORY_SEPARATOR.'ledger-before.json'))->toBeTrue();

    $this->artisan('app:ledger-check', ['--compare' => 'before', '--dir' => $this->dir])
        ->expectsOutputToContain('ตรงกับไฟล์อ้างอิง before ทุกรายการ')
        ->assertSuccessful();

    ledgerCheckCharge('2026-11-03', 9_900);

    $this->artisan('app:ledger-check', ['--compare' => 'before', '--dir' => $this->dir])
        ->expectsOutputToContain('ต่างจากไฟล์อ้างอิง before')
        ->expectsOutputToContain('months.2026-11')
        ->assertFailed();
});

test('it never overwrites a saved reference and rejects bad option use', function () {
    $this->artisan('app:ledger-check', ['--save' => 'before', '--dir' => $this->dir])->assertSuccessful();
    $file = $this->dir.DIRECTORY_SEPARATOR.'ledger-before.json';
    $content = File::get($file);

    ledgerCheckCharge('2026-11-03', 9_900);

    $this->artisan('app:ledger-check', ['--save' => 'before', '--dir' => $this->dir])
        ->expectsOutputToContain('ไม่เขียนทับ')
        ->assertFailed();
    expect(File::get($file))->toBe($content);

    $this->artisan('app:ledger-check', ['--save' => '../escape', '--dir' => $this->dir])->assertFailed();
    $this->artisan('app:ledger-check', ['--save' => 'a', '--compare' => 'b', '--dir' => $this->dir])->assertFailed();
    $this->artisan('app:ledger-check', ['--compare' => 'missing', '--dir' => $this->dir])
        ->expectsOutputToContain('ไม่พบไฟล์อ้างอิง')
        ->assertFailed();

    expect(File::files($this->dir))->toHaveCount(1);
});
