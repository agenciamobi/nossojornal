<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $currentUser = nj_admin_current_user(true);
    nj_admin_require_capability($currentUser, 'list_users');

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if (!is_int($id) || $id <= 0) {
        throw new NjApiHttpException(422, 'invalid_user_id');
    }

    $pdo = nj_db();
    $users = nj_table('users');

    $statement = $pdo->prepare(<<<SQL
SELECT
    ID,
    user_login,
    user_email,
    user_registered,
    user_status,
    display_name
FROM {$users}
WHERE ID = :id
LIMIT 1
SQL);
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    if (!$row) {
        throw new NjApiHttpException(404, 'user_not_found');
    }

    $definitions = nj_admin_role_definitions($pdo);
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
        'user' => nj_admin_user_payload($pdo, $row),
        'roles' => $roles,
        'canChangeRole' => in_array('promote_users', $currentUser['capabilities'], true)
            && (int) $currentUser['id'] !== $id
            && (string) $row['user_login'] !== NJ_PAUTAS_OWNER_LOGIN,
    ];
});
