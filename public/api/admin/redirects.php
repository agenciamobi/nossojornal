<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

function nj_redirect_normalize_source(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        throw new NjApiHttpException(422, 'redirect_source_required');
    }

    if (strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
        throw new NjApiHttpException(422, 'redirect_source_invalid');
    }

    $path = parse_url($value, PHP_URL_PATH);
    $value = is_string($path) ? $path : '';

    $value = '/' . ltrim($value, '/');
    $value = preg_replace('#/+#', '/', $value) ?? $value;

    if ($value !== '/') {
        $value = rtrim($value, '/');
    }

    if ($value === '' || str_starts_with($value, '/api/') || str_starts_with($value, '/sistema')) {
        throw new NjApiHttpException(422, 'redirect_source_reserved');
    }

    return $value;
}

function nj_redirect_normalize_destination(string $value, int $status): string
{
    $value = trim($value);

    if ($status === 410) {
        return '';
    }

    if ($value === '' || strlen($value) > 1000 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
        throw new NjApiHttpException(422, 'redirect_destination_invalid');
    }

    if (str_starts_with($value, '/')) {
        $normalized = '/' . ltrim($value, '/');
        return preg_replace('#/+#', '/', $normalized) ?? $normalized;
    }

    if (!preg_match('#^https?://#i', $value)) {
        throw new NjApiHttpException(422, 'redirect_destination_invalid');
    }

    $parts = parse_url($value);
    if (!is_array($parts) || empty($parts['host'])) {
        throw new NjApiHttpException(422, 'redirect_destination_invalid');
    }

    return $value;
}

function nj_redirect_payload(array $row): array
{
    $status = (int) ($row['redirect_status'] ?? 301);

    return [
        'id' => (int) $row['ID'],
        'source' => (string) ($row['redirect_from'] ?? ''),
        'destination' => (string) ($row['redirect_to'] ?? ''),
        'statusCode' => in_array($status, [301, 302, 307, 308, 410], true) ? $status : 301,
        'enabled' => (string) $row['post_status'] === 'publish',
        'note' => (string) ($row['post_excerpt'] ?? ''),
        'createdAt' => nj_content_iso8601((string) $row['post_date']),
        'modifiedAt' => nj_content_iso8601((string) $row['post_modified']),
    ];
}

