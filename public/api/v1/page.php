<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_content.php';

nj_run(static function (): array {
    $slug = trim((string) ($_GET['slug'] ?? ''));
    $aliases = [
        'sobre' => 'quem-somos',
    ];
    $legacySlug = $aliases[$slug] ?? $slug;

    if ($legacySlug === '' || !preg_match('/^[a-z0-9-]+$/', $legacySlug)) {
        nj_json_response(400, [
            'ok' => false,
            'error' => ['code' => 'invalid_page_slug'],
        ]);
    }

    $pdo = nj_db();
    $posts = nj_table('posts');

    $statement = $pdo->prepare(<<<SQL
SELECT
    ID AS id,
    post_title AS title,
    post_name AS slug,
    post_content AS content,
    post_excerpt AS excerpt,
    post_date AS published_at,
    post_modified AS modified_at
FROM {$posts}
WHERE
    post_type = 'page'
    AND post_status = 'publish'
    AND post_name = :slug
LIMIT 1
SQL);
    $statement->execute(['slug' => $legacySlug]);
    $row = $statement->fetch();

    if (!$row) {
        nj_json_response(404, [
            'ok' => false,
            'error' => ['code' => 'page_not_found'],
        ]);
    }

    return [
        'page' => [
            'id' => (int) $row['id'],
            'title' => trim(html_entity_decode(strip_tags((string) $row['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'slug' => $slug,
            'legacySlug' => (string) $row['slug'],
            'contentHtml' => nj_content_sanitize_html((string) $row['content']),
            'excerpt' => nj_content_excerpt((string) $row['excerpt'], (string) $row['content']),
            'modifiedAt' => (string) $row['modified_at'],
        ],
    ];
}, 'public, max-age=300, stale-while-revalidate=900');
