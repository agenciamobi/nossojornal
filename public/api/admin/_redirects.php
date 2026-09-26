<?php
declare(strict_types=1);

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

function nj_redirect_lookup_by_source(PDO $pdo, string $source): ?array
{
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');

    $statement = $pdo->prepare(<<<SQL
SELECT
    p.ID,
    p.post_status,
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
    ), '301') AS redirect_status,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_redirect_origin'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS redirect_origin
FROM {$posts} p
INNER JOIN {$postmeta} source_meta
    ON source_meta.post_id = p.ID
    AND source_meta.meta_key = '_nj_redirect_from'
    AND source_meta.meta_value = :source
WHERE
    p.post_type = 'nj_redirect'
    AND p.post_status <> 'trash'
ORDER BY p.ID DESC
LIMIT 1
SQL);
    $statement->execute(['source' => $source]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function nj_redirect_insert_auto(
    PDO $pdo,
    int $authorId,
    string $source,
    string $destination,
    string $note,
    string $objectType,
    int $objectId
): int {
    $posts = nj_table('posts');

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
    'publish',
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
        'author_id' => $authorId,
        'title' => $source,
        'note' => $note,
    ]);

    $redirectId = (int) $pdo->lastInsertId();
    if ($redirectId <= 0) {
        throw new RuntimeException('redirect_insert_missing_id');
    }

    nj_admin_upsert_postmeta($pdo, $redirectId, '_nj_redirect_from', $source);
    nj_admin_upsert_postmeta($pdo, $redirectId, '_nj_redirect_to', $destination);
    nj_admin_upsert_postmeta($pdo, $redirectId, '_nj_redirect_status', '301');
    nj_admin_upsert_postmeta($pdo, $redirectId, '_nj_redirect_origin', 'auto_slug');
    nj_admin_upsert_postmeta($pdo, $redirectId, '_nj_redirect_object_type', $objectType);
    nj_admin_upsert_postmeta($pdo, $redirectId, '_nj_redirect_object_id', (string) $objectId);

    return $redirectId;
}

function nj_redirect_ensure_slug_change(
    PDO $pdo,
    int $authorId,
    string $source,
    string $destination,
    string $objectType,
    int $objectId
): array {
    $source = nj_redirect_normalize_source($source);
    $destination = nj_redirect_normalize_destination($destination, 301);

    if ($source === $destination) {
        return [
            'state' => 'unchanged',
            'id' => null,
            'collapsed' => 0,
        ];
    }

    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');

    // If a canonical slug returns to a URL that previously had an automatic
    // redirect, retire that automatic rule before creating the reverse route.
    $destinationRule = nj_redirect_lookup_by_source($pdo, $destination);
    if (
        is_array($destinationRule)
        && (string) $destinationRule['redirect_origin'] === 'auto_slug'
    ) {
        $retire = $pdo->prepare(<<<SQL
UPDATE {$posts}
SET
    post_status = 'trash',
    post_modified = NOW(),
    post_modified_gmt = UTC_TIMESTAMP()
WHERE ID = :id AND post_type = 'nj_redirect'
LIMIT 1
SQL);
        $retire->execute(['id' => (int) $destinationRule['ID']]);
    }

    // Collapse previous automatic chains. Example: A→B and B→C becomes
    // A→C plus B→C.
    $inbound = $pdo->prepare(<<<SQL
SELECT
    p.ID,
    source_meta.meta_value AS redirect_from
FROM {$posts} p
INNER JOIN {$postmeta} target_meta
    ON target_meta.post_id = p.ID
    AND target_meta.meta_key = '_nj_redirect_to'
    AND target_meta.meta_value = :source
INNER JOIN {$postmeta} origin_meta
    ON origin_meta.post_id = p.ID
    AND origin_meta.meta_key = '_nj_redirect_origin'
    AND origin_meta.meta_value = 'auto_slug'
INNER JOIN {$postmeta} source_meta
    ON source_meta.post_id = p.ID
    AND source_meta.meta_key = '_nj_redirect_from'
WHERE
    p.post_type = 'nj_redirect'
    AND p.post_status = 'publish'
ORDER BY p.ID ASC
LIMIT 50
SQL);
    $inbound->execute(['source' => $source]);

    $collapsed = 0;
    foreach ($inbound->fetchAll() as $row) {
        $redirectFrom = (string) $row['redirect_from'];
        if ($redirectFrom === $destination) {
            continue;
        }

        nj_admin_upsert_postmeta(
            $pdo,
            (int) $row['ID'],
            '_nj_redirect_to',
            $destination
        );
        $collapsed++;
    }

    $existing = nj_redirect_lookup_by_source($pdo, $source);
    $note = sprintf(
        'Criado automaticamente ao alterar URL de %s #%d.',
        $objectType === 'page' ? 'página' : 'notícia',
        $objectId
    );

    if (is_array($existing)) {
        $redirectId = (int) $existing['ID'];
        $existingDestination = (string) $existing['redirect_to'];
        $existingStatus = (int) $existing['redirect_status'];
        $origin = (string) $existing['redirect_origin'];

        if (
            $existingDestination === $destination
            && $existingStatus === 301
            && (string) $existing['post_status'] === 'publish'
        ) {
            return [
                'state' => 'existing',
                'id' => $redirectId,
                'collapsed' => $collapsed,
            ];
        }

        if ($origin !== 'auto_slug') {
            return [
                'state' => 'manual_conflict',
                'id' => $redirectId,
                'collapsed' => $collapsed,
            ];
        }

        $update = $pdo->prepare(<<<SQL
UPDATE {$posts}
SET
    post_title = :title,
    post_excerpt = :note,
    post_status = 'publish',
    post_modified = NOW(),
    post_modified_gmt = UTC_TIMESTAMP()
WHERE ID = :id AND post_type = 'nj_redirect'
LIMIT 1
SQL);
        $update->execute([
            'title' => $source,
            'note' => $note,
            'id' => $redirectId,
        ]);

        nj_admin_upsert_postmeta($pdo, $redirectId, '_nj_redirect_to', $destination);
        nj_admin_upsert_postmeta($pdo, $redirectId, '_nj_redirect_status', '301');
        nj_admin_upsert_postmeta($pdo, $redirectId, '_nj_redirect_origin', 'auto_slug');
        nj_admin_upsert_postmeta($pdo, $redirectId, '_nj_redirect_object_type', $objectType);
        nj_admin_upsert_postmeta($pdo, $redirectId, '_nj_redirect_object_id', (string) $objectId);

        return [
            'state' => 'updated',
            'id' => $redirectId,
            'collapsed' => $collapsed,
        ];
    }

    $redirectId = nj_redirect_insert_auto(
        $pdo,
        $authorId,
        $source,
        $destination,
        $note,
        $objectType,
        $objectId
    );

    return [
        'state' => 'created',
        'id' => $redirectId,
        'collapsed' => $collapsed,
    ];
}
