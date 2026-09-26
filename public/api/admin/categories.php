<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';
require_once __DIR__ . '/../v1/_category_theme.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'manage_categories');

    $pdo = nj_db();
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');

    $rows = $pdo->query(<<<SQL
SELECT
    t.term_id AS id,
    tt.term_taxonomy_id AS taxonomy_id,
    t.name,
    t.slug,
    tt.parent AS parent_id,
    tt.count AS legacy_count
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
        array_map(static fn (array $row): int => (int) $row['id'], $rows)
    );

    $items = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $slug = (string) $row['slug'];

        $items[] = [
            'id' => $id,
            'taxonomyId' => (int) $row['taxonomy_id'],
            'name' => (string) $row['name'],
            'slug' => $slug,
            'parentId' => (int) $row['parent_id'] > 0 ? (int) $row['parent_id'] : null,
            'count' => (int) $row['legacy_count'],
            'color' => nj_category_color_for($id, $slug, $overrides),
            'colorSource' => nj_category_color_source_for($id, $overrides),
            'publicUrl' => '/categoria/' . rawurlencode($slug),
        ];
    }

    return [
        'items' => $items,
        'count' => count($items),
    ];
});
