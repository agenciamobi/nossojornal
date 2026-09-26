<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    $body = nj_admin_request_body();

    return nj_admin_login(
        (string) ($body['identity'] ?? ''),
        (string) ($body['password'] ?? '')
    );
});
