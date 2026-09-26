<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_posts');

    $postId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if (!is_int($postId) || $postId <= 0) {
        throw new NjApiHttpException(422, 'invalid_post_id');
    }

    $pdo = nj_db();
    nj_admin_require_post_editor($pdo, $user, $postId);

    $posts = nj_table('posts');
    $users = nj_table('users');

    $statement = $pdo->prepare(<<<SQL
SELECT
    a.ID,
    a.post_author,
    a.post_title,
    a.post_content,
    a.post_date,
    COALESCE(u.display_name, u.user_login, '') AS author_name
FROM {$posts} a
LEFT JOIN {$users} u ON u.ID = a.post_author
WHERE
    a.post_type = 'nj_activity'
    AND a.post_parent = :post_id
    AND a.post_status = 'private'
ORDER BY a.post_date DESC, a.ID DESC
LIMIT 100
SQL);
    $statement->execute(['post_id' => $postId]);

    $items = [];
    foreach ($statement->fetchAll() as $row) {
        $payload = json_decode((string) $row['post_content'], true);
        $items[] = [
            'id' => (int) $row['ID'],
            'action' => (string) $row['post_title'],
            'payload' => is_array($payload) ? $payload : [],
            'createdAt' => nj_content_iso8601((string) $row['post_date']),
            'author' => [
                'id' => (int) $row['post_author'],
                'name' => (string) $row['author_name'],
            ],
        ];
    }

    return ['items' => $items];
});
