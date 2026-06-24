<?php

namespace totalwebcreations\b2bcommerce\console\controllers;

use craft\console\Controller;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\console\ExitCode;

/**
 * Recovery commands for company teams.
 *
 * This lives on the console side only. The web team flows guard against
 * leaving a company without an admin; the console is the deliberate escape
 * hatch for an operator to repair a company that ended up orphaned.
 */
class TeamController extends Controller
{
    /**
     * Assigns (or re-assigns) a company role to a user, looked up by email.
     *
     * Unlike the web flows this bypasses the last-admin guard by design: the
     * console is the recovery path for a company that lost its admin, so an
     * operator must be able to reinstate one from the command line.
     */
    public function actionAssignRole(int $companyId, string $email, string $role): int
    {
        $companyRole = CompanyRole::tryFrom($role);

        if ($companyRole === null) {
            $allowed = implode(', ', array_column(CompanyRole::cases(), 'value'));
            $this->stderr("Invalid role `{$role}`. Allowed roles: {$allowed}.\n");

            return ExitCode::USAGE;
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyById($companyId);

        if ($company === null) {
            $this->stderr("No company found with id `{$companyId}`.\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $user = Plugin::getInstance()->companyMembers->findUserByEmail($email);

        if ($user === null) {
            $this->stderr("No user found with email `{$email}`.\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Assigning role `{$companyRole->value}` to `{$user->email}` in company `{$company->title}` (#{$company->id})...\n");

        Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, $companyRole);

        $this->stdout("Done.\n");

        return ExitCode::OK;
    }
}
