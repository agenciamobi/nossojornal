<?php
declare(strict_types=1);

require __DIR__ . '/_auth.php';

$user = nj_system_require_user('dashboard');
$pdo = nj_db();

$posts = nj_table('posts');
$terms = nj_table('terms');
$taxonomy = nj_table('term_taxonomy');
$users = nj_table('users');

$postCounts = [
    'publish' => 0,
    'draft' => 0,
    'pending' => 0,
    'future' => 0,
    'private' => 0,
    'trash' => 0,
];

$countStatement = $pdo->query(<<<SQL
SELECT post_status, COUNT(*) AS total
FROM {$posts}
WHERE post_type = 'post'
GROUP BY post_status
SQL);

foreach ($countStatement->fetchAll() as $row) {
    $status = (string) $row['post_status'];
    if (array_key_exists($status, $postCounts)) {
        $postCounts[$status] = (int) $row['total'];
    }
}

$categoryCount = (int) $pdo->query(<<<SQL
SELECT COUNT(*)
FROM {$taxonomy}
WHERE taxonomy = 'category'
SQL)->fetchColumn();

$userCount = (int) $pdo->query("SELECT COUNT(*) FROM {$users}")->fetchColumn();

$mediaCount = (int) $pdo->query(<<<SQL
SELECT COUNT(*)
FROM {$posts}
WHERE post_type = 'attachment'
SQL)->fetchColumn();

$recentStatement = $pdo->query(<<<SQL
SELECT
    p.ID AS id,
    p.post_title AS title,
    p.post_name AS slug,
    p.post_status AS status,
    p.post_date AS published_at,
    p.post_modified AS modified_at,
    COALESCE(u.display_name, '') AS author_name
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
        'publishedAt' => (string) $row['published_at'],
        'modifiedAt' => (string) $row['modified_at'],
        'authorName' => (string) $row['author_name'],
        'publicUrl' => (string) $row['slug'] !== ''
            ? '/noticia/' . rawurlencode((string) $row['slug'])
            : null,
    ];
}

nj_system_json([
    'ok' => true,
    'data' => [
        'summary' => [
            'posts' => $postCounts,
            'categories' => $categoryCount,
            'users' => $userCount,
            'media' => $mediaCount,
        ],
        'recentPosts' => $recent,
        'user' => $user,
    ],
]);
