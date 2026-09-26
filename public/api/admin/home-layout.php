<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

const NJ_HOME_META_SLOT = '_nj_home_slot';
const NJ_HOME_META_RANK = '_nj_home_rank';
const NJ_HOME_META_UNTIL = '_nj_home_until';

function nj_home_admin_until(mixed $value): string
{
    $raw = trim((string) $value);

    if ($raw === '') {
        return '';
    }

    try {
        $timezone = new DateTimeZone('America/Sao_Paulo');
        $date = new DateTimeImmutable($raw, $timezone);

        return $date->format('Y-m-d\TH:i');
    } catch (Throwable) {
        throw new NjApiHttpException(422, 'invalid_home_until');
    }
}

function nj_home_admin_items(PDO $pdo): array
{
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $users = nj_table('users');

    $rows = $pdo->query(<<<SQL
SELECT
    p.ID AS id,
    p.post_title AS title,
    p.post_name AS slug,
    p.post_status AS status,
    p.post_date AS published_at,
    p.post_modified AS modified_at,
    COALESCE(u.display_name, u.user_login, '') AS author_name,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_home_slot'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), 'automatic') AS home_slot,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_home_rank'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '0') AS home_rank,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_home_until'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '') AS home_until,
    COALESCE((
        SELECT a.guid
        FROM {$postmeta} thumb
        INNER JOIN {$posts} a
            ON a.ID = CAST(thumb.meta_value AS UNSIGNED)
            AND a.post_type = 'attachment'
        WHERE thumb.post_id = p.ID AND thumb.meta_key = '_thumbnail_id'
        ORDER BY thumb.meta_id DESC
        LIMIT 1
    ), '') AS image_url
FROM {$posts} p
LEFT JOIN {$users} u ON u.ID = p.post_author
WHERE
    p.post_type = 'post'
    AND p.post_status = 'publish'
    AND p.post_title <> ''
ORDER BY
    CASE
        WHEN (
            SELECT pm.meta_value
            FROM {$postmeta} pm
            WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_home_slot'
            ORDER BY pm.meta_id DESC
            LIMIT 1
        ) = 'hero' THEN 0
        WHEN (
            SELECT pm.meta_value
            FROM {$postmeta} pm
            WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_home_slot'
            ORDER BY pm.meta_id DESC
            LIMIT 1
        ) = 'featured' THEN 1
        ELSE 2
    END,
    CAST(COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = p.ID AND pm.meta_key = '_nj_home_rank'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '0') AS UNSIGNED) ASC,
    p.post_date DESC
LIMIT 80
SQL)->fetchAll();

    $items = [];
    $timezone = new DateTimeZone('America/Sao_Paulo');
    $now = new DateTimeImmutable('now', $timezone);

    foreach ($rows as $row) {
        $imageUrl = trim((string) $row['image_url']);
        if ($imageUrl !== '') {
            $path = parse_url($imageUrl, PHP_URL_PATH);
            if (is_string($path) && str_starts_with($path, '/wp-content/uploads/')) {
                $imageUrl = $path;
            }
        }

        $slot = (string) $row['home_slot'];
        if (!in_array($slot, ['automatic', 'hero', 'featured'], true)) {
            $slot = 'automatic';
        }

        $until = trim((string) $row['home_until']);
        $active = $slot !== 'automatic';

        if ($active && $until !== '') {
            try {
                $active = new DateTimeImmutable($until, $timezone) > $now;
            } catch (Throwable) {
                $active = false;
            }
        }

        $items[] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'slug' => (string) $row['slug'],
            'status' => (string) $row['status'],
            'publishedAt' => nj_content_iso8601((string) $row['published_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'author' => (string) $row['author_name'],
            'imageUrl' => $imageUrl !== '' ? $imageUrl : null,
            'publicUrl' => '/noticia/' . rawurlencode((string) $row['slug']),
            'home' => [
                'slot' => $slot,
                'rank' => max(0, min(99, (int) $row['home_rank'])),
                'until' => $until,
                'active' => $active,
            ],
        ];
    }

    return $items;
}

nj_admin_run(['GET', 'POST'], static function (string $method): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'publish_posts');
    nj_admin_require_capability($user, 'edit_others_posts');

    $pdo = nj_db();

    if ($method === 'GET') {
        return [
            'items' => nj_home_admin_items($pdo),
        ];
    }

    nj_admin_require_csrf();

    $body = nj_admin_request_body();
    $postId = filter_var(
        $body['postId'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $slot = trim((string) ($body['slot'] ?? 'automatic'));
    $rank = max(0, min(99, (int) ($body['rank'] ?? 0)));
    $until = nj_home_admin_until($body['until'] ?? '');

    if (!is_int($postId) || $postId <= 0) {
        throw new NjApiHttpException(422, 'invalid_post_id');
    }

    if (!in_array($slot, ['automatic', 'hero', 'featured'], true)) {
        throw new NjApiHttpException(422, 'invalid_home_slot');
    }

    $posts = nj_table('posts');
    $exists = $pdo->prepare(
        "SELECT ID
         FROM {$posts}
         WHERE ID = :id
           AND post_type = 'post'
           AND post_status = 'publish'
         LIMIT 1"
    );
    $exists->execute(['id' => $postId]);

    if (!$exists->fetchColumn()) {
        throw new NjApiHttpException(404, 'post_not_found');
    }

    try {
        $pdo->beginTransaction();

        if ($slot === 'hero') {
            $postmeta = nj_table('postmeta');
            $heroRows = $pdo->query(
                "SELECT DISTINCT post_id
                 FROM {$postmeta}
                 WHERE meta_key = '_nj_home_slot'
                   AND meta_value = 'hero'"
            )->fetchAll(PDO::FETCH_COLUMN);

            foreach ($heroRows as $heroId) {
                $heroId = (int) $heroId;
                if ($heroId > 0 && $heroId !== $postId) {
                    nj_admin_upsert_postmeta($pdo, $heroId, NJ_HOME_META_SLOT, 'automatic');
                    nj_admin_upsert_postmeta($pdo, $heroId, NJ_HOME_META_RANK, '0');
                    nj_admin_upsert_postmeta($pdo, $heroId, NJ_HOME_META_UNTIL, '');
                }
            }
        }

        nj_admin_upsert_postmeta($pdo, $postId, NJ_HOME_META_SLOT, $slot);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_HOME_META_RANK, (string) $rank);
        nj_admin_upsert_postmeta($pdo, $postId, NJ_HOME_META_UNTIL, $until);

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
        'items' => nj_home_admin_items($pdo),
    ];
});
