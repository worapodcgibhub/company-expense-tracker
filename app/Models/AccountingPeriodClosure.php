<?php

namespace App\Models;

use App\Support\ChargeOverview;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One close or reopen of an accounting month (append-only log; never edited
 * or deleted). The latest row of a period decides whether it is closed.
 *
 * Nothing in the app closes or reopens a period on its own: only close()
 * and reopen(), called by a person, write rows here. Charges recorded or
 * changed in a closed month are flagged instead (entered_after_close).
 *
 * `snapshot` (on "closed" rows) keeps the company-wide figures of the month
 * at the moment it was closed — see ChargeOverview::snapshotFor().
 *
 * @property int $id
 * @property string $period "YYYY-MM"
 * @property string $action "closed" | "reopened"
 * @property string|null $reason
 * @property array{thb_total: int, charge_count: int, missing_thb_count: int, missing_thb_usd: int}|null $snapshot
 * @property int|null $acted_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $actor
 */
class AccountingPeriodClosure extends Model
{
    public const CLOSED = 'closed';

    public const REOPENED = 'reopened';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    /**
     * "2026-10" for any date in October 2026.
     */
    public static function periodOf(DateTimeInterface $date): string
    {
        return $date->format('Y-m');
    }

    public static function isValidPeriod(string $period): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1;
    }

    /**
     * First day of the period.
     */
    public static function startOf(string $period): CarbonImmutable
    {
        if (! self::isValidPeriod($period)) {
            throw new LogicException("Invalid accounting period [{$period}].");
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $period.'-01') ?: throw new LogicException("Invalid accounting period [{$period}].");
    }

    /**
     * The latest row of each of $periods (or of every period when null).
     *
     * @param  list<string>|null  $periods
     * @return array<string, AccountingPeriodClosure>
     */
    public static function latestFor(?array $periods = null): array
    {
        $latestIds = self::query()
            ->when($periods !== null, fn ($query) => $query->whereIn('period', $periods))
            ->selectRaw('max(id) as id')
            ->groupBy('period')
            ->pluck('id');

        return self::query()
            ->with('actor')
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('period')
            ->all();
    }

    /**
     * Which of $periods are closed right now.
     *
     * @param  list<string>  $periods
     * @return array<string, AccountingPeriodClosure> the closing row, keyed by period
     */
    public static function closedAmong(array $periods): array
    {
        return array_filter(
            self::latestFor(array_values(array_unique($periods))),
            fn (AccountingPeriodClosure $row) => $row->action === self::CLOSED,
        );
    }

    public static function isClosed(string $period): bool
    {
        return self::closedAmong([$period]) !== [];
    }

    /**
     * A month can be closed once it has ended.
     */
    public static function canBeClosed(string $period, ?DateTimeInterface $today = null): bool
    {
        $currentMonth = CarbonImmutable::instance($today ?? now())->startOfMonth();

        return self::isValidPeriod($period) && self::startOf($period)->lessThan($currentMonth);
    }

    public static function close(string $period, User $by, ?string $reason = null): self
    {
        if (! self::canBeClosed($period)) {
            throw new LogicException("Period [{$period}] has not ended yet.");
        }

        return DB::transaction(function () use ($period, $by, $reason) {
            if (self::isClosed($period)) {
                throw new LogicException("Period [{$period}] is already closed.");
            }

            $row = new self;
            $row->forceFill([
                'period' => $period,
                'action' => self::CLOSED,
                'reason' => self::clean($reason),
                'snapshot' => ChargeOverview::snapshotFor($period),
                'acted_by' => $by->id,
            ])->save();

            return $row;
        });
    }

    public static function reopen(string $period, User $by, string $reason): self
    {
        if (self::clean($reason) === null) {
            throw new LogicException('A reason is required to reopen a period.');
        }

        return DB::transaction(function () use ($period, $by, $reason) {
            if (! self::isClosed($period)) {
                throw new LogicException("Period [{$period}] is not closed.");
            }

            $row = new self;
            $row->forceFill([
                'period' => $period,
                'action' => self::REOPENED,
                'reason' => self::clean($reason),
                'snapshot' => null,
                'acted_by' => $by->id,
            ])->save();

            return $row;
        });
    }

    public function isClosing(): bool
    {
        return $this->action === self::CLOSED;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    private static function clean(?string $text): ?string
    {
        return $text === null || trim($text) === '' ? null : trim($text);
    }
}
