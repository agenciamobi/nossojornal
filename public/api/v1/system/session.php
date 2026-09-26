<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';

nj_system_start_session();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    nj_system_json([
        'ok' => true,
        'data' => nj_system_public_session_payload(nj_system_current_user()),
    ]);
}

if ($method === 'POST') {
    $body = nj_system_request_body();
    $login = trim((string) ($body['login'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($login === '' || $password === '') {
        nj_system_json([
            'ok' => false,
            'error' => ['code' => 'credentials_required'],
        ], 400);
    }

    $pdo = nj_db();
    $users = nj_table('users');

    $statement = $pdo->prepare(<<<SQL
SELECT ID, user_login, user_email, user_pass
FROM {$users}
WHERE
    user_login = :login
    OR user_email = :email
LIMIT 1
SQL);
    $statement->execute([
        'login' => $login,
        'email' => $login,
    ]);
    $row = $statement->fetch();

    if (
        !$row
        || !nj_system_verify_wordpress_password(
            $password,
            (string) $row['user_pass']
        )
    ) {
        usleep(250000);
        nj_system_json([
            'ok' => false,
            'error' => ['code' => 'invalid_credentials'],
        ], 401);
    }

    $user = nj_system_user_by_id($pdo, (int) $row['ID']);
    if ($user === null || empty($user['permissions']['dashboard'])) {
        nj_system_json([
            'ok' => false,
            'error' => ['code' => 'admin_access_denied'],
        ], 403);
    }

    session_regenerate_id(true);
    $_SESSION[NJ_SYSTEM_SESSION_USER_KEY] = (int) $row['ID'];

    nj_system_json([
        'ok' => true,
        'data' => nj_system_public_session_payload($user),
    ]);
}

if ($method === 'DELETE') {
    nj_system_verify_csrf();

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }

    session_destroy();
    nj_system_json([
        'ok' => true,
        'data' => ['authenticated' => false],
    ]);
}

header('Allow: GET, POST, DELETE');
nj_system_json([
    'ok' => false,
    'error' => ['code' => 'method_not_allowed'],
], 405);
