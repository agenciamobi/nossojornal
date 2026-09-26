<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';
require dirname(__DIR__) . '/_content.php';

nj_system_require_user('posts');
$pdo = nj_db();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(50, max(5, (int) ($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;
$status = trim((string) ($_GET['status'] ?? 'all'));
$search = trim((string) ($_GET['q'] ?? ''));

$allowedStatuses = ['all', 'publish', 'draft', 'pending', 'future', 'private', 'trash'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

$posts = nj_table('posts');
$users = nj_table('users');
$postmeta = nj_table('postmeta');

$where = ["p.post_type = 'post'"];
$params = [];

if ($status !== 'all') {
    $where[] = 'p.post_status = :status';
    $params['status'] = $status;
}

if ($search !== '') {
    $where[] = '(p.post_title LIKE :search_title OR p.post_name LIKE :search_slug OR p.post_content LIKE :search_content)';
    $term = '%' . $search . '%';
    $params['search_title'] = $term;
    $params['search_slug'] = $term;
    $params['search_content'] = $term;
}

$whereSql = implode("
    AND ", $where);

$countStatement = $pdo->prepare("SELECT COUNT(*) FROM {$posts} p WHERE {$whereSql}");
$countStatement->execute($params);
$total = (int) $countStatement->fetchColumn();

$sql = <<<SQL
SELECT
    p.ID AS id,
    p.post_author AS author_id,
    p.post_title AS title,
    p.post_name AS slug,
    p.post_status AS status,
    p.post_date AS published_at,
    p.post_modified AS modified_at,
    COALESCE(u.display_name, '') AS author_name,
    COALESCE((
        SELECT CAST(pm_views.meta_value AS UNSIGNED)
        FROM {$postmeta} pm_views
        WHERE pm_views.post_id = p.ID AND pm_views.meta_key = 'views'
        ORDER BY pm_views.meta_id DESC
        LIMIT 1
    ), 0) AS views
FROM {$posts} p
LEFT JOIN {$users} u ON u.ID = p.post_author
WHERE {$whereSql}
ORDER BY p.post_modified DESC, p.ID DESC
LIMIT {$perPage} OFFSET {$offset}
SQL;

$statement = $pdo->prepare($sql);
$statement->execute($params);
$rows = $statement->fetchAll();

$postIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
$categoriesByPost = nj_content_categories_for_posts($pdo, $postIds);

$items = [];
foreach ($rows as $row) {
    $id = (int) $row['id'];
    $items[] = [
        'id' => $id,
        'title' => trim((string) $row['title']) !== '' ? (string) $row['title'] : '(sem título)',
        'slug' => (string) $row['slug'],
        'status' => (string) $row['status'],
        'publishedAt' => nj_content_iso8601((string) $row['published_at']),
        'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
        'author' => [
            'id' => (int) $row['author_id'],
            'name' => (string) $row['author_name'],
        ],
        'views' => (int) $row['views'],
        'categories' => $categoriesByPost[$id] ?? [],
        'publicUrl' => (string) $row['slug'] !== ''
            ? '/noticia/' . rawurlencode((string) $row['slug'])
            : null,
    ];
}

nj_system_json([
    'ok' => true,
    'data' => [
        'items' => $items,
        'filters' => [
            'status' => $status,
            'query' => $search,
        ],
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ],
    ],
]);
