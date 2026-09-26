<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_pages');

    $pdo = nj_db();
    $posts = nj_table('posts');
    $users = nj_table('users');

    $status = trim((string) ($_GET['status'] ?? 'all'));
    $allowedStatuses = ['all', 'publish', 'draft', 'pending', 'future', 'private', 'trash'];

    if (!in_array($status, $allowedStatuses, true)) {
        throw new NjApiHttpException(400, 'invalid_status');
    }

    $search = trim((string) ($_GET['q'] ?? ''));
    if (function_exists('mb_substr')) {
        $search = mb_substr($search, 0, 120, 'UTF-8');
    } else {
        $search = substr($search, 0, 120);
    }

    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, [
        'options' => ['default' => 1, 'min_range' => 1],
    ]);
    $page = is_int($page) ? $page : 1;

    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $where = ["p.post_type = 'page'"];
    $params = [];

    if ($status === 'all') {
        $where[] = "p.post_status <> 'trash'";
    } else {
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

    $statement = $pdo->prepare(<<<SQL
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
SQL);
    $statement->execute($params);

    $items = [];

    foreach ($statement->fetchAll() as $row) {
        $slug = (string) $row['slug'];

        $items[] = [
            'id' => (int) $row['id'],
            'title' => trim((string) $row['title']) !== ''
                ? (string) $row['title']
                : '(sem título)',
            'slug' => $slug,
            'status' => (string) $row['status'],
            'publishedAt' => nj_content_iso8601((string) $row['published_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'author' => [
                'id' => (int) $row['author_id'],
                'name' => (string) $row['author_name'],
            ],
            'publicUrl' => nj_admin_page_public_url($slug),
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
    ];
});
