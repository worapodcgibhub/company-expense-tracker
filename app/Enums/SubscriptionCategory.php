<?php

namespace App\Enums;

/**
 * Fixed default categories (no category management screen yet).
 */
enum SubscriptionCategory: string
{
    case Software = 'software';
    case CloudHosting = 'cloud_hosting';
    case Marketing = 'marketing';
    case Communication = 'communication';
    case Design = 'design';
    case Productivity = 'productivity';
    case Finance = 'finance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Software => 'Software',
            self::CloudHosting => 'Cloud/Hosting',
            self::Marketing => 'Marketing/โฆษณา',
            self::Communication => 'การสื่อสาร',
            self::Design => 'Design',
            self::Productivity => 'Productivity',
            self::Finance => 'การเงิน/บัญชี',
            self::Other => 'อื่น ๆ',
        };
    }
}
