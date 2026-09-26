<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_content.php';

nj_run(static function (): array {
    $slug = trim((string) ($_GET['slug'] ?? ''));
    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, [
        'options' => ['default' => 1, 'min_range' => 1],
    ]);
    $perPage = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT, [
        'options' => ['default' => 12, 'min_range' => 1, 'max_range' => 24],
    ]);

    $page = is_int($page) ? $page : 1;
    $perPage = is_int($perPage) ? $perPage : 12;

    if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
        throw new NjApiHttpException(400, 'invalid_series');
    }

    $pdo = nj_db();
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $users = nj_table('users');
    $offset = ($page - 1) * $perPage;

    $seriesStatement = $pdo->prepare(<<<SQL
SELECT
    p.ID AS post_id,
    COALESCE(name_meta.meta_value, '') AS series_name
FROM {$posts} p
INNER JOIN {$postmeta} slug_meta
    ON slug_meta.post_id = p.ID
    AND slug_meta.meta_key = '_nj_series_slug'
    AND slug_meta.meta_value = :slug
LEFT JOIN {$postmeta} name_meta
    ON name_meta.post_id = p.ID
    AND name_meta.meta_key = '_nj_series_name'
WHERE
    p.post_type = 'post'
    AND p.post_status = 'publish'
    AND p.post_password = ''
ORDER BY p.post_date DESC, p.ID DESC
LIMIT 1
SQL);
    $seriesStatement->execute(['slug' => $slug]);
    $seriesRow = $seriesStatement->fetch();

    if (!$seriesRow) {
        throw new NjApiHttpException(404, 'series_not_found');
    }

    $countStatement = $pdo->prepare(<<<SQL
SELECT COUNT(DISTINCT p.ID)
FROM {$posts} p
INNER JOIN {$postmeta} slug_meta
    ON slug_meta.post_id = p.ID
    AND slug_meta.meta_key = '_nj_series_slug'
    AND slug_meta.meta_value = :slug
WHERE
    p.post_type = 'post'
    AND p.post_status = 'publish'
    AND p.post_password = ''
SQL);
    $countStatement->execute(['slug' => $slug]);
    $total = (int) $countStatement->fetchColumn();

    $select = nj_content_article_select($posts, $postmeta, $users);
    $sql = $select . <<<SQL

INNER JOIN {$postmeta} series_slug
    ON series_slug.post_id = p.ID
    AND series_slug.meta_key = '_nj_series_slug'
    AND series_slug.meta_value = :slug
LEFT JOIN {$postmeta} series_order
    ON series_order.post_id = p.ID
    AND series_order.meta_key = '_nj_series_order'
WHERE
    p.post_type = 'post'
    AND p.post_status = 'publish'
    AND p.post_password = ''
ORDER BY
    CAST(COALESCE(NULLIF(series_order.meta_value, ''), '999') AS UNSIGNED) ASC,
    p.post_date ASC,
    p.ID ASC
LIMIT {$perPage} OFFSET {$offset}
SQL;

    $statement = $pdo->prepare($sql);
    $statement->execute(['slug' => $slug]);
    $items = nj_content_hydrate_articles($pdo, $statement->fetchAll());

    $totalPages = max(1, (int) ceil($total / $perPage));
    $name = nj_content_clean_text_source((string) ($seriesRow['series_name'] ?? ''));

    return [
        'series' => [
            'name' => $name !== '' ? $name : str_replace('-', ' ', $slug),
            'slug' => $slug,
            'url' => '/dossie/' . rawurlencode($slug),
            'count' => $total,
        ],
        'items' => $items,
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
            'hasPrevious' => $page > 1,
            'hasNext' => $page < $totalPages,
        ],
    ];
}, 'public, max-age=30, stale-while-revalidate=120');
