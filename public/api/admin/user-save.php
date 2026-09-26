<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    $currentUser = nj_admin_current_user(true);
    nj_admin_require_capability($currentUser, 'edit_users');
    nj_admin_require_csrf();

    $body = nj_admin_request_body();
    $id = filter_var(
        $body['userId'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $displayName = trim((string) ($body['displayName'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $requestedRole = trim((string) ($body['role'] ?? ''));

    if (!is_int($id) || $id <= 0) {
        throw new NjApiHttpException(422, 'invalid_user_id');
    }

    if ($displayName === '' || (function_exists('mb_strlen') ? mb_strlen($displayName, 'UTF-8') : strlen($displayName)) > 250) {
        throw new NjApiHttpException(422, 'invalid_display_name');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new NjApiHttpException(422, 'invalid_user_email');
    }

    $pdo = nj_db();
    $users = nj_table('users');
    $usermeta = nj_table('usermeta');
    $prefix = (string) nj_db_config()['table_prefix'];

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

    $duplicateEmail = $pdo->prepare(<<<SQL
SELECT ID
FROM {$users}
WHERE
    user_email = :email
    AND ID <> :id
LIMIT 1
SQL);
    $duplicateEmail->execute([
        'email' => $email,
        'id' => $id,
    ]);

    if ($duplicateEmail->fetchColumn()) {
        throw new NjApiHttpException(409, 'user_email_exists');
    }

    $access = nj_admin_user_roles_and_capabilities($pdo, $id);
    $currentRole = $access['roles'][0] ?? '';

    $canChangeRole = in_array('promote_users', $currentUser['capabilities'], true)
        && (int) $currentUser['id'] !== $id
        && (string) $row['user_login'] !== NJ_PAUTAS_OWNER_LOGIN;

    $role = $currentRole;

    if ($requestedRole !== '' && $requestedRole !== $currentRole) {
        if (!$canChangeRole) {
            throw new NjApiHttpException(403, 'role_change_not_allowed');
        }

        $definitions = nj_admin_role_definitions($pdo);
        if (!isset($definitions[$requestedRole])) {
            throw new NjApiHttpException(422, 'invalid_user_role');
        }

        $role = $requestedRole;
    }

    try {
        $pdo->beginTransaction();

        $updateUser = $pdo->prepare(<<<SQL
UPDATE {$users}
SET
    display_name = :display_name,
    user_email = :email
WHERE ID = :id
LIMIT 1
SQL);
        $updateUser->execute([
            'display_name' => $displayName,
            'email' => $email,
            'id' => $id,
        ]);

        if ($role !== $currentRole) {
            $capabilityKey = $prefix . 'capabilities';
            $levelKey = $prefix . 'user_level';
            $serializedRole = serialize([$role => true]);

            $findMeta = $pdo->prepare(<<<SQL
SELECT umeta_id
FROM {$usermeta}
WHERE
    user_id = :user_id
    AND meta_key = :meta_key
ORDER BY umeta_id DESC
LIMIT 1
SQL);
            $updateMeta = $pdo->prepare(<<<SQL
UPDATE {$usermeta}
SET meta_value = :meta_value
WHERE umeta_id = :meta_id
LIMIT 1
SQL);
            $insertMeta = $pdo->prepare(<<<SQL
INSERT INTO {$usermeta} (user_id, meta_key, meta_value)
VALUES (:user_id, :meta_key, :meta_value)
SQL);

            $upsertMeta = static function (
                string $key,
                string $value
            ) use ($findMeta, $updateMeta, $insertMeta, $id): void {
                $findMeta->execute([
                    'user_id' => $id,
                    'meta_key' => $key,
                ]);
                $metaId = (int) ($findMeta->fetchColumn() ?: 0);

                if ($metaId > 0) {
                    $updateMeta->execute([
                        'meta_value' => $value,
                        'meta_id' => $metaId,
                    ]);
                } else {
                    $insertMeta->execute([
                        'user_id' => $id,
                        'meta_key' => $key,
                        'meta_value' => $value,
                    ]);
                }
            };

            $definitions = nj_admin_role_definitions($pdo);
            $roleCapabilities = $definitions[$role]['capabilities'] ?? [];
            $level = 0;

            if (is_array($roleCapabilities)) {
                for ($candidate = 10; $candidate >= 0; $candidate--) {
                    if (($roleCapabilities['level_' . $candidate] ?? false) === true) {
                        $level = $candidate;
                        break;
                    }
                }
            }

            $upsertMeta($capabilityKey, $serializedRole);
            $upsertMeta($levelKey, (string) $level);
        }

        $readBack = $pdo->prepare(<<<SQL
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
        $readBack->execute(['id' => $id]);
        $persisted = $readBack->fetch();

        if (
            !$persisted
            || (string) $persisted['display_name'] !== $displayName
            || (string) $persisted['user_email'] !== $email
        ) {
            throw new RuntimeException('user_readback_mismatch');
        }

        if ($role !== $currentRole) {
            $persistedAccess = nj_admin_user_roles_and_capabilities($pdo, $id);
            if (!in_array($role, $persistedAccess['roles'], true)) {
                throw new RuntimeException('user_role_readback_mismatch');
            }
        }

        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
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
    ];
});
