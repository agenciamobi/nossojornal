<?php
declare(strict_types=1);

require_once __DIR__ . '/_admin.php';

function nj_post_category_policy(PDO $pdo, array $categoryIds): array
{
    $categoryIds = array_values(array_unique(array_filter(array_map(
        static fn (mixed $value): int => (int) $value,
        $categoryIds
    ))));

    if (count($categoryIds) > 2) {
        throw new NjApiHttpException(422, 'too_many_categories');
    }

    if ($categoryIds === []) {
        return [
            'categoryIds' => [],
            'taxonomyIds' => [],
            'primaryCategoryId' => 0,
            'editorialCategoryIds' => [],
            'regionalCategoryIds' => [],
        ];
    }

    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));

    $statement = $pdo->prepare(<<<SQL
SELECT
    tt.term_id,
    tt.term_taxonomy_id,
    tt.parent AS parent_id,
    t.slug,
    COALESCE(parent_t.slug, '') AS parent_slug
FROM {$taxonomy} tt
INNER JOIN {$terms} t
    ON t.term_id = tt.term_id
LEFT JOIN {$taxonomy} parent_tt
    ON parent_tt.term_id = tt.parent
    AND parent_tt.taxonomy = 'category'
LEFT JOIN {$terms} parent_t
    ON parent_t.term_id = parent_tt.term_id
WHERE
    tt.taxonomy = 'category'
    AND tt.term_id IN ({$placeholders})
SQL);
    $statement->execute($categoryIds);

    $taxonomyIds = [];
    $details = [];

    foreach ($statement->fetchAll() as $row) {
        $termId = (int) $row['term_id'];
        $taxonomyIds[$termId] = (int) $row['term_taxonomy_id'];
        $details[$termId] = [
            'slug' => strtolower((string) $row['slug']),
            'parentSlug' => strtolower((string) $row['parent_slug']),
        ];
    }

    if (count($taxonomyIds) !== count($categoryIds)) {
        throw new NjApiHttpException(422, 'invalid_categories');
    }

    $technicalSlugs = [
        'capa' => true,
        'outros' => true,
        'cobertura-regional' => true,
        'eleicoes-2024' => true,
    ];

    $editorialCategoryIds = [];
    $regionalCategoryIds = [];

    foreach ($categoryIds as $categoryId) {
        $item = $details[$categoryId] ?? null;
        if (!is_array($item)) {
            throw new NjApiHttpException(422, 'invalid_categories');
        }

        if (isset($technicalSlugs[(string) $item['slug']])) {
            throw new NjApiHttpException(422, 'technical_category_not_allowed');
        }

        if ((string) $item['parentSlug'] === 'cobertura-regional') {
            $regionalCategoryIds[] = $categoryId;
            continue;
        }

        $editorialCategoryIds[] = $categoryId;
    }

    if (count($editorialCategoryIds) > 1) {
        throw new NjApiHttpException(422, 'multiple_editorial_categories');
    }

    if (count($regionalCategoryIds) > 1) {
        throw new NjApiHttpException(422, 'multiple_regional_categories');
    }

    $primaryCategoryId = $editorialCategoryIds[0]
        ?? $regionalCategoryIds[0]
        ?? 0;

    return [
        'categoryIds' => $categoryIds,
        'taxonomyIds' => $taxonomyIds,
        'primaryCategoryId' => $primaryCategoryId,
        'editorialCategoryIds' => $editorialCategoryIds,
        'regionalCategoryIds' => $regionalCategoryIds,
    ];
}

function nj_post_selectable_taxonomy(PDO $pdo): array
{
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');

    $rows = $pdo->query(<<<SQL
SELECT
    t.term_id AS id,
    t.name,
    t.slug,
    COALESCE(parent_t.slug, '') AS parent_slug
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
    AND tt.taxonomy = 'category'
LEFT JOIN {$terms} parent_t
    ON parent_t.term_id = tt.parent
WHERE t.slug <> ''
ORDER BY t.name ASC
LIMIT 250
SQL)->fetchAll();

    $technicalSlugs = [
        'capa' => true,
        'outros' => true,
        'cobertura-regional' => true,
        'eleicoes-2024' => true,
    ];

    $items = [];

    foreach ($rows as $row) {
        $slug = strtolower((string) $row['slug']);
        if (isset($technicalSlugs[$slug])) {
            continue;
        }

        $items[] = [
            'id' => (int) $row['id'],
            'slug' => $slug,
            'name' => (string) $row['name'],
            'kind' => strtolower((string) $row['parent_slug']) === 'cobertura-regional'
                ? 'regional'
                : 'editorial',
        ];
    }

    return $items;
}
