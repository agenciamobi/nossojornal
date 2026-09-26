<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'upload_files');

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if (!is_int($id) || $id <= 0) {
        throw new NjApiHttpException(422, 'invalid_media_id');
    }

    $pdo = nj_db();
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');

    $statement = $pdo->prepare(<<<SQL
SELECT
    p.ID AS id,
    p.post_author AS author_id,
    p.post_title AS title,
    p.post_excerpt AS caption,
    p.post_content AS description,
    p.post_mime_type AS mime_type,
    p.guid,
    p.post_date AS created_at,
    p.post_modified AS modified_at,
    p.post_parent AS parent_id,
    COALESCE((
        SELECT alt.meta_value
        FROM {$postmeta} alt
        WHERE alt.post_id = p.ID
          AND alt.meta_key = '_wp_attachment_image_alt'
        ORDER BY alt.meta_id DESC
        LIMIT 1
    ), '') AS alt_text,
    COALESCE((
        SELECT file.meta_value
        FROM {$postmeta} file
        WHERE file.post_id = p.ID
          AND file.meta_key = '_wp_attached_file'
        ORDER BY file.meta_id DESC
        LIMIT 1
    ), '') AS attached_file
FROM {$posts} p
WHERE
    p.ID = :id
    AND p.post_type = 'attachment'
LIMIT 1
SQL);
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    if (!$row) {
        throw new NjApiHttpException(404, 'media_not_found');
    }

    $url = (string) $row['guid'];
    $path = parse_url($url, PHP_URL_PATH);
    $publicUrl = is_string($path) && str_starts_with($path, '/wp-content/uploads/')
        ? $path
        : $url;

    $usageStatement = $pdo->prepare(<<<SQL
SELECT
    p.ID AS id,
    p.post_title AS title,
    p.post_name AS slug,
    p.post_status AS status
FROM {$postmeta} pm
INNER JOIN {$posts} p ON p.ID = pm.post_id
WHERE
    pm.meta_key = '_thumbnail_id'
    AND pm.meta_value = :attachment_id
    AND p.post_type = 'post'
ORDER BY p.post_modified DESC
LIMIT 30
SQL);
    $usageStatement->execute(['attachment_id' => (string) $id]);

    $usedBy = [];
    foreach ($usageStatement->fetchAll() as $post) {
        $slug = trim((string) $post['slug']);

        $usedBy[] = [
            'id' => (int) $post['id'],
            'title' => trim((string) $post['title']) !== ''
                ? (string) $post['title']
                : '(sem título)',
            'status' => (string) $post['status'],
            'adminUrl' => '/sistema/noticias/' . (int) $post['id'],
            'publicUrl' => $slug !== ''
                ? '/noticia/' . rawurlencode($slug)
                : null,
        ];
    }

    return [
        'media' => [
            'id' => (int) $row['id'],
            'authorId' => (int) $row['author_id'],
            'title' => (string) $row['title'],
            'caption' => (string) $row['caption'],
            'description' => (string) $row['description'],
            'mimeType' => (string) $row['mime_type'],
            'url' => $publicUrl,
            'alt' => (string) $row['alt_text'],
            'attachedFile' => (string) $row['attached_file'],
            'createdAt' => nj_content_iso8601((string) $row['created_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'parentId' => (int) $row['parent_id'],
            'usedBy' => $usedBy,
        ],
    ];
});
