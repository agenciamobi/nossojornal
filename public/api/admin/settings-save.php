<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'manage_options');
    nj_admin_require_csrf();

    $body = nj_admin_request_body();
    $input = is_array($body['options'] ?? null) ? $body['options'] : [];

    $blogname = trim((string) ($input['blogname'] ?? ''));
    $blogdescription = trim((string) ($input['blogdescription'] ?? ''));
    $adminEmail = trim((string) ($input['admin_email'] ?? ''));
    $postsPerPage = (int) ($input['posts_per_page'] ?? 10);
    $timezone = trim((string) ($input['timezone_string'] ?? 'America/Sao_Paulo'));
    $dateFormat = trim((string) ($input['date_format'] ?? 'd/m/Y'));
    $timeFormat = trim((string) ($input['time_format'] ?? 'H:i'));

    if ($blogname === '' || (function_exists('mb_strlen') ? mb_strlen($blogname, 'UTF-8') : strlen($blogname)) > 200) {
        throw new NjApiHttpException(422, 'invalid_blogname');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($blogdescription, 'UTF-8') : strlen($blogdescription)) > 500) {
        throw new NjApiHttpException(422, 'invalid_blogdescription');
    }

    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        throw new NjApiHttpException(422, 'invalid_admin_email');
    }

    if ($postsPerPage < 1 || $postsPerPage > 100) {
        throw new NjApiHttpException(422, 'invalid_posts_per_page');
    }

    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        throw new NjApiHttpException(422, 'invalid_timezone');
    }

    if (strlen($dateFormat) > 40 || strlen($timeFormat) > 40) {
        throw new NjApiHttpException(422, 'invalid_date_time_format');
    }

    $values = [
        'blogname' => $blogname,
        'blogdescription' => $blogdescription,
        'admin_email' => $adminEmail,
        'posts_per_page' => (string) $postsPerPage,
        'timezone_string' => $timezone,
        'date_format' => $dateFormat,
        'time_format' => $timeFormat,
    ];

    $pdo = nj_db();
    $options = nj_table('options');

    try {
        $pdo->beginTransaction();

        $find = $pdo->prepare(
            "SELECT option_id FROM {$options} WHERE option_name = :option_name LIMIT 1"
        );
        $update = $pdo->prepare(<<<SQL
UPDATE {$options}
SET option_value = :option_value
WHERE option_id = :option_id
LIMIT 1
SQL);
        $insert = $pdo->prepare(<<<SQL
INSERT INTO {$options} (option_name, option_value, autoload)
VALUES (:option_name, :option_value, 'yes')
SQL);

        foreach ($values as $name => $value) {
            $find->execute(['option_name' => $name]);
            $optionId = (int) ($find->fetchColumn() ?: 0);

            if ($optionId > 0) {
                $update->execute([
                    'option_value' => $value,
                    'option_id' => $optionId,
                ]);
            } else {
                $insert->execute([
                    'option_name' => $name,
                    'option_value' => $value,
                ]);
            }
        }

        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $readBack = $pdo->prepare(
            "SELECT option_name, option_value FROM {$options} WHERE option_name IN ({$placeholders})"
        );
        $readBack->execute(array_keys($values));

        $persisted = [];
        foreach ($readBack->fetchAll() as $row) {
            $persisted[(string) $row['option_name']] = (string) $row['option_value'];
        }

        foreach ($values as $name => $value) {
            if (($persisted[$name] ?? null) !== $value) {
                throw new RuntimeException('settings_readback_mismatch');
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
        'options' => $persisted,
    ];
});
