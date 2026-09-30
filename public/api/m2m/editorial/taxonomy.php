<?php
declare(strict_types=1);

require __DIR__ . '/_m2m.php';

nj_m2m_run('GET', 'editorial.taxonomy.list', static function (array $context): array {
    $pdo = $context['pdo'];
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');

    $categories = $pdo->query(
        "SELECT t.name, t.slug
         FROM {$terms} t
         INNER JOIN {$taxonomy} tt
           ON tt.term_id = t.term_id
          AND tt.taxonomy = 'category'
         WHERE t.slug <> ''
         ORDER BY t.name ASC
         LIMIT 200"
    )->fetchAll();

    $tags = $pdo->query(
        "SELECT t.name
         FROM {$terms} t
         INNER JOIN {$taxonomy} tt
           ON tt.term_id = t.term_id
          AND tt.taxonomy = 'post_tag'
         WHERE t.name <> ''
         ORDER BY tt.count DESC, t.name ASC
         LIMIT 300"
    )->fetchAll(PDO::FETCH_COLUMN);

    return [
        'categories' => array_map(
            static fn (array $row): array => [
                'slug' => strtolower((string) $row['slug']),
                'name' => (string) $row['name'],
            ],
            $categories
        ),
        'tags' => array_values(array_map('strval', $tags)),
    ];
});
