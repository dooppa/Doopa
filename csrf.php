<?php

/*
|--------------------------------------------------------------------------
| Doopa CSRF Token Endpoint
|--------------------------------------------------------------------------
| Provides a CSRF token to authenticated users.
|--------------------------------------------------------------------------
*/

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/config/csrf.php';

// Allow GET requests only.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    jsonResponse(
        false,
        'Method not allowed.',
        [],
        405
    );
}

// Require an authenticated user.
if (
    empty($_SESSION['logged_in']) ||
    empty($_SESSION['user_id'])
) {
    jsonResponse(
        false,
        'Authentication required.',
        [],
        401
    );
}

// Prevent token responses from being cached.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Generate or retrieve the session CSRF token.
$csrfToken = getCsrfToken();

// Return the token.
jsonResponse(
    true,
    'CSRF token retrieved successfully.',
    [
        'csrf_token' => $csrfToken
    ],
    200
);