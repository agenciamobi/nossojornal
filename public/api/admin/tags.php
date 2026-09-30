<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'manage_categories');

    $pdo = nj_db();
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');

    $query = trim((string) ($_GET['q'] ?? ''));
    $query = function_exists('mb_substr')
        ? mb_substr($query, 0, 120, 'UTF-8')
        : substr($query, 0, 120);

    $where = "WHERE tt.taxonomy = 'post_tag'";
    $params = [];

    if ($query !== '') {
        $where .= " AND (t.name LIKE :search_name OR t.slug LIKE :search_slug OR tt.description LIKE :search_description)";
        $needle = '%' . $query . '%';
        $params = [
            'search_name' => $needle,
            'search_slug' => $needle,
            'search_description' => $needle,
        ];
    }

    $statement = $pdo->prepare(<<<SQL
SELECT
    t.term_id AS id,
    tt.term_taxonomy_id AS taxonomy_id,
    t.name,
    t.slug,
    tt.description,
    tt.count
FROM {$terms} t
INNER JOIN {$taxonomy} tt ON tt.term_id = t.term_id
{$where}
ORDER BY t.name ASC
SQL);
    $statement->execute($params);
    $rows = $statement->fetchAll();

    $total = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$taxonomy} WHERE taxonomy = 'post_tag'"
    )->fetchColumn();

    return [
        'items' => array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'taxonomyId' => (int) $row['taxonomy_id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'description' => (string) $row['description'],
                'count' => (int) $row['count'],
            ],
            $rows
        ),
        'count' => count($rows),
        'total' => $total,
        'query' => $query,
    ];
});
