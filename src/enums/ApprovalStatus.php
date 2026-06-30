<?php

namespace totalwebcreations\b2bcommerce\enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';

    public function canTransitionTo(self $target): bool
    {
        if ($target === $this) {
            return false;
        }

        return match ($this) {
            self::Pending => in_array($target, [self::Approved, self::Declined], true),
            self::Approved, self::Declined => false,
        };
    }
}
