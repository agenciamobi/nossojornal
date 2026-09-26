<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_pages');

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if (!is_int($id) || $id <= 0) {
        throw new NjApiHttpException(422, 'invalid_page_id');
    }

    $pdo = nj_db();
    $posts = nj_table('posts');
    $users = nj_table('users');
    $postmeta = nj_table('postmeta');

    $statement = $pdo->prepare(<<<SQL
SELECT
    p.ID AS id,
    p.post_author AS author_id,
    p.post_title AS title,
    p.post_name AS slug,
    p.post_excerpt AS excerpt,
    p.post_content AS content,
    p.post_status AS status,
    p.post_date AS published_at,
    p.post_modified AS modified_at,
    COALESCE(u.display_name, u.user_login, '') AS author_name
FROM {$posts} p
LEFT JOIN {$users} u ON u.ID = p.post_author
WHERE
    p.ID = :id
    AND p.post_type = 'page'
LIMIT 1
SQL);
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    if (!$row) {
        throw new NjApiHttpException(404, 'page_not_found');
    }

    $metaStatement = $pdo->prepare(<<<SQL
SELECT meta_key, meta_value
FROM {$postmeta}
WHERE
    post_id = :post_id
    AND meta_key IN (
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc'
    )
ORDER BY meta_id DESC
SQL);
    $metaStatement->execute(['post_id' => $id]);

    $meta = [];
    foreach ($metaStatement->fetchAll() as $metaRow) {
        $key = (string) $metaRow['meta_key'];
        if (!array_key_exists($key, $meta)) {
            $meta[$key] = (string) $metaRow['meta_value'];
        }
    }

    $slug = (string) $row['slug'];
    $slugLocked = in_array($slug, ['quem-somos', 'contato'], true);

    return [
        'page' => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'slug' => $slug,
            'slugLocked' => $slugLocked,
            'excerpt' => (string) $row['excerpt'],
            'content' => (string) $row['content'],
            'status' => (string) $row['status'],
            'publishedAt' => nj_content_iso8601((string) $row['published_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'author' => [
                'id' => (int) $row['author_id'],
                'name' => (string) $row['author_name'],
            ],
            'seo' => [
                'title' => nj_content_clean_text_source((string) ($meta['_yoast_wpseo_title'] ?? '')),
                'description' => nj_content_clean_text_source((string) ($meta['_yoast_wpseo_metadesc'] ?? '')),
            ],
            'publicUrl' => nj_admin_page_public_url($slug),
        ],
    ];
});
