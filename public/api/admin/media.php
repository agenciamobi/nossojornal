<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'upload_files');

    $pdo = nj_db();
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(60, max(12, (int) ($_GET['per_page'] ?? 36)));
    $offset = ($page - 1) * $perPage;

    $total = (int) $pdo->query(<<<SQL
SELECT COUNT(*)
FROM {$posts}
WHERE post_type = 'attachment'
SQL)->fetchColumn();

    $statement = $pdo->query(<<<SQL
SELECT
    p.ID AS id,
    p.post_title AS title,
    p.post_mime_type AS mime_type,
    p.guid,
    p.post_date AS created_at,
    p.post_modified AS modified_at,
    p.post_parent AS parent_id,
    COALESCE((
        SELECT alt.meta_value
        FROM {$postmeta} alt
        WHERE alt.post_id = p.ID AND alt.meta_key = '_wp_attachment_image_alt'
        ORDER BY alt.meta_id DESC
        LIMIT 1
    ), '') AS alt_text
FROM {$posts} p
WHERE p.post_type = 'attachment'
ORDER BY p.post_date DESC, p.ID DESC
LIMIT {$perPage} OFFSET {$offset}
SQL);

    $items = [];

    foreach ($statement->fetchAll() as $row) {
        $url = (string) $row['guid'];
        $path = parse_url($url, PHP_URL_PATH);

        $items[] = [
            'id' => (int) $row['id'],
            'title' => trim((string) $row['title']) !== '' ? (string) $row['title'] : '(sem título)',
            'mimeType' => (string) $row['mime_type'],
            'url' => is_string($path) && str_starts_with($path, '/wp-content/uploads/')
                ? $path
                : $url,
            'alt' => (string) $row['alt_text'],
            'createdAt' => nj_content_iso8601((string) $row['created_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'parentId' => (int) $row['parent_id'],
        ];
    }

    return [
        'items' => $items,
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ],
        'mode' => 'read_only',
    ];
});
