<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    nj_admin_current_user(true);
    nj_admin_require_csrf();

    nj_admin_start_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            (string) ($params['path'] ?? '/'),
            (string) ($params['domain'] ?? ''),
            (bool) ($params['secure'] ?? true),
            (bool) ($params['httponly'] ?? true)
        );
    }

    session_destroy();

    return ['loggedOut' => true];
});
