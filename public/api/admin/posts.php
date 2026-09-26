<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_posts');

    $pdo = nj_db();
    $posts = nj_table('posts');
    $users = nj_table('users');
    $relationships = nj_table('term_relationships');
    $taxonomy = nj_table('term_taxonomy');
    $terms = nj_table('terms');

    $status = trim((string) ($_GET['status'] ?? 'all'));
    $allowedStatuses = ['all', 'publish', 'draft', 'pending', 'future', 'private', 'trash'];

    if (!in_array($status, $allowedStatuses, true)) {
        throw new NjApiHttpException(400, 'invalid_status');
    }

    $search = trim((string) ($_GET['q'] ?? ''));
    if (function_exists('mb_substr')) {
        $search = mb_substr($search, 0, 100, 'UTF-8');
    } else {
        $search = substr($search, 0, 100);
    }

    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, [
        'options' => ['default' => 1, 'min_range' => 1],
    ]);
    $page = is_int($page) ? $page : 1;
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $where = ["p.post_type = 'post'"];
    $params = [];

    if ($status !== 'all') {
        $where[] = 'p.post_status = :status';
        $params['status'] = $status;
    }

    if ($search !== '') {
        $where[] = '(p.post_title LIKE :search_title OR p.post_content LIKE :search_content)';
        $needle = '%' . $search . '%';
        $params['search_title'] = $needle;
        $params['search_content'] = $needle;
    }

    $whereSql = implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(*) FROM {$posts} p WHERE {$whereSql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $sql = <<<SQL
SELECT
    p.ID AS id,
    p.post_title AS title,
    p.post_name AS slug,
    p.post_status AS status,
    p.post_date AS published_at,
    p.post_modified AS modified_at,
    p.post_author AS author_id,
    COALESCE(u.display_name, u.user_login, '') AS author_name
FROM {$posts} p
LEFT JOIN {$users} u ON u.ID = p.post_author
WHERE {$whereSql}
ORDER BY p.post_modified DESC, p.ID DESC
LIMIT {$perPage} OFFSET {$offset}
SQL;

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();

    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $categoriesByPost = [];

    if ($ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $categoryStatement = $pdo->prepare(<<<SQL
SELECT
    tr.object_id AS post_id,
    t.term_id AS id,
    t.name,
    t.slug
FROM {$relationships} tr
INNER JOIN {$taxonomy} tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
    AND tt.taxonomy = 'category'
INNER JOIN {$terms} t ON t.term_id = tt.term_id
WHERE tr.object_id IN ({$placeholders})
ORDER BY t.name ASC
SQL);
        $categoryStatement->execute($ids);

        foreach ($categoryStatement->fetchAll() as $categoryRow) {
            $categoriesByPost[(int) $categoryRow['post_id']][] = [
                'id' => (int) $categoryRow['id'],
                'name' => (string) $categoryRow['name'],
                'slug' => (string) $categoryRow['slug'],
            ];
        }
    }

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'title' => trim((string) $row['title']) !== '' ? (string) $row['title'] : '(sem título)',
            'slug' => (string) $row['slug'],
            'status' => (string) $row['status'],
            'publishedAt' => nj_content_iso8601((string) $row['published_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'author' => [
                'id' => (int) $row['author_id'],
                'name' => (string) $row['author_name'],
            ],
            'categories' => $categoriesByPost[(int) $row['id']] ?? [],
            'publicUrl' => trim((string) $row['slug']) !== ''
                ? '/noticia/' . rawurlencode((string) $row['slug'])
                : null,
        ];
    }

    return [
        'items' => $items,
        'query' => $search,
        'status' => $status,
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ],
        'mode' => 'read_only',
    ];
});
