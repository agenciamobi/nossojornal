<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    $pdo = nj_db();

    $posts = nj_table('posts');
    $comments = nj_table('comments');
    $users = nj_table('users');
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');

    $postSummary = [];
    $statusRows = $pdo->query(<<<SQL
SELECT post_status, COUNT(*) AS total
FROM {$posts}
WHERE post_type = 'post'
GROUP BY post_status
SQL)->fetchAll();

    foreach ($statusRows as $row) {
        $postSummary[(string) $row['post_status']] = (int) $row['total'];
    }

    $categoryCount = (int) $pdo->query(<<<SQL
SELECT COUNT(*)
FROM {$taxonomy}
WHERE taxonomy = 'category'
SQL)->fetchColumn();

    $userCount = (int) $pdo->query("SELECT COUNT(*) FROM {$users} WHERE user_status = 0")->fetchColumn();

    $mediaCount = (int) $pdo->query(<<<SQL
SELECT COUNT(*)
FROM {$posts}
WHERE post_type = 'attachment'
SQL)->fetchColumn();

    $commentCounts = [
        'approved' => 0,
        'pending' => 0,
        'spam' => 0,
    ];

    try {
        $commentRows = $pdo->query(<<<SQL
SELECT comment_approved, COUNT(*) AS total
FROM {$comments}
GROUP BY comment_approved
SQL)->fetchAll();

        foreach ($commentRows as $row) {
            $key = (string) $row['comment_approved'];

            if ($key === '1') {
                $commentCounts['approved'] = (int) $row['total'];
            } elseif ($key === '0') {
                $commentCounts['pending'] = (int) $row['total'];
            } elseif ($key === 'spam') {
                $commentCounts['spam'] = (int) $row['total'];
            }
        }
    } catch (Throwable) {
        // Comentários não são críticos para o dashboard MVP.
    }

    $recentStatement = $pdo->query(<<<SQL
SELECT
    p.ID AS id,
    p.post_title AS title,
    p.post_name AS slug,
    p.post_status AS status,
    p.post_date AS published_at,
    p.post_modified AS modified_at,
    COALESCE(u.display_name, u.user_login, '') AS author_name
FROM {$posts} p
LEFT JOIN {$users} u ON u.ID = p.post_author
WHERE p.post_type = 'post'
ORDER BY p.post_modified DESC, p.ID DESC
LIMIT 8
SQL);

    $recent = [];
    foreach ($recentStatement->fetchAll() as $row) {
        $recent[] = [
            'id' => (int) $row['id'],
            'title' => trim((string) $row['title']) !== '' ? (string) $row['title'] : '(sem título)',
            'slug' => (string) $row['slug'],
            'status' => (string) $row['status'],
            'publishedAt' => nj_content_iso8601((string) $row['published_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'author' => (string) $row['author_name'],
            'publicUrl' => trim((string) $row['slug']) !== ''
                ? '/noticia/' . rawurlencode((string) $row['slug'])
                : null,
        ];
    }

    return [
        'user' => $user,
        'summary' => [
            'posts' => [
                'published' => $postSummary['publish'] ?? 0,
                'draft' => $postSummary['draft'] ?? 0,
                'pending' => $postSummary['pending'] ?? 0,
                'future' => $postSummary['future'] ?? 0,
                'private' => $postSummary['private'] ?? 0,
                'trash' => $postSummary['trash'] ?? 0,
                'total' => array_sum($postSummary),
            ],
            'categories' => $categoryCount,
            'users' => $userCount,
            'media' => $mediaCount,
            'comments' => $commentCounts,
        ],
        'recentPosts' => $recent,
        'mode' => 'read_only',
    ];
});
