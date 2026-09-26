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

    $search = trim((string) ($_GET['q'] ?? ''));
    if (function_exists('mb_substr')) {
        $search = mb_substr($search, 0, 120, 'UTF-8');
    } else {
        $search = substr($search, 0, 120);
    }

    $where = ["p.post_type = 'attachment'"];
    $params = [];

    if ($search !== '') {
        $where[] = "(
            p.post_title LIKE :search_title
            OR p.guid LIKE :search_guid
            OR EXISTS (
                SELECT 1
                FROM {$postmeta} alt_search
                WHERE
                    alt_search.post_id = p.ID
                    AND alt_search.meta_key = '_wp_attachment_image_alt'
                    AND alt_search.meta_value LIKE :search_alt
            )
        )";

        $needle = '%' . $search . '%';
        $params['search_title'] = $needle;
        $params['search_guid'] = $needle;
        $params['search_alt'] = $needle;
    }

    $whereSql = implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(*) FROM {$posts} p WHERE {$whereSql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $statement = $pdo->prepare(<<<SQL
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
    ), '') AS alt_text,
    COALESCE((
        SELECT file.meta_value
        FROM {$postmeta} file
        WHERE file.post_id = p.ID AND file.meta_key = '_wp_attached_file'
        ORDER BY file.meta_id DESC
        LIMIT 1
    ), '') AS attached_file,
    COALESCE((
        SELECT metadata.meta_value
        FROM {$postmeta} metadata
        WHERE metadata.post_id = p.ID AND metadata.meta_key = '_wp_attachment_metadata'
        ORDER BY metadata.meta_id DESC
        LIMIT 1
    ), '') AS attachment_metadata
FROM {$posts} p
WHERE {$whereSql}
ORDER BY p.post_date DESC, p.ID DESC
LIMIT {$perPage} OFFSET {$offset}
SQL);
    $statement->execute($params);

    $items = [];

    foreach ($statement->fetchAll() as $row) {
        $title = trim((string) $row['title']) !== ''
            ? (string) $row['title']
            : '(sem título)';
        $image = nj_media_descriptor(
            (string) $row['guid'],
            (string) $row['attached_file'],
            (string) $row['attachment_metadata'],
            (string) $row['alt_text'],
            $title
        );

        $items[] = [
            'id' => (int) $row['id'],
            'title' => $title,
            'mimeType' => (string) $row['mime_type'],
            'url' => is_array($image)
                ? (string) $image['url']
                : nj_media_local_url((string) $row['guid']),
            'alt' => is_array($image)
                ? (string) $image['alt']
                : (string) $row['alt_text'],
            'width' => is_array($image) ? $image['width'] : null,
            'height' => is_array($image) ? $image['height'] : null,
            'srcSet' => is_array($image) ? (string) $image['srcSet'] : '',
            'variants' => is_array($image) ? $image['variants'] : [],
            'createdAt' => nj_content_iso8601((string) $row['created_at']),
            'modifiedAt' => nj_content_iso8601((string) $row['modified_at']),
            'parentId' => (int) $row['parent_id'],
        ];
    }

    return [
        'items' => $items,
        'query' => $search,
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ],
    ];
});
