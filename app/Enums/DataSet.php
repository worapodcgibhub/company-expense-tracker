<?php

namespace App\Enums;

/**
 * Which data a figure is calculated from: real company data, the sample data
 * kept for trying the system out (subscriptions flagged `is_sample`, with
 * their charges, documents and history), or everything together.
 *
 * Totals and reports default to Real, so sample amounts never mix into real
 * money unless someone asks for them. One-off expenses are never samples.
 */
enum DataSet: string
{
    case Real = 'real';
    case Sample = 'sample';
    case All = 'all';

    /**
     * From a request value ("real", "sample", "all"); anything else is Real.
     */
    public static function fromRequest(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Real) : self::Real;
    }

    public function label(): string
    {
        return match ($this) {
            self::Real => 'ข้อมูลจริง',
            self::Sample => 'ข้อมูลตัวอย่าง',
            self::All => 'ทั้งหมด',
        };
    }

    public function isReal(): bool
    {
        return $this === self::Real;
    }
}
