<?php
declare(strict_types=1);

function nj_content_excerpt(string $excerpt, string $content, int $maxLength = 240): string
{
    $source = trim($excerpt) !== '' ? $excerpt : $content;
    $text = html_entity_decode(strip_tags($source), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\\s+/u', ' ', trim($text)) ?? trim($text);

    if ($text === '') {
        return '';
    }

    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length <= $maxLength) {
        return $text;
    }

    $cut = function_exists('mb_substr')
        ? mb_substr($text, 0, $maxLength - 1, 'UTF-8')
        : substr($text, 0, $maxLength - 1);

    return rtrim($cut, " \\t\\n\\r\\0\\x0B,.;:!?-") . '…';
}

function nj_content_local_media_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $path = parse_url($url, PHP_URL_PATH);
    if (is_string($path) && str_starts_with($path, '/wp-content/uploads/')) {
        return $path;
    }

    return $url;
}

function nj_content_sanitize_html(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    $html = preg_replace('#<(script|style|form|input|button|textarea|select|object|embed|iframe)[^>]*>.*?</\\1>#is', '', $html) ?? $html;
    $html = preg_replace('#<(script|style|form|input|button|textarea|select|object|embed|iframe)[^>]*/?>#is', '', $html) ?? $html;
    $html = preg_replace('/\\son[a-z]+\\s*=\\s*("[^"]*"|\'[^\']*\'|[^\\s>]+)/i', '', $html) ?? $html;
    $html = preg_replace('/\\sstyle\\s*=\\s*("[^"]*"|\'[^\']*\')/i', '', $html) ?? $html;
    $html = preg_replace_callback(
        '/\\s(href|src)\\s*=\\s*(["\'])(.*?)\\2/i',
        static function (array $matches): string {
            $attribute = strtolower($matches[1]);
            $quote = $matches[2];
            $value = trim(html_entity_decode($matches[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (preg_match('#^(javascript|data):#i', $value)) {
                return '';
            }

            if ($attribute === 'src') {
                $value = nj_content_local_media_url($value);
            }

            return ' ' . $attribute . '=' . $quote . htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') . $quote;
        },
        $html
    ) ?? $html;

    return $html;
}

function nj_content_categories_for_posts(PDO $pdo, array $postIds): array
{
    if ($postIds === []) {
        return [];
    }

    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $relationships = nj_table('term_relationships');
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));

    $sql = <<<SQL
SELECT
    tr.object_id AS post_id,
    t.term_id AS id,
    tt.term_taxonomy_id AS taxonomy_id,
    t.name,
    t.slug,
    tt.parent AS parent_id
FROM {$relationships} tr
INNER JOIN {$taxonomy} tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
    AND tt.taxonomy = 'category'
INNER JOIN {$terms} t
    ON t.term_id = tt.term_id
WHERE tr.object_id IN ({$placeholders})
ORDER BY t.name ASC
SQL;

    $statement = $pdo->prepare($sql);
    $statement->execute($postIds);
    $rows = $statement->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $result[(int) $row['post_id']][] = [
            'id' => (int) $row['id'],
            'taxonomyId' => (int) $row['taxonomy_id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'parentId' => (int) $row['parent_id'] > 0 ? (int) $row['parent_id'] : null,
            'url' => '/categoria/' . rawurlencode((string) $row['slug']),
        ];
    }

    return $result;
}

function nj_content_primary_category(array $categories, int $primaryId): ?array
{
    $technical = [
        'capa' => true,
        'geral' => true,
        'outros' => true,
        'eleicoes-2024' => true,
        'cobertura-regional' => true,
    ];

    foreach ($categories as $category) {
        if ($category['id'] === $primaryId && !isset($technical[$category['slug']])) {
            return $category;
        }
    }

    foreach ($categories as $category) {
        if (!isset($technical[$category['slug']])) {
            return $category;
        }
    }

    return $categories[0] ?? null;
}

function nj_content_hydrate_articles(PDO $pdo, array $rows, bool $includeBody = false): array
{
    if ($rows === []) {
        return [];
    }

    $postIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $categoriesByPost = nj_content_categories_for_posts($pdo, $postIds);
    $articles = [];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $categories = $categoriesByPost[$id] ?? [];
        $primary = nj_content_primary_category($categories, (int) ($row['primary_category_id'] ?? 0));
        $title = trim(html_entity_decode(strip_tags((string) $row['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $article = [
            'id' => $id,
            'title' => $title,
            'slug' => (string) $row['slug'],
            'url' => '/noticia/' . rawurlencode((string) $row['slug']),
            'excerpt' => nj_content_excerpt((string) ($row['excerpt'] ?? ''), (string) ($row['content'] ?? '')),
            'publishedAt' => (string) $row['published_at'],
            'modifiedAt' => (string) $row['modified_at'],
            'author' => [
                'id' => (int) ($row['author_id'] ?? 0),
                'name' => trim((string) ($row['author_name'] ?? '')),
            ],
            'featuredImage' => trim((string) ($row['featured_image_url'] ?? '')) !== ''
                ? [
                    'url' => nj_content_local_media_url((string) $row['featured_image_url']),
                    'alt' => trim((string) ($row['featured_image_alt'] ?? '')) !== ''
                        ? (string) $row['featured_image_alt']
                        : $title,
                ]
                : null,
            'views' => (int) ($row['views'] ?? 0),
            'primaryCategory' => $primary,
            'categories' => $categories,
        ];

        if ($includeBody) {
            $article['contentHtml'] = nj_content_sanitize_html((string) ($row['content'] ?? ''));
        }

        $articles[] = $article;
    }

    return $articles;
}

function nj_content_article_select(string $posts, string $postmeta, string $users): string
{
    return <<<SQL
SELECT
    p.ID AS id,
    p.post_author AS author_id,
    p.post_title AS title,
    p.post_name AS slug,
    p.post_excerpt AS excerpt,
    p.post_content AS content,
    p.post_date AS published_at,
    p.post_modified AS modified_at,
    COALESCE(u.display_name, '') AS author_name,
    COALESCE((
        SELECT a.guid
        FROM {$postmeta} thumb
        INNER JOIN {$posts} a
            ON a.ID = CAST(thumb.meta_value AS UNSIGNED)
            AND a.post_type = 'attachment'
        WHERE thumb.post_id = p.ID AND thumb.meta_key = '_thumbnail_id'
        LIMIT 1
    ), '') AS featured_image_url,
    COALESCE((
        SELECT alt.meta_value
        FROM {$postmeta} thumb2
        INNER JOIN {$postmeta} alt
            ON alt.post_id = CAST(thumb2.meta_value AS UNSIGNED)
            AND alt.meta_key = '_wp_attachment_image_alt'
        WHERE thumb2.post_id = p.ID AND thumb2.meta_key = '_thumbnail_id'
        LIMIT 1
    ), '') AS featured_image_alt,
    COALESCE((
        SELECT CAST(pm_views.meta_value AS UNSIGNED)
        FROM {$postmeta} pm_views
        WHERE pm_views.post_id = p.ID AND pm_views.meta_key = 'views'
        ORDER BY pm_views.meta_id DESC
        LIMIT 1
    ), 0) AS views,
    COALESCE((
        SELECT CAST(pm_primary.meta_value AS UNSIGNED)
        FROM {$postmeta} pm_primary
        WHERE pm_primary.post_id = p.ID AND pm_primary.meta_key = '_yoast_wpseo_primary_category'
        ORDER BY pm_primary.meta_id DESC
        LIMIT 1
    ), 0) AS primary_category_id
FROM {$posts} p
LEFT JOIN {$users} u ON u.ID = p.post_author
SQL;
}
