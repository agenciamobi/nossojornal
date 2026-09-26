<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $currentUser = nj_admin_current_user(true);
    nj_admin_require_capability($currentUser, 'list_users');

    $pdo = nj_db();
    $users = nj_table('users');

    $query = trim((string) ($_GET['q'] ?? ''));
    if (function_exists('mb_substr')) {
        $query = mb_substr($query, 0, 120, 'UTF-8');
    } else {
        $query = substr($query, 0, 120);
    }

    $roleFilter = trim((string) ($_GET['role'] ?? 'all'));
    $definitions = nj_admin_role_definitions($pdo);
    if ($roleFilter !== 'all' && !isset($definitions[$roleFilter])) {
        throw new NjApiHttpException(400, 'invalid_user_role_filter');
    }

    $where = [];
    $params = [];

    if ($query !== '') {
        $where[] = "(
            user_login LIKE :search_login
            OR user_email LIKE :search_email
            OR display_name LIKE :search_name
        )";
        $needle = '%' . $query . '%';
        $params = [
            'search_login' => $needle,
            'search_email' => $needle,
            'search_name' => $needle,
        ];
    }

    $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

    $statement = $pdo->prepare(<<<SQL
SELECT
    ID,
    user_login,
    user_email,
    user_registered,
    user_status,
    display_name
FROM {$users}
{$whereSql}
ORDER BY display_name ASC, user_login ASC
LIMIT 500
SQL);
    $statement->execute($params);
    $rows = $statement->fetchAll();

    $items = [];

    foreach ($rows as $row) {
        $payload = nj_admin_user_payload($pdo, $row);

        if (
            $roleFilter !== 'all'
            && !in_array($roleFilter, $payload['roles'], true)
        ) {
            continue;
        }

        $items[] = $payload;
    }

    $roles = [];
    foreach ($definitions as $key => $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $roles[] = [
            'key' => (string) $key,
            'name' => (string) ($definition['name'] ?? $key),
        ];
    }

    return [
        'items' => $items,
        'count' => count($items),
        'query' => $query,
        'role' => $roleFilter,
        'roles' => $roles,
        'canCreate' => in_array('create_users', $currentUser['capabilities'], true)
            && in_array('promote_users', $currentUser['capabilities'], true),
    ];
});
