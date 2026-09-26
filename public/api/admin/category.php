<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';
require_once __DIR__ . '/../v1/_category_theme.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'manage_categories');

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if (!is_int($id) || $id <= 0) {
        throw new NjApiHttpException(400, 'invalid_category_id');
    }

    $pdo = nj_db();
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');

    $statement = $pdo->prepare(<<<SQL
SELECT
    t.term_id AS id,
    tt.term_taxonomy_id AS taxonomy_id,
    t.name,
    t.slug,
    tt.description,
    tt.parent AS parent_id,
    tt.count AS legacy_count
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
    AND tt.taxonomy = 'category'
WHERE t.term_id = :id
LIMIT 1
SQL);
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    if (!$row) {
        throw new NjApiHttpException(404, 'category_not_found');
    }

    $allRows = $pdo->query(<<<SQL
SELECT
    t.term_id AS id,
    t.name,
    t.slug,
    tt.parent AS parent_id
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
    AND tt.taxonomy = 'category'
ORDER BY
    CASE WHEN tt.parent = 0 THEN 0 ELSE 1 END,
    t.name ASC
SQL)->fetchAll();

    $overrides = nj_category_color_overrides(
        $pdo,
        array_map(static fn (array $category): int => (int) $category['id'], $allRows)
    );

    $category = [
        'id' => (int) $row['id'],
        'taxonomyId' => (int) $row['taxonomy_id'],
        'name' => (string) $row['name'],
        'slug' => (string) $row['slug'],
        'description' => (string) $row['description'],
        'parentId' => (int) $row['parent_id'] > 0 ? (int) $row['parent_id'] : null,
        'count' => (int) $row['legacy_count'],
        'color' => nj_category_color_for(
            (int) $row['id'],
            (string) $row['slug'],
            $overrides
        ),
        'colorSource' => nj_category_color_source_for(
            (int) $row['id'],
            $overrides
        ),
        'publicUrl' => '/categoria/' . rawurlencode((string) $row['slug']),
    ];

    $parents = array_values(array_filter(array_map(
        static fn (array $item): ?array => (int) $item['id'] === $id
            ? null
            : [
                'id' => (int) $item['id'],
                'name' => (string) $item['name'],
                'slug' => (string) $item['slug'],
                'parentId' => (int) $item['parent_id'] > 0 ? (int) $item['parent_id'] : null,
            ],
        $allRows
    )));

    return [
        'category' => $category,
        'parents' => $parents,
    ];
});
