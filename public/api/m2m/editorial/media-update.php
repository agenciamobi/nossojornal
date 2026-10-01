<?php
declare(strict_types=1);

require __DIR__ . '/_draft.php';

/**
 * Update only display/SEO metadata of a library attachment owned by an
 * existing draft. Source URL, file path, post status and linked media IDs
 * are deliberately immutable through this API.
 */
nj_m2m_run('POST', 'editorial.media.update', static function (array $context): array {
    $body = nj_m2m_body($context);
    if (array_diff(array_keys($body), ['attachment_id', 'fields']) !== []) {
        throw new NjApiHttpException(422, 'body_field_not_allowed');
    }
    $id = filter_var($body['attachment_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $fields = $body['fields'] ?? null;
    $limits = [
        'title' => 500, 'alt' => 1000, 'caption' => 10000,
        'description' => 50000, 'credit' => 1000, 'license' => 200,
        'seo_title' => 180, 'seo_description' => 400,
    ];
    if (!is_int($id) || $id <= 0 || !is_array($fields)
        || array_is_list($fields) || $fields === []
        || array_diff(array_keys($fields), array_keys($limits)) !== []
    ) throw new NjApiHttpException(422, 'media_update_payload_invalid');

    foreach ($fields as $key => $value) {
        $length = is_string($value)
            ? (function_exists('mb_strlen') ? mb_strlen(trim($value), 'UTF-8') : strlen(trim($value)))
            : -1;
        if ($length < 0 || $length > $limits[$key] || ($key === 'credit' && $length === 0)
            || ($key === 'title' && $length === 0)
        ) throw new NjApiHttpException(422, 'media_update_field_invalid');
        $fields[$key] = trim($value);
    }

    $pdo = $context['pdo'];
    $posts = nj_table('posts');
    $meta = nj_table('postmeta');
    $pdo->beginTransaction();
    try {
        $item = $pdo->prepare(
            "SELECT a.ID, a.post_parent, a.post_mime_type,
                    a.post_title, a.post_excerpt, a.post_content, p.post_author
             FROM {$posts} a
             INNER JOIN {$posts} p ON p.ID = a.post_parent
                AND p.post_type = 'post' AND p.post_status = 'draft'
             WHERE a.ID = :id AND a.post_type = 'attachment'
               AND (a.post_mime_type LIKE 'image/%' OR a.post_mime_type = 'video/x-embed')
             LIMIT 1 FOR UPDATE"
        );
        $item->execute(['id' => $id]);
        $attachment = $item->fetch();
        if (!is_array($attachment)) {
            throw new NjApiHttpException(409, 'media_update_draft_required');
        }

        // Library records can be reused across posts. MCP must not silently
        // rewrite metadata visible on a published/scheduled/private article.
        $publishedFeatured = $pdo->prepare(
            "SELECT 1 FROM {$meta} m
             INNER JOIN {$posts} p ON p.ID = m.post_id AND p.post_type = 'post'
                 AND p.post_status IN ('publish','future','private')
             WHERE m.meta_key = '_thumbnail_id' AND m.meta_value = :id
             LIMIT 1"
        );
        $publishedFeatured->execute(['id' => (string) $id]);
        if ($publishedFeatured->fetchColumn()) {
            throw new NjApiHttpException(409, 'media_used_by_published_post');
        }
        if ((string) $attachment['post_mime_type'] === 'video/x-embed') {
            $publishedVideo = $pdo->prepare(
                "SELECT 1 FROM {$meta} m
                 INNER JOIN {$posts} p ON p.ID = m.post_id AND p.post_type = 'post'
                     AND p.post_status IN ('publish','future','private')
                 WHERE m.meta_key = '_nj_editorial_video_attachment_ids'
                   AND JSON_CONTAINS(IF(JSON_VALID(m.meta_value), m.meta_value, '[]'), :needle, '$') = 1
                 LIMIT 1"
            );
            $publishedVideo->execute(['needle' => json_encode($id, JSON_THROW_ON_ERROR)]);
            if ($publishedVideo->fetchColumn()) {
                throw new NjApiHttpException(409, 'media_used_by_published_post');
            }
        }

        if ($attachment['post_mime_type'] === 'video/x-embed' && array_key_exists('alt', $fields)) {
            throw new NjApiHttpException(422, 'video_alt_not_supported');
        }

        $postColumns = [
            'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content',
        ];
        $updateParts = [];
        $updates = ['id' => $id];
        foreach ($postColumns as $key => $column) {
            if (array_key_exists($key, $fields)) {
                $updateParts[] = "{$column} = :{$key}";
                $updates[$key] = $fields[$key];
            }
        }
        if ($updateParts !== []) {
            $updateParts[] = 'post_modified = NOW()';
            $updateParts[] = 'post_modified_gmt = UTC_TIMESTAMP()';
            $update = $pdo->prepare(
                "UPDATE {$posts} SET " . implode(', ', $updateParts)
                . " WHERE ID = :id AND post_type = 'attachment' LIMIT 1"
            );
            $update->execute($updates);
        }

        $metaFields = [
            'alt' => '_wp_attachment_image_alt',
            'credit' => '_nj_media_credit',
            'license' => '_nj_media_license',
            'seo_title' => '_nj_media_seo_title',
            'seo_description' => '_nj_media_seo_description',
        ];
        foreach ($metaFields as $key => $metaKey) {
            if (array_key_exists($key, $fields)) {
                nj_admin_upsert_postmeta($pdo, $id, $metaKey, $fields[$key]);
            }
        }

        $read = $pdo->prepare(
            "SELECT post_title, post_excerpt, post_content FROM {$posts}
             WHERE ID = :id AND post_type = 'attachment' LIMIT 1"
        );
        $read->execute(['id' => $id]);
        $persisted = $read->fetch();
        if (!is_array($persisted)) throw new RuntimeException('media_update_readback_failed');
        foreach ($postColumns as $key => $column) {
            if (array_key_exists($key, $fields)
                && (string) $persisted[$column] !== $fields[$key]) {
                throw new RuntimeException('media_update_readback_failed');
            }
        }
        foreach ($metaFields as $key => $metaKey) {
            if (!array_key_exists($key, $fields)) continue;
            $check = $pdo->prepare(
                "SELECT meta_value FROM {$meta} WHERE post_id = :id AND meta_key = :key
                 ORDER BY meta_id DESC LIMIT 1"
            );
            $check->execute(['id' => $id, 'key' => $metaKey]);
            if ((string) $check->fetchColumn() !== $fields[$key]) {
                throw new RuntimeException('media_metadata_readback_failed');
            }
        }
        nj_admin_log_post_activity(
            $pdo, (int) $attachment['post_parent'],
            (int) $attachment['post_author'], 'editorial_media_metadata_updated',
            [
                'attachmentId' => $id, 'fields' => array_keys($fields),
                'requestId' => (string) $context['requestId'],
            ]
        );
        $pdo->commit();
        return [
            'attachment_id' => (string) $id,
            'post_id' => (string) $attachment['post_parent'],
            'updated_fields' => array_keys($fields),
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
});
