<?php

use totalwebcreations\b2bcommerce\enums\CompanyRole;

it('lets only admins manage the team', function () {
    expect(CompanyRole::Admin->canManageTeam())->toBeTrue()
        ->and(CompanyRole::Purchaser->canManageTeam())->toBeFalse()
        ->and(CompanyRole::Approver->canManageTeam())->toBeFalse();
});

it('lets admins and approvers approve orders', function () {
    expect(CompanyRole::Admin->canApproveOrders())->toBeTrue()
        ->and(CompanyRole::Approver->canApproveOrders())->toBeTrue()
        ->and(CompanyRole::Purchaser->canApproveOrders())->toBeFalse();
});
