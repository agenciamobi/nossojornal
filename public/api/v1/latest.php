<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_content.php';

nj_run(static function (): array {
    $pdo = nj_db();
    $posts = nj_table('posts');

    $requestedLimit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, [
        'options' => [
            'default' => 6,
            'min_range' => 1,
            'max_range' => 20,
        ],
    ]);

    $limit = is_int($requestedLimit) ? $requestedLimit : 6;
    $limit = max(1, min(20, $limit));

    $sql = <<<SQL
SELECT
    ID AS id,
    post_title AS title,
    post_name AS slug,
    post_date AS published_at,
    post_modified AS modified_at
FROM {$posts}
WHERE
    post_type = 'post'
    AND post_status = 'publish'
    AND post_password = ''
    AND post_title <> ''
    AND post_name <> ''
ORDER BY
    post_date DESC,
    ID DESC
LIMIT {$limit}
SQL;

    $rows = $pdo->query($sql)->fetchAll();
    $items = [];

    foreach ($rows as $row) {
        $slug = (string) $row['slug'];
        $title = trim(strip_tags(html_entity_decode(
            (string) $row['title'],
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        )));

        if ($title === '') {
            continue;
        }

        $items[] = [
            'id' => (int) $row['id'],
            'title' => $title,
            'slug' => $slug,
            'url' => '/noticia/' . rawurlencode($slug),
            'publishedAt' => nj_content_iso8601((string) $row['published_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
        ];
    }

    return [
        'items' => $items,
        'count' => count($items),
        'limit' => $limit,
    ];
}, 'public, max-age=10, stale-while-revalidate=30');
