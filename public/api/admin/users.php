<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $currentUser = nj_admin_current_user(true);
    nj_admin_require_capability($currentUser, 'list_users');

    $pdo = nj_db();
    $users = nj_table('users');

    $rows = $pdo->query(<<<SQL
SELECT
    ID,
    user_login,
    user_email,
    user_registered,
    user_status,
    display_name
FROM {$users}
ORDER BY display_name ASC, user_login ASC
SQL)->fetchAll();

    $items = [];

    foreach ($rows as $row) {
        $items[] = nj_admin_user_payload($pdo, $row);
    }

    return [
        'items' => $items,
        'count' => count($items),
        'mode' => 'read_only',
    ];
});
