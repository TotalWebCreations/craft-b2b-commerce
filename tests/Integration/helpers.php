<?php

use craft\base\ElementInterface;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;

/**
 * Elements created during a test, hard-deleted afterwards.
 *
 * @var array<int, ElementInterface> $GLOBALS['b2bTrackedElements']
 */
$GLOBALS['b2bTrackedElements'] = [];

/**
 * Registers an element for automatic hard-delete in afterEach.
 */
function trackElement(ElementInterface $element): void
{
    $GLOBALS['b2bTrackedElements'][] = $element;
}

/**
 * Hard-deletes every tracked element and resets the tracker.
 */
function deleteTrackedElements(): void
{
    if ($GLOBALS['b2bTrackedElements'] === []) {
        return;
    }

    $elementsService = craftApp()->getElements();

    foreach (array_reverse($GLOBALS['b2bTrackedElements']) as $element) {
        $elementsService->deleteElement($element, true);
    }

    $GLOBALS['b2bTrackedElements'] = [];
}

/**
 * Creates and saves a tracked Company with a unique title.
 */
function createTestCompany(string $status = 'approved', string $title = 'Test Co'): Company
{
    $company = new Company();
    $company->title = $title . ' ' . uniqid();
    $company->companyStatus = $status;

    if (!craftApp()->getElements()->saveElement($company)) {
        throw new RuntimeException('Could not save test company: ' . implode(', ', $company->getFirstErrors()));
    }

    trackElement($company);

    return $company;
}

/**
 * Creates and saves a tracked User with a unique username and the given email.
 */
function createTestUser(string $email, bool $active = true): User
{
    $user = new User();
    $user->email = $email;
    $user->username = 'test_' . uniqid();
    $user->active = $active;

    if (!craftApp()->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save test user: ' . implode(', ', $user->getFirstErrors()));
    }

    trackElement($user);

    return $user;
}
