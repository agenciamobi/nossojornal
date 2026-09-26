<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

function nj_admin_new_user_login(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
    return trim($value, '-');
}

nj_admin_run(['POST'], static function (): array {
    $currentUser = nj_admin_current_user(true);
    nj_admin_require_capability($currentUser, 'create_users');
    nj_admin_require_capability($currentUser, 'promote_users');
    nj_admin_require_csrf();

    $body = nj_admin_request_body();

    $login = nj_admin_new_user_login((string) ($body['login'] ?? ''));
    $displayName = trim((string) ($body['displayName'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $role = trim((string) ($body['role'] ?? 'author'));

    if (
        $login === ''
        || strlen($login) < 3
        || strlen($login) > 60
        || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $login) !== 1
    ) {
        throw new NjApiHttpException(422, 'invalid_user_login');
    }

    $displayLength = function_exists('mb_strlen')
        ? mb_strlen($displayName, 'UTF-8')
        : strlen($displayName);

    if ($displayName === '' || $displayLength > 250) {
        throw new NjApiHttpException(422, 'invalid_display_name');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        throw new NjApiHttpException(422, 'invalid_user_email');
    }

    if (strlen($password) < 12 || strlen($password) > 4096) {
        throw new NjApiHttpException(422, 'invalid_user_password');
    }

    $pdo = nj_db();
    $definitions = nj_admin_role_definitions($pdo);

    if (!isset($definitions[$role])) {
        throw new NjApiHttpException(422, 'invalid_user_role');
    }

    if (
        $role === 'administrator'
        && !in_array('manage_options', $currentUser['capabilities'], true)
    ) {
        throw new NjApiHttpException(403, 'administrator_role_not_allowed');
    }

    $users = nj_table('users');

    $duplicate = $pdo->prepare(<<<SQL
SELECT ID
FROM {$users}
WHERE user_login = :login OR user_email = :email
LIMIT 1
SQL);
    $duplicate->execute([
        'login' => $login,
        'email' => $email,
    ]);

    if ($duplicate->fetchColumn()) {
        throw new NjApiHttpException(409, 'user_already_exists');
    }

    $passwordHash = nj_admin_hash_wp_password($password);

    try {
        $pdo->beginTransaction();

        $insert = $pdo->prepare(<<<SQL
INSERT INTO {$users} (
    user_login,
    user_pass,
    user_nicename,
    user_email,
    user_url,
    user_registered,
    user_activation_key,
    user_status,
    display_name
) VALUES (
    :login,
    :password_hash,
    :nicename,
    :email,
    '',
    UTC_TIMESTAMP(),
    '',
    0,
    :display_name
)
SQL);
        $insert->execute([
            'login' => $login,
            'password_hash' => $passwordHash,
            'nicename' => $login,
            'email' => $email,
            'display_name' => $displayName,
        ]);

        $userId = (int) $pdo->lastInsertId();
        if ($userId <= 0) {
            throw new RuntimeException('user_insert_missing_id');
        }

        nj_admin_set_user_role($pdo, $userId, $role);
        nj_admin_upsert_usermeta($pdo, $userId, 'nickname', $displayName);
        nj_admin_upsert_usermeta($pdo, $userId, 'first_name', '');
        nj_admin_upsert_usermeta($pdo, $userId, 'last_name', '');

        $readBack = $pdo->prepare(<<<SQL
SELECT
    ID,
    user_login,
    user_email,
    user_registered,
    user_status,
    user_nicename,
    user_url,
    display_name
FROM {$users}
WHERE ID = :id
LIMIT 1
SQL);
        $readBack->execute(['id' => $userId]);
        $persisted = $readBack->fetch();

        if (
            !$persisted
            || (string) $persisted['user_login'] !== $login
            || (string) $persisted['user_email'] !== $email
            || (string) $persisted['display_name'] !== $displayName
        ) {
            throw new RuntimeException('user_create_readback_mismatch');
        }

        $persistedAccess = nj_admin_user_roles_and_capabilities($pdo, $userId);
        if (!in_array($role, $persistedAccess['roles'], true)) {
            throw new RuntimeException('user_role_readback_mismatch');
        }

        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ((string) $error->getCode() === '23000') {
            throw new NjApiHttpException(409, 'user_already_exists');
        }

        if ((string) $error->getCode() === '42000') {
            throw new NjApiHttpException(409, 'database_write_unavailable');
        }

        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }

    return [
        'user' => nj_admin_user_payload($pdo, $persisted),
        'adminUrl' => '/sistema/usuarios/' . (int) $persisted['ID'],
    ];
});
