<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_content.php';

function nj_home_category_rank(string $slug): int
{
    static $order = [
        'hulha-negra' => 10,
        'politica' => 20,
        'seguranca' => 30,
        'economia' => 40,
        'educacao' => 50,
        'rural' => 60,
        'esportes' => 70,
        'cultura' => 80,
        'saude' => 90,
        'rio-grande-do-sul' => 100,
        'brasil' => 110,
        'internacional' => 120,
        'bage' => 130,
        'acegua' => 140,
        'candiota' => 150,
        'dom-pedrito' => 160,
        'herval' => 170,
        'pedras-altas' => 180,
        'pinheiro-machado' => 190,
        'piratini' => 200,
    ];

    return $order[$slug] ?? 1000;
}

nj_run(static function (): array {
    $pdo = nj_db();

    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');
    $users = nj_table('users');
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $relationships = nj_table('term_relationships');

    $sql = <<<SQL
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
        WHERE
            thumb.post_id = p.ID
            AND thumb.meta_key = '_thumbnail_id'
        LIMIT 1
    ), '') AS featured_image_url,
    COALESCE((
        SELECT alt.meta_value
        FROM {$postmeta} thumb2
        INNER JOIN {$postmeta} alt
            ON alt.post_id = CAST(thumb2.meta_value AS UNSIGNED)
            AND alt.meta_key = '_wp_attachment_image_alt'
        WHERE
            thumb2.post_id = p.ID
            AND thumb2.meta_key = '_thumbnail_id'
        LIMIT 1
    ), '') AS featured_image_alt,
    COALESCE((
        SELECT CAST(pm_views.meta_value AS UNSIGNED)
        FROM {$postmeta} pm_views
        WHERE
            pm_views.post_id = p.ID
            AND pm_views.meta_key = 'views'
        ORDER BY pm_views.meta_id DESC
        LIMIT 1
    ), 0) AS views,
    COALESCE((
        SELECT CAST(pm_primary.meta_value AS UNSIGNED)
        FROM {$postmeta} pm_primary
        WHERE
            pm_primary.post_id = p.ID
            AND pm_primary.meta_key = '_yoast_wpseo_primary_category'
        ORDER BY pm_primary.meta_id DESC
        LIMIT 1
    ), 0) AS primary_category_id
FROM {$posts} p
LEFT JOIN {$users} u
    ON u.ID = p.post_author
WHERE
    p.post_type = 'post'
    AND p.post_status = 'publish'
    AND p.post_password = ''
    AND p.post_title <> ''
    AND p.post_name <> ''
ORDER BY
    p.post_date DESC,
    p.ID DESC
