<?php

namespace totalwebcreations\b2bcommerce\enums;

enum CompanyRole: string
{
    case Admin = 'admin';
    case Purchaser = 'purchaser';
    case Approver = 'approver';

    public function canManageTeam(): bool
    {
        return $this === self::Admin;
    }

    public function canApproveOrders(): bool
    {
        return in_array($this, [self::Admin, self::Approver], true);
    }
}
