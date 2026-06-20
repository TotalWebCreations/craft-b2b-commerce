<?php

namespace totalwebcreations\b2bcommerce\enums;

enum CompanyStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Blocked = 'blocked';

    public function canTransitionTo(self $target): bool
    {
        if ($target === $this) {
            return false;
        }

        return match ($this) {
            self::Pending => true,
            self::Approved => $target === self::Blocked,
            self::Blocked => $target === self::Approved,
        };
    }
}
