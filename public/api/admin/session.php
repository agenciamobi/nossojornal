<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(false);

    return [
        'authenticated' => $user !== null,
        'user' => $user,
        'csrfToken' => $user !== null ? nj_admin_csrf_token() : null,
    ];
});
