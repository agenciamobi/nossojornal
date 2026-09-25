<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

nj_run(static function (): array {
    $pdo = nj_db();

    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $relationships = nj_table('term_relationships');
    $posts = nj_table('posts');

    $sql = <<<SQL
SELECT
    t.term_id AS id,
    tt.term_taxonomy_id AS taxonomy_id,
    t.name,
    t.slug,
    tt.parent AS parent_id,
    tt.count AS legacy_count,
    COUNT(DISTINCT CASE WHEN p.ID IS NOT NULL THEN p.ID END) AS published_count,
    MAX(CASE WHEN p.ID IS NOT NULL THEN p.post_date END) AS latest_published_at
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
    AND tt.taxonomy = 'category'
LEFT JOIN {$relationships} tr
    ON tr.term_taxonomy_id = tt.term_taxonomy_id
LEFT JOIN {$posts} p
    ON p.ID = tr.object_id
    AND p.post_type = 'post'
    AND p.post_status = 'publish'
GROUP BY
    t.term_id,
    tt.term_taxonomy_id,
    t.name,
    t.slug,
    tt.parent,
    tt.count
ORDER BY
    CASE WHEN tt.parent = 0 THEN 0 ELSE 1 END,
    t.name ASC
SQL;

    $rows = $pdo->query($sql)->fetchAll();
    $includeEmpty = ($_GET['include_empty'] ?? '1') !== '0';

    $items = [];
    foreach ($rows as $row) {
        $publishedCount = (int) $row['published_count'];
        if (!$includeEmpty && $publishedCount === 0) {
            continue;
        }

        $parentId = (int) $row['parent_id'];

        $items[] = [
            'id' => (int) $row['id'],
            'taxonomyId' => (int) $row['taxonomy_id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'parentId' => $parentId > 0 ? $parentId : null,
            'url' => '/categoria/' . rawurlencode((string) $row['slug']),
            'legacyCount' => (int) $row['legacy_count'],
            'publishedCount' => $publishedCount,
            'latestPublishedAt' => $row['latest_published_at'] !== null
                ? (string) $row['latest_published_at']
                : null,
        ];
    }

    $rootCount = 0;
    $withPublishedPosts = 0;
    foreach ($items as $item) {
        if ($item['parentId'] === null) {
            $rootCount++;
        }
        if ($item['publishedCount'] > 0) {
            $withPublishedPosts++;
        }
    }

    return [
        'items' => $items,
        'tree' => nj_build_category_tree($items),
        'summary' => [
            'total' => count($items),
            'rootCount' => $rootCount,
            'withPublishedPosts' => $withPublishedPosts,
            'includeEmpty' => $includeEmpty,
        ],
    ];
}, 'public, max-age=60, stale-while-revalidate=300');