nj_admin_run(['GET', 'POST'], static function (string $method): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'manage_options');

    $pdo = nj_db();
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');

    if ($method === 'POST') {
        nj_admin_require_csrf();
        $body = nj_admin_request_body();
        $action = (string) ($body['action'] ?? 'save');

        if ($action === 'delete') {
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) {
                throw new NjApiHttpException(422, 'redirect_id_required');
            }

            $statement = $pdo->prepare(
                "UPDATE {$posts}
                 SET post_status = 'trash', post_modified = NOW(), post_modified_gmt = UTC_TIMESTAMP()
                 WHERE ID = :id AND post_type = 'nj_redirect'
                 LIMIT 1"
            );
            $statement->execute(['id' => $id]);

            if ($statement->rowCount() < 1) {
                throw new NjApiHttpException(404, 'redirect_not_found');
            }
        } elseif ($action === 'save') {
            $id = (int) ($body['id'] ?? 0);
            $statusCode = (int) ($body['statusCode'] ?? 301);
            $enabled = (bool) ($body['enabled'] ?? true);
            $source = nj_redirect_normalize_source((string) ($body['source'] ?? ''));
            $destination = nj_redirect_normalize_destination(
                (string) ($body['destination'] ?? ''),
                $statusCode
            );
            $note = trim((string) ($body['note'] ?? ''));

            if (!in_array($statusCode, [301, 302, 307, 308, 410], true)) {
                throw new NjApiHttpException(422, 'redirect_status_invalid');
            }

            if (strlen($note) > 1000) {
                throw new NjApiHttpException(422, 'redirect_note_too_large');
            }

            if ($destination !== '' && str_starts_with($destination, '/')) {
                $destinationPath = nj_redirect_normalize_source($destination);
                if ($destinationPath === $source) {
                    throw new NjApiHttpException(422, 'redirect_loop');
                }
            }

            $duplicate = $pdo->prepare(<<<SQL
SELECT p.ID
FROM {$posts} p
INNER JOIN {$postmeta} pm
    ON pm.post_id = p.ID
    AND pm.meta_key = '_nj_redirect_from'
    AND pm.meta_value = :source
WHERE
    p.post_type = 'nj_redirect'
    AND p.post_status <> 'trash'
    AND p.ID <> :id
LIMIT 1
SQL);
            $duplicate->execute([
                'source' => $source,
                'id' => $id,
            ]);

            if ($duplicate->fetchColumn()) {
                throw new NjApiHttpException(409, 'redirect_source_conflict');
            }

            $pdo->beginTransaction();

            try {
                if ($id > 0) {
                    $update = $pdo->prepare(<<<SQL
UPDATE {$posts}
SET
    post_title = :title,
    post_excerpt = :note,
    post_status = :status,
    post_modified = NOW(),
    post_modified_gmt = UTC_TIMESTAMP()
WHERE ID = :id AND post_type = 'nj_redirect'
LIMIT 1
SQL);
                    $update->execute([
                        'title' => $source,
                        'note' => $note,
                        'status' => $enabled ? 'publish' : 'draft',
                        'id' => $id,
                    ]);

                    if ($update->rowCount() < 1) {
                        $exists = $pdo->prepare(
                            "SELECT ID FROM {$posts} WHERE ID = :id AND post_type = 'nj_redirect' LIMIT 1"
                        );
                        $exists->execute(['id' => $id]);
                        if (!$exists->fetchColumn()) {
                            throw new NjApiHttpException(404, 'redirect_not_found');
                        }
                    }
                } else {
                    $insert = $pdo->prepare(<<<SQL
INSERT INTO {$posts} (
    post_author,
    post_date,
    post_date_gmt,
    post_content,
    post_title,
    post_excerpt,
    post_status,
    comment_status,
    ping_status,
    post_password,
    post_name,
    to_ping,
    pinged,
    post_modified,
    post_modified_gmt,
    post_content_filtered,
    post_parent,
    guid,
    menu_order,
    post_type,
    post_mime_type,
    comment_count
) VALUES (
    :author_id,
    NOW(),
    UTC_TIMESTAMP(),
    '',
    :title,
    :note,
    :status,
    'closed',
    'closed',
    '',
    '',
    '',
    '',
    NOW(),
    UTC_TIMESTAMP(),
    '',
    0,
    '',
    0,
    'nj_redirect',
    '',
    0
)
SQL);
                    $insert->execute([
                        'author_id' => (int) $user['id'],
                        'title' => $source,
                        'note' => $note,
                        'status' => $enabled ? 'publish' : 'draft',
                    ]);
                    $id = (int) $pdo->lastInsertId();
                }

                nj_admin_upsert_postmeta($pdo, $id, '_nj_redirect_from', $source);
                nj_admin_upsert_postmeta($pdo, $id, '_nj_redirect_to', $destination);
                nj_admin_upsert_postmeta($pdo, $id, '_nj_redirect_status', (string) $statusCode);

                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        } else {
            throw new NjApiHttpException(422, 'redirect_action_invalid');
        }
    }

    $statement = $pdo->query(<<<SQL
SELECT
    p.ID,
    p.post_status,
    p.post_excerpt,
    p.post_date,
    p.post_modified,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_redirect_from'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS redirect_from,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_redirect_to'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS redirect_to,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_redirect_status'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '301') AS redirect_status
FROM {$posts} p
WHERE p.post_type = 'nj_redirect' AND p.post_status <> 'trash'
ORDER BY p.post_modified DESC, p.ID DESC
LIMIT 500
SQL);

    return [
        'items' => array_map('nj_redirect_payload', $statement->fetchAll()),
        'allowedStatusCodes' => [301, 302, 307, 308, 410],
    ];
});