LIMIT 120
SQL;

    $rows = $pdo->query($sql)->fetchAll();

    if ($rows === []) {
        return [
            'hero' => null,
            'latest' => [],
            'mostRead' => [],
            'sections' => [],
            'summary' => [
                'publishedArticles' => 0,
                'sectionCount' => 0,
                'heroSelection' => 'none',
            ],
        ];
    }

    $postIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));

    $categorySql = <<<SQL
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

    $categoryStatement = $pdo->prepare($categorySql);
    $categoryStatement->execute($postIds);
    $categoryRows = $categoryStatement->fetchAll();
    $colorOverrides = nj_category_color_overrides(
        $pdo,
        array_map(static fn (array $row): int => (int) $row['id'], $categoryRows)
    );

    $categoriesByPost = [];
    $categoryById = [];
    $categoryUsage = [];

    foreach ($categoryRows as $row) {
        $category = [
            'id' => (int) $row['id'],
            'taxonomyId' => (int) $row['taxonomy_id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'parentId' => (int) $row['parent_id'] > 0 ? (int) $row['parent_id'] : null,
            'url' => '/categoria/' . rawurlencode((string) $row['slug']),
            'color' => nj_category_color_for(
                (int) $row['id'],
                (string) $row['slug'],
                $colorOverrides
            ),
        ];

        $postId = (int) $row['post_id'];
        $categoriesByPost[$postId][] = $category;
        $categoryById[$category['id']] = $category;
        $categoryUsage[$category['slug']] = ($categoryUsage[$category['slug']] ?? 0) + 1;
    }

    $technicalCategorySlugs = [
        'capa' => true,
        'geral' => true,
        'outros' => true,
        'eleicoes-2024' => true,
        'cobertura-regional' => true,
    ];

    $articles = [];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $categories = $categoriesByPost[$id] ?? [];
        $primaryCategoryId = (int) $row['primary_category_id'];
        $primaryCategory = $categoryById[$primaryCategoryId] ?? null;

        if ($primaryCategory === null || isset($technicalCategorySlugs[$primaryCategory['slug']])) {
            foreach ($categories as $category) {
                if (!isset($technicalCategorySlugs[$category['slug']])) {
                    $primaryCategory = $category;
                    break;
                }
            }
        }

        if ($primaryCategory === null && $categories !== []) {
            $primaryCategory = $categories[0];
        }

        $imageUrl = trim((string) $row['featured_image_url']);
        if ($imageUrl !== '') {
            $imagePath = parse_url($imageUrl, PHP_URL_PATH);
            if (is_string($imagePath) && str_starts_with($imagePath, '/wp-content/uploads/')) {
                $imageUrl = $imagePath;
            }
        }

        $articles[] = [
            'id' => $id,
            'title' => trim(html_entity_decode(
                strip_tags((string) $row['title']),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )),
            'slug' => (string) $row['slug'],
            'url' => '/noticia/' . rawurlencode((string) $row['slug']),
            'excerpt' => nj_content_excerpt((string) $row['excerpt'], (string) $row['content'], 210),
            'publishedAt' => nj_content_iso8601((string) $row['published_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'author' => [
                'id' => (int) $row['author_id'],
                'name' => trim((string) $row['author_name']),
            ],
            'featuredImage' => $imageUrl !== ''
                ? [
                    'url' => $imageUrl,
                    'alt' => trim((string) $row['featured_image_alt']) !== ''
                        ? (string) $row['featured_image_alt']
                        : trim(html_entity_decode(strip_tags((string) $row['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                ]
                : null,
            'views' => (int) $row['views'],
            'primaryCategory' => $primaryCategory,
            'categories' => $categories,
        ];
    }

    $hero = null;
    foreach ($articles as $article) {
        foreach ($article['categories'] as $category) {
            if ($category['slug'] === 'capa') {
                $hero = $article;
                break 2;
            }
        }
    }

    $heroSelection = 'capa_category';
    if ($hero === null) {
        $hero = $articles[0];
        $heroSelection = 'latest_fallback';
    }

    $latest = [];
    foreach ($articles as $article) {
        if ($article['id'] === $hero['id']) {
            continue;
        }

        $latest[] = $article;
        if (count($latest) >= 8) {
            break;
        }
    }

    $mostReadPool = array_values(array_filter(
        $articles,
        static fn (array $article): bool => $article['views'] > 0
    ));

    usort($mostReadPool, static function (array $a, array $b): int {
        $viewsComparison = $b['views'] <=> $a['views'];
        if ($viewsComparison !== 0) {
            return $viewsComparison;
        }

        return strcmp($b['publishedAt'], $a['publishedAt']);
    });

    $mostRead = array_slice($mostReadPool, 0, 5);

    $sectionCategories = [];
    foreach ($categoryById as $category) {
        $slug = $category['slug'];

        if (isset($technicalCategorySlugs[$slug])) {
            continue;
        }

        if (($categoryUsage[$slug] ?? 0) < 1) {
            continue;
        }

        $sectionCategories[$slug] = $category;
    }

    uasort($sectionCategories, static function (array $a, array $b): int {
        $rankComparison = nj_home_category_rank($a['slug']) <=> nj_home_category_rank($b['slug']);
        if ($rankComparison !== 0) {
            return $rankComparison;
        }

        return strcasecmp($a['name'], $b['name']);
    });

    $sections = [];
    foreach ($sectionCategories as $slug => $category) {
        $stories = [];

        foreach ($articles as $article) {
            $belongs = false;
            foreach ($article['categories'] as $articleCategory) {
                if ($articleCategory['slug'] === $slug) {
                    $belongs = true;
                    break;
                }
            }

            if (!$belongs) {
                continue;
            }

            $stories[] = $article;
            if (count($stories) >= 4) {
                break;
            }
        }

        if ($stories === []) {
            continue;
        }

        $sections[] = [
            'category' => $category,
            'stories' => $stories,
        ];
    }

    return [
        'hero' => $hero,
        'latest' => $latest,
        'mostRead' => $mostRead,
        'sections' => $sections,
        'summary' => [
            'publishedArticles' => count($articles),
            'sectionCount' => count($sections),
            'heroSelection' => $heroSelection,
        ],
    ];
}, 'public, max-age=10, stale-while-revalidate=30');
