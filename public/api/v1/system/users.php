<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';

nj_system_require_user('users');
$pdo = nj_db();

$users = nj_table('users');

$rows = $pdo->query(<<<SQL
SELECT
    ID AS id,
    user_login AS login,
    user_nicename AS nicename,
    user_email AS email,
    user_url AS url,
    user_registered AS registered_at,
    user_status AS status,
    display_name AS display_name
FROM {$users}
ORDER BY display_name ASC, ID ASC
SQL)->fetchAll();

$items = [];
foreach ($rows as $row) {
    $roles = nj_system_user_caps($pdo, (int) $row['id']);

    $items[] = [
        'id' => (int) $row['id'],
        'login' => (string) $row['login'],
        'nicename' => (string) $row['nicename'],
        'email' => (string) $row['email'],
        'url' => (string) $row['url'],
        'registeredAt' => (string) $row['registered_at'],
        'status' => (int) $row['status'],
        'displayName' => (string) $row['display_name'],
        'roles' => $roles,
    ];
}

nj_system_json([
    'ok' => true,
    'data' => [
        'items' => $items,
        'count' => count($items),
    ],
]);
