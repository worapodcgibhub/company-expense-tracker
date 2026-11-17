<?php

namespace App\Console\Commands;

use App\Models\AccountingPeriodClosure;
use App\Models\ChargeReceipt;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionChargeRevision;
use App\Support\ChargeOverview;
use Illuminate\Console\Command;
use JsonException;

/**
 * Read-only check of the ledger (what was really paid, month by month).
 *
 * Safety notes:
 * - Never writes to the database. The only thing it can write is the small
 *   reference file asked for with --save, and only to the backups folder
 *   (ignored by Git); it never overwrites an existing reference file.
 * - Uses the same code as the dashboard and the period closing, so the
 *   figures are exactly the ones the app shows.
 * - Typical use around a database change:
 *     php artisan app:ledger-check --save=before
 *     (back up, then change the database)
 *     php artisan app:ledger-check --compare=before
 *
 * @phpstan-type Totals array{thb_total: int, charge_count: int, missing_thb_count: int, missing_thb_usd: int}
 * @phpstan-type Report array{version: int, counts: array<string, int>, months: array<string, Totals>, closed: array<string, Totals>}
 */
class LedgerCheck extends Command
{
    private const VERSION = 1;

    private const NAME_PATTERN = '/^[A-Za-z0-9_-]{1,40}$/';

    /**
     * @var string
     */
    protected $signature = 'app:ledger-check
        {--save= : บันทึกยอดปัจจุบันเป็นไฟล์อ้างอิงชื่อนี้ (เช่น before)}
        {--compare= : เทียบยอดปัจจุบันกับไฟล์อ้างอิงชื่อนี้}
        {--dir= : โฟลเดอร์ไฟล์อ้างอิง (ค่าเริ่มต้น storage/app/backups)}';

    /**
     * @var string
     */
    protected $description = 'ตรวจยอดค่าใช้จ่ายจริงรายเดือนและ Snapshot งวดบัญชี (อ่านอย่างเดียว ไม่แก้ฐานข้อมูล)';

