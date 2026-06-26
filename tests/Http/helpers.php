<?php

use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\ResponseInterface;

/**
 * Base URL of the sibling dev site served by Herd over trusted local TLS.
 */
function httpBaseUri(): string
{
    return 'https://b2b-dev.test';
}

/**
 * Default password handed to every programmatically created HTTP test user.
 * Comfortably above Craft's minimum length so validation never rejects it.
 */
function httpTestPassword(): string
{
    return 'Sup3rS3cretP4ss!';
}

/**
 * Probes the dev web server exactly once and memoizes the outcome, so the whole
 * Http suite can skip cleanly (rather than erroring) when nothing is listening.
 */
function httpSiteAvailable(): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    try {
        (new Client([
            'base_uri' => httpBaseUri(),
            'verify' => false,
            'http_errors' => false,
            'timeout' => 5,
        ]))->get('/actions/users/session-info', [
            'headers' => ['Accept' => 'application/json'],
        ]);

        return $available = true;
    } catch (ConnectException) {
        return $available = false;
    }
}

/**
 * Builds a fresh Guzzle client with its own cookie jar, so each client models
 * one isolated browser session. TLS verification is disabled because the local
 * Herd certificate is not part of the CA bundle Guzzle ships with.
 */
function httpClient(): Client
{
    return new Client([
        'base_uri' => httpBaseUri(),
        'cookies' => new CookieJar(),
        'verify' => false,
        'http_errors' => false,
        'allow_redirects' => false,
        'timeout' => 15,
    ]);
}

/**
 * Fetches the CSRF token bound to this client's session. Must be called with the
 * SAME client (cookie jar) that will post, and re-called after login because the
 * session — and therefore its token — is regenerated on authentication.
 */
function csrfToken(Client $client): string
{
    $response = $client->get('/actions/users/session-info', [
        'headers' => ['Accept' => 'application/json'],
    ]);

    $data = json_decode((string) $response->getBody(), true);

    return is_array($data) ? (string) ($data['csrfTokenValue'] ?? '') : '';
}

/**
 * Posts a form-encoded action request with a freshly fetched CSRF token, sent
 * both as the header Craft reads and as the body param named after the token.
 *
 * @param array<string, mixed> $params
 */
function postAction(Client $client, string $action, array $params = []): ResponseInterface
{
    $token = csrfToken($client);

    return $client->post("/actions/{$action}", [
        'headers' => [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-CSRF-Token' => $token,
        ],
        'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $token],
    ]);
}

/**
 * Posts a multipart/form-data action request (for file uploads) with a freshly fetched
 * CSRF token. Each entry in $parts is a Guzzle multipart part; the CSRF token is appended
 * automatically both as the header Craft reads and as the body field named after the token.
 *
 * @param array<int, array<string, mixed>> $parts
 */
function postMultipartAction(Client $client, string $action, array $parts): ResponseInterface
{
    $token = csrfToken($client);

    return $client->post("/actions/{$action}", [
        'headers' => [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-CSRF-Token' => $token,
        ],
        'multipart' => array_merge($parts, [
            ['name' => 'CRAFT_CSRF_TOKEN', 'contents' => $token],
        ]),
    ]);
}

/**
 * Logs a client in over HTTP and returns the raw login response.
 */
function loginAs(Client $client, string $email, string $password): ResponseInterface
{
    return postAction($client, 'users/login', [
        'loginName' => $email,
        'password' => $password,
    ]);
}

/**
 * Creates a tracked, active User with a known password so it can authenticate
 * over HTTP. Extends the integration createTestUser helper by activating the
 * account and setting newPassword, which Craft hashes on save.
 */
function createTestUserWithPassword(string $email, string $password = ''): User
{
    $user = new User();
    $user->email = $email;
    $user->username = 'test_' . uniqid();
    $user->active = true;
    $user->newPassword = $password !== '' ? $password : httpTestPassword();

    if (!craftApp()->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save test user: ' . implode(', ', $user->getFirstErrors()));
    }

    trackElement($user);

    return $user;
}
