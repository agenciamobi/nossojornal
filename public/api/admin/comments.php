<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'moderate_comments');

    $pdo = nj_db();
    $comments = nj_table('comments');
    $posts = nj_table('posts');

    $status = trim((string) ($_GET['status'] ?? 'all'));
    $allowedStatuses = ['all', 'pending', 'approved', 'spam', 'trash'];

    if (!in_array($status, $allowedStatuses, true)) {
        throw new NjApiHttpException(400, 'invalid_comment_status');
    }

    $query = trim((string) ($_GET['q'] ?? ''));
    if (function_exists('mb_substr')) {
        $query = mb_substr($query, 0, 120, 'UTF-8');
    } else {
        $query = substr($query, 0, 120);
    }

    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, [
        'options' => ['default' => 1, 'min_range' => 1],
    ]);
    $page = is_int($page) ? $page : 1;

    $perPage = 30;
    $offset = ($page - 1) * $perPage;

    $where = [];
    $params = [];

    if ($status === 'pending') {
        $where[] = "c.comment_approved = '0'";
    } elseif ($status === 'approved') {
        $where[] = "c.comment_approved = '1'";
    } elseif ($status === 'spam') {
        $where[] = "c.comment_approved = 'spam'";
    } elseif ($status === 'trash') {
        $where[] = "c.comment_approved = 'trash'";
    } else {
        $where[] = "c.comment_approved <> 'post-trashed'";
    }

    if ($query !== '') {
        $where[] = "(
            c.comment_author LIKE :search_author
            OR c.comment_author_email LIKE :search_email
            OR c.comment_content LIKE :search_content
            OR p.post_title LIKE :search_post
        )";
        $needle = '%' . $query . '%';
        $params['search_author'] = $needle;
        $params['search_email'] = $needle;
        $params['search_content'] = $needle;
        $params['search_post'] = $needle;
    }

    $whereSql = implode(' AND ', $where);

    $count = $pdo->prepare(<<<SQL
SELECT COUNT(*)
FROM {$comments} c
LEFT JOIN {$posts} p ON p.ID = c.comment_post_ID
WHERE {$whereSql}
SQL);
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $statement = $pdo->prepare(<<<SQL
SELECT
    c.comment_ID AS id,
    c.comment_post_ID AS post_id,
    c.comment_author AS author,
    c.comment_author_email AS email,
    c.comment_author_url AS author_url,
    c.comment_content AS content,
    c.comment_date AS created_at,
    c.comment_approved AS approved,
    c.comment_parent AS parent_id,
    c.user_id,
    p.post_title,
    p.post_name
FROM {$comments} c
LEFT JOIN {$posts} p ON p.ID = c.comment_post_ID
WHERE {$whereSql}
ORDER BY c.comment_date DESC, c.comment_ID DESC
LIMIT {$perPage} OFFSET {$offset}
SQL);
    $statement->execute($params);

    $items = [];

    foreach ($statement->fetchAll() as $row) {
        $approved = (string) $row['approved'];
        $normalizedStatus = $approved === '1'
            ? 'approved'
            : ($approved === '0' ? 'pending' : $approved);

        $postSlug = trim((string) ($row['post_name'] ?? ''));

        $items[] = [
            'id' => (int) $row['id'],
            'postId' => (int) $row['post_id'],
            'postTitle' => trim((string) ($row['post_title'] ?? '')) !== ''
                ? (string) $row['post_title']
                : '(conteúdo indisponível)',
            'postUrl' => $postSlug !== ''
                ? '/noticia/' . rawurlencode($postSlug)
                : null,
            'author' => (string) $row['author'],
            'email' => (string) $row['email'],
            'authorUrl' => (string) $row['author_url'],
            'content' => (string) $row['content'],
            'createdAt' => nj_content_iso8601((string) $row['created_at']),
            'status' => $normalizedStatus,
            'parentId' => (int) $row['parent_id'],
            'userId' => (int) $row['user_id'],
        ];
    }

    $counts = [
        'pending' => 0,
        'approved' => 0,
        'spam' => 0,
        'trash' => 0,
    ];

    $countStatement = $pdo->query(<<<SQL
SELECT comment_approved, COUNT(*) AS total
FROM {$comments}
WHERE comment_approved IN ('0', '1', 'spam', 'trash')
GROUP BY comment_approved
SQL);

    foreach ($countStatement->fetchAll() as $row) {
        $key = (string) $row['comment_approved'];
        $normalized = $key === '1'
            ? 'approved'
            : ($key === '0' ? 'pending' : $key);

        if (array_key_exists($normalized, $counts)) {
            $counts[$normalized] = (int) $row['total'];
        }
    }

    return [
        'items' => $items,
        'query' => $query,
        'status' => $status,
        'counts' => $counts,
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ],
    ];
});
