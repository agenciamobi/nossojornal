<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_content.php';

nj_run(static function (): array {
    $pdo = nj_db();
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $users = nj_table('users');

    $requestedLimit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, [
        'options' => [
            'default' => 6,
            'min_range' => 1,
            'max_range' => 20,
        ],
    ]);

    $limit = is_int($requestedLimit) ? $requestedLimit : 6;
    $limit = max(1, min(20, $limit));

    $select = nj_content_article_select($posts, $postmeta, $users);
    $sql = $select . <<<SQL

WHERE
    p.post_type = 'post'
    AND p.post_status = 'publish'
    AND p.post_password = ''
    AND p.post_title <> ''
    AND p.post_name <> ''
ORDER BY
    p.post_date DESC,
    p.ID DESC
LIMIT {$limit}
SQL;

    $articles = nj_content_hydrate_articles($pdo, $pdo->query($sql)->fetchAll());
    $items = array_map(
        static fn (array $article): array => [
            'id' => (int) $article['id'],
            'title' => (string) $article['title'],
            'slug' => (string) $article['slug'],
            'url' => (string) $article['url'],
            'publishedAt' => (string) $article['publishedAt'],
            'modifiedAt' => (string) $article['modifiedAt'],
            'primaryCategory' => $article['primaryCategory'],
        ],
        $articles
    );

    return [
        'items' => $items,
        'count' => count($items),
        'limit' => $limit,
    ];
}, 'public, max-age=10, stale-while-revalidate=30');
