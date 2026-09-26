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
        throw new NjApiHttpException(400, 'invalid_page_slug');
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
        throw new NjApiHttpException(404, 'page_not_found');
    }

    $contentHtml = nj_content_sanitize_html((string) $row['content']);
    $contacts = [];

    if ($slug === 'contato') {
        preg_match_all(
            "~<a\\b[^>]*href=([\"'])(https://wa\\.me/[^\"']+|mailto:[^\"']+|tel:[^\"']+)\\1[^>]*>(.*?)</a>~is",
            $contentHtml,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $href = html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = preg_replace('/\\s+/u', ' ', trim(strip_tags((string) $match[3]))) ?? '';
            $type = str_starts_with($href, 'mailto:')
                ? 'email'
                : (str_starts_with($href, 'tel:') ? 'phone' : 'whatsapp');
            $value = $type === 'email'
                ? preg_replace('/^mailto:/i', '', $href)
                : ($type === 'phone' ? preg_replace('/^tel:/i', '', $href) : preg_replace('#^https://wa\\.me/#i', '', $href));

            $key = $type . ':' . $value;
            $contacts[$key] = [
                'type' => $type,
                'href' => $href,
                'value' => $value,
                'label' => $label !== '' ? $label : $value,
            ];
        }
    }

    return [
        'page' => [
            'id' => (int) $row['id'],
            'title' => trim(html_entity_decode(strip_tags((string) $row['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'slug' => $slug,
            'legacySlug' => (string) $row['slug'],
            'contentHtml' => $contentHtml,
            'excerpt' => nj_content_excerpt((string) $row['excerpt'], (string) $row['content']),
            'modifiedAt' => (string) $row['modified_at'],
            'contacts' => array_values($contacts),
        ],
    ];
}, 'public, max-age=300, stale-while-revalidate=900');
