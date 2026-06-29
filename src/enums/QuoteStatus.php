<?php

namespace totalwebcreations\b2bcommerce\enums;

enum QuoteStatus: string
{
    case Requested = 'requested';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';

    public function canTransitionTo(self $target): bool
    {
        if ($target === $this) {
            return false;
        }

        return match ($this) {
            self::Requested => in_array($target, [self::Sent, self::Declined, self::Expired], true),
            self::Sent => in_array($target, [self::Accepted, self::Declined, self::Expired], true),
            self::Accepted, self::Declined, self::Expired => false,
        };
    }
}
