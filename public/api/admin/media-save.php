<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'upload_files');
    nj_admin_require_csrf();

    $body = nj_admin_request_body();

    $id = filter_var(
        $body['mediaId'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $title = trim((string) ($body['title'] ?? ''));
    $alt = trim((string) ($body['alt'] ?? ''));
    $caption = trim((string) ($body['caption'] ?? ''));
    $description = trim((string) ($body['description'] ?? ''));

    if (!is_int($id) || $id <= 0) {
        throw new NjApiHttpException(422, 'invalid_media_id');
    }

    if ($title === '' || (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)) > 500) {
        throw new NjApiHttpException(422, 'invalid_media_title');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($alt, 'UTF-8') : strlen($alt)) > 1000) {
        throw new NjApiHttpException(422, 'media_alt_too_large');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($caption, 'UTF-8') : strlen($caption)) > 10000) {
        throw new NjApiHttpException(422, 'media_caption_too_large');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description)) > 50000) {
        throw new NjApiHttpException(422, 'media_description_too_large');
    }

    $pdo = nj_db();
    $posts = nj_table('posts');
    $postmeta = nj_table('postmeta');

    $exists = $pdo->prepare(<<<SQL
SELECT ID
FROM {$posts}
WHERE
    ID = :id
    AND post_type = 'attachment'
LIMIT 1
SQL);
    $exists->execute(['id' => $id]);

    if (!$exists->fetchColumn()) {
        throw new NjApiHttpException(404, 'media_not_found');
    }

    try {
        $pdo->beginTransaction();

        $update = $pdo->prepare(<<<SQL
UPDATE {$posts}
SET
    post_title = :title,
    post_excerpt = :caption,
    post_content = :description,
    post_modified = NOW(),
    post_modified_gmt = UTC_TIMESTAMP()
WHERE
    ID = :id
    AND post_type = 'attachment'
LIMIT 1
SQL);
        $update->execute([
            'title' => $title,
            'caption' => $caption,
            'description' => $description,
            'id' => $id,
        ]);

        nj_admin_upsert_postmeta($pdo, $id, '_wp_attachment_image_alt', $alt);

        $readBack = $pdo->prepare(<<<SQL
SELECT
    p.post_title AS title,
    p.post_excerpt AS caption,
    p.post_content AS description,
    p.post_modified AS modified_at,
    COALESCE((
        SELECT alt.meta_value
        FROM {$postmeta} alt
        WHERE alt.post_id = p.ID
          AND alt.meta_key = '_wp_attachment_image_alt'
        ORDER BY alt.meta_id DESC
        LIMIT 1
    ), '') AS alt_text
FROM {$posts} p
WHERE p.ID = :id
LIMIT 1
SQL);
        $readBack->execute(['id' => $id]);
        $persisted = $readBack->fetch();

        if (
            !$persisted
            || (string) $persisted['title'] !== $title
            || (string) $persisted['caption'] !== $caption
            || (string) $persisted['description'] !== $description
            || (string) $persisted['alt_text'] !== $alt
        ) {
            throw new RuntimeException('media_readback_mismatch');
        }

        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ((string) $error->getCode() === '42000') {
            throw new NjApiHttpException(409, 'database_write_unavailable');
        }

        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }

    return [
        'media' => [
            'id' => $id,
            'title' => $title,
            'alt' => $alt,
            'caption' => $caption,
            'description' => $description,
            'modifiedAt' => nj_content_iso8601((string) $persisted['modified_at']),
        ],
    ];
});
