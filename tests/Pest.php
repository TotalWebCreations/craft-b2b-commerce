<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

// uses(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Integration suite
|--------------------------------------------------------------------------
|
| The Integration suite boots the sibling b2b-dev Craft application. The Unit
| suite must stay Craft-free, so the harness is only loaded here and only the
| Integration tests boot Craft. When the dev site is absent the suite skips
| itself cleanly.
|
*/

require_once __DIR__ . '/Integration/bootstrap.php';
require_once __DIR__ . '/Integration/helpers.php';
require_once __DIR__ . '/Http/helpers.php';

uses()
    ->beforeEach(function () {
        if (!devSiteAvailable()) {
            test()->markTestSkipped('Dev site (../b2b-dev) is not available.');
        }

        craftApp();
    })
    ->afterEach(fn () => deleteTrackedElements())
    ->in('Integration');

/*
|--------------------------------------------------------------------------
| Http suite
|--------------------------------------------------------------------------
|
| The Http suite drives the real request pipeline against the running dev web
| server, while arranging fixtures and asserting DB state in-process (both share
| the same database). It boots craftApp() for the fixtures and skips cleanly
| when either the dev site files or the web server are unreachable.
|
*/

uses()
    ->beforeEach(function () {
        if (!devSiteAvailable() || !httpSiteAvailable()) {
            test()->markTestSkipped('Dev web server (https://b2b-dev.test) is not reachable.');
        }

        craftApp();
    })
    ->afterEach(fn () => deleteTrackedElements())
    ->in('Http');