    public function handle(): int
    {
        $save = $this->option('save');
        $compare = $this->option('compare');

        if ($save !== null && $compare !== null) {
            $this->error('ใช้ --save และ --compare พร้อมกันไม่ได้');

            return self::FAILURE;
        }

        foreach (['--save' => $save, '--compare' => $compare] as $option => $name) {
            if ($name !== null && preg_match(self::NAME_PATTERN, $name) !== 1) {
                $this->error("ชื่อของ {$option} ใช้ได้เฉพาะ a-z A-Z 0-9 _ - ความยาวไม่เกิน 40 ตัวอักษร");

                return self::FAILURE;
            }
        }

        $report = $this->buildReport();
        $ok = $this->show($report);

        if (is_string($save)) {
            return $this->saveReference($report, $save) && $ok ? self::SUCCESS : self::FAILURE;
        }

        if (is_string($compare)) {
            return $this->compareWithReference($report, $compare) && $ok ? self::SUCCESS : self::FAILURE;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Report
     */
    private function buildReport(): array
    {
        $charged = SubscriptionCharge::query()->charged()->get();
        $byMonth = $charged->groupBy(fn (SubscriptionCharge $charge) => (string) $charge->period())->sortKeys();

        $months = [];
        foreach ($byMonth as $period => $charges) {
            if ($period !== '') {
                $months[(string) $period] = ChargeOverview::totals($charges);
            }
        }

        $closed = [];
        foreach (AccountingPeriodClosure::latestFor() as $period => $row) {
            if ($row->isClosing() && $row->snapshot !== null) {
                $closed[$period] = $row->snapshot;
            }
        }
        ksort($closed);

        return [
            'version' => self::VERSION,
            'counts' => [
                'charges' => SubscriptionCharge::query()->count(),
                'charged' => $charged->count(),
                'charged_without_date' => $charged->filter(fn (SubscriptionCharge $charge) => $charge->period() === null)->count(),
                'charged_on_with_time' => SubscriptionCharge::query()->whereNotNull('charged_on')->whereRaw('length(charged_on) <> 10')->count(),
                'receipts' => ChargeReceipt::query()->count(),
                'revisions' => SubscriptionChargeRevision::query()->count(),
                'closing_log_rows' => AccountingPeriodClosure::query()->count(),
            ],
            'months' => $months,
            'closed' => $closed,
        ];
    }

    /**
     * Prints the report and the checks; false when something is wrong.
     *
     * @param  Report  $report
     */
    private function show(array $report): bool
    {
        $this->info('ยอดค่าใช้จ่ายจริงรายเดือน (นับเฉพาะรายการที่ตัดเงินแล้ว ตามวันที่ตัดเงิน)');

        $rows = [];
        foreach ($report['months'] as $period => $totals) {
            $rows[] = [
                $period,
                $totals['charge_count'],
                $this->baht($totals['thb_total']),
                $totals['missing_thb_count'] === 0 ? '-' : $totals['missing_thb_count'].' รายการ (USD '.$this->baht($totals['missing_thb_usd']).')',
                isset($report['closed'][$period]) ? 'ปิดงวดแล้ว' : 'เปิดอยู่',
            ];
        }
        $this->table(['งวด', 'รายการ', 'ยอดรวม (บาท)', 'รอยอดบาท', 'สถานะ'], $rows);

        $this->line('จำนวนข้อมูล: '.implode(', ', array_map(fn (string $name, int $count) => "{$name}={$count}", array_keys($report['counts']), $report['counts'])));

        $ok = true;

        if ($report['counts']['charged_without_date'] > 0) {
            $this->error('ผิดปกติ: มีรายการที่ตัดเงินแล้วแต่ไม่มีวันที่ตัดเงิน '.$report['counts']['charged_without_date'].' รายการ');
            $ok = false;
        }

        if ($report['counts']['charged_on_with_time'] > 0) {
            $this->warn('หมายเหตุ: มีวันที่ตัดเงิน '.$report['counts']['charged_on_with_time'].' รายการที่เก็บแบบมีเวลาปน (รายงานรองรับแล้ว ไม่กระทบยอด)');
        }

        // The report queries (used by the dashboard and the closing) must
        // find exactly the charges each month owns by its own date.
        foreach ($report['months'] as $period => $totals) {
            if (ChargeOverview::snapshotFor($period) != $totals) {
                $this->error("ผิดปกติ: งวด {$period} ยอดที่รายงานคำนวณได้ไม่ตรงกับยอดตามวันที่ตัดเงินจริง (อาจมีรายการตกหล่นจากหน้าจอ)");
                $ok = false;
            }
        }

        foreach ($report['closed'] as $period => $snapshot) {
            $current = $report['months'][$period] ?? ['thb_total' => 0, 'charge_count' => 0, 'missing_thb_count' => 0, 'missing_thb_usd' => 0];

            if ($current == $snapshot) {
                continue;
            }

            $explained = $this->lateChanges($period);

            if ($explained > 0) {
                $this->warn("งวด {$period}: ยอดปัจจุบันต่างจาก Snapshot วันปิดงวด (มีรายการแก้ไขย้อนหลังที่บันทึกไว้ {$explained} รายการ) ให้ผู้ทำบัญชีตรวจสอบ");
            } else {
                $this->error("ผิดปกติ: งวด {$period} ต่างจาก Snapshot วันปิดงวด โดยไม่มีรายการย้อนหลังที่บันทึกไว้");
                $ok = false;
            }
        }

        if ($ok) {
            $this->info('ไม่พบความผิดปกติ (คำสั่งนี้ไม่ได้แก้ไขฐานข้อมูล)');
        }

        return $ok;
    }

    /**
     * Charges flagged as entered after closing in the period, plus changes
     * made to closed periods since it was closed.
     */
    private function lateChanges(string $period): int
    {
        $closedAt = AccountingPeriodClosure::latestFor([$period])[$period]->created_at;

        $flagged = SubscriptionCharge::query()
            ->where('entered_after_close', true)
            ->chargedInMonth(AccountingPeriodClosure::startOf($period))
            ->count();

        $revisions = SubscriptionChargeRevision::query()
            ->where('in_closed_period', true)
            ->when($closedAt !== null, fn ($query) => $query->where('created_at', '>=', $closedAt))
            ->count();

        return $flagged + $revisions;
    }

    /**
     * @param  Report  $report
     */
    private function saveReference(array $report, string $name): bool
    {
        $dir = $this->directory();
        $path = $dir.DIRECTORY_SEPARATOR."ledger-{$name}.json";

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error("สร้างโฟลเดอร์ไม่ได้: {$dir}");

            return false;
        }

        if (file_exists($path)) {
            $this->error("มีไฟล์อ้างอิงชื่อ {$name} อยู่แล้ว ไม่เขียนทับ (ใช้ชื่ออื่น หรือลบไฟล์เองหากแน่ใจ): {$path}");

            return false;
        }

        $json = json_encode(['created_at' => now()->toIso8601String()] + $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || file_put_contents($path, $json) === false) {
            $this->error('เขียนไฟล์อ้างอิงไม่สำเร็จ');

            return false;
        }

        @chmod($path, 0600);
        $this->info("บันทึกไฟล์อ้างอิงแล้ว: {$path}");

        return true;
    }

    /**
     * @param  Report  $report
     */
    private function compareWithReference(array $report, string $name): bool
    {
        $path = $this->directory().DIRECTORY_SEPARATOR."ledger-{$name}.json";

        if (! is_file($path)) {
            $this->error("ไม่พบไฟล์อ้างอิง: {$path}");

            return false;
        }

        try {
            /** @var mixed $before */
            $before = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $before = null;
        }

        if (! is_array($before) || ($before['version'] ?? null) !== self::VERSION) {
            $this->error('ไฟล์อ้างอิงอ่านไม่ได้หรือเป็นรุ่นที่ไม่รองรับ');

            return false;
        }

        $differences = [];

        foreach (['counts', 'months', 'closed'] as $section) {
            $old = is_array($before[$section] ?? null) ? $before[$section] : [];
            $new = $report[$section];

            foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
                if (($old[$key] ?? null) != ($new[$key] ?? null)) {
                    $differences[] = "{$section}.{$key}: ก่อน=".json_encode($old[$key] ?? null).' ตอนนี้='.json_encode($new[$key] ?? null);
                }
            }
        }

        if ($differences === []) {
            $this->info("ตรงกับไฟล์อ้างอิง {$name} ทุกรายการ");

            return true;
        }

        $this->error("ต่างจากไฟล์อ้างอิง {$name} ".count($differences).' จุด (ถ้ามีการใช้งานระบบระหว่างนั้น ความต่างอาจเกิดจากข้อมูลใหม่ที่ถูกบันทึก):');

        foreach ($differences as $difference) {
            $this->line('  - '.$difference);
        }

        return false;
    }

    private function directory(): string
    {
        return rtrim((string) ($this->option('dir') ?: storage_path('app/backups')), '/\\');
    }

    private function baht(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2);
    }
}
