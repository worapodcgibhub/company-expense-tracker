<?php

namespace App\Enums;

/**
 * Status inside this app only. Pausing or cancelling here does NOT stop the
 * vendor from charging the card; that must be done with the vendor.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'ใช้งานอยู่',
            self::Paused => 'พักชั่วคราว',
            self::Cancelled => 'ยกเลิกแล้ว',
        };
    }

    /**
     * Tone for <x-ui.status-badge>.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Active => 'emerald',
            self::Paused => 'amber',
            self::Cancelled => 'slate',
        };
    }
}
