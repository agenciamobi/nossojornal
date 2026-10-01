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
    ), '') AS attached_file,
    COALESCE((
        SELECT metadata.meta_value
        FROM {$postmeta} metadata
        WHERE metadata.post_id = p.ID
          AND metadata.meta_key = '_wp_attachment_metadata'
        ORDER BY metadata.meta_id DESC
        LIMIT 1
    ), '') AS attachment_metadata
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

    $image = nj_media_descriptor(
        (string) $row['guid'],
        (string) $row['attached_file'],
        (string) $row['attachment_metadata'],
        (string) $row['alt_text'],
        (string) $row['title']
    );
    $publicUrl = is_array($image)
        ? (string) $image['url']
        : nj_media_local_url((string) $row['guid']);

    $extraStatement = $pdo->prepare(
        "SELECT meta_key, meta_value FROM {$postmeta} WHERE post_id = :id
         AND meta_key IN ('_nj_media_credit', '_nj_media_license', '_nj_media_seo_title',
                          '_nj_media_seo_description', '_nj_remote_media_source_url',
                          '_nj_remote_media_source_page', '_nj_embed_youtube_id')
         ORDER BY meta_id DESC"
    );
    $extraStatement->execute(['id' => $id]);
    $extras = [];
    foreach ($extraStatement->fetchAll() as $entry) {
        if (!array_key_exists((string) $entry['meta_key'], $extras)) {
            $extras[(string) $entry['meta_key']] = (string) $entry['meta_value'];
        }
    }

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

    if ((string) $row['mime_type'] === 'video/x-embed') {
        // Video posts link by metadata rather than by _thumbnail_id.
        $videoUsage = $pdo->prepare(
            "SELECT p.ID AS id, p.post_title AS title, p.post_name AS slug, p.post_status AS status
             FROM {$postmeta} pm
             INNER JOIN {$posts} p ON p.ID = pm.post_id AND p.post_type = 'post'
             WHERE pm.meta_key = '_nj_editorial_video_attachment_ids'
               AND JSON_VALID(pm.meta_value) = 1
               AND JSON_CONTAINS(pm.meta_value, :needle, '
            'id' => (int) $row['id'],
            'authorId' => (int) $row['author_id'],
            'title' => (string) $row['title'],
            'caption' => (string) $row['caption'],
            'description' => (string) $row['description'],
            'mimeType' => (string) $row['mime_type'],
            'url' => $publicUrl,
            'alt' => is_array($image)
                ? (string) $image['alt']
                : (string) $row['alt_text'],
            'width' => is_array($image) ? $image['width'] : null,
            'height' => is_array($image) ? $image['height'] : null,
            'srcSet' => is_array($image) ? (string) $image['srcSet'] : '',
            'variants' => is_array($image) ? $image['variants'] : [],
            'attachedFile' => (string) $row['attached_file'],
            'createdAt' => nj_content_iso8601((string) $row['created_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'parentId' => (int) $row['parent_id'],
            'credit' => (string) ($extras['_nj_media_credit'] ?? ''),
            'license' => (string) ($extras['_nj_media_license'] ?? ''),
            'seoTitle' => (string) ($extras['_nj_media_seo_title'] ?? ''),
            'seoDescription' => (string) ($extras['_nj_media_seo_description'] ?? ''),
            'sourceUrl' => (string) ($extras['_nj_remote_media_source_url'] ?? ''),
            'sourcePage' => (string) ($extras['_nj_remote_media_source_page'] ?? ''),
            'videoId' => (string) ($extras['_nj_embed_youtube_id'] ?? ''),
            'usedBy' => $usedBy,
        ],
    ];
});
) = 1
             ORDER BY p.post_modified DESC LIMIT 30"
        );
        $videoUsage->execute(['needle' => json_encode($id, JSON_THROW_ON_ERROR)]);
        foreach ($videoUsage->fetchAll() as $post) {
            $usedBy[(int) $post['id']] = [
                'id' => (int) $post['id'],
                'title' => (string) $post['title'] ?: '(sem título)',
                'status' => (string) $post['status'],
                'adminUrl' => '/sistema/noticias/' . (int) $post['id'],
                'publicUrl' => (string) $post['slug'] !== ''
                    ? '/noticia/' . rawurlencode((string) $post['slug']) : null,
            ];
        }
        $usedBy = array_values($usedBy);
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
            'alt' => is_array($image)
                ? (string) $image['alt']
                : (string) $row['alt_text'],
            'width' => is_array($image) ? $image['width'] : null,
            'height' => is_array($image) ? $image['height'] : null,
            'srcSet' => is_array($image) ? (string) $image['srcSet'] : '',
            'variants' => is_array($image) ? $image['variants'] : [],
            'attachedFile' => (string) $row['attached_file'],
            'createdAt' => nj_content_iso8601((string) $row['created_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'parentId' => (int) $row['parent_id'],
            'credit' => (string) ($extras['_nj_media_credit'] ?? ''),
            'license' => (string) ($extras['_nj_media_license'] ?? ''),
            'seoTitle' => (string) ($extras['_nj_media_seo_title'] ?? ''),
            'seoDescription' => (string) ($extras['_nj_media_seo_description'] ?? ''),
            'sourceUrl' => (string) ($extras['_nj_remote_media_source_url'] ?? ''),
            'sourcePage' => (string) ($extras['_nj_remote_media_source_page'] ?? ''),
            'videoId' => (string) ($extras['_nj_embed_youtube_id'] ?? ''),
            'usedBy' => $usedBy,
        ],
    ];
});
