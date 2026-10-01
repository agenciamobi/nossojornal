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
    $credit = trim((string) ($body['credit'] ?? ''));
    $license = trim((string) ($body['license'] ?? ''));
    $seoTitle = trim((string) ($body['seoTitle'] ?? ''));
    $seoDescription = trim((string) ($body['seoDescription'] ?? ''));

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

    foreach ([
        'credit' => [$credit, 1000], 'license' => [$license, 200],
        'seoTitle' => [$seoTitle, 180], 'seoDescription' => [$seoDescription, 400],
    ] as $field => [$value, $limit]) {
        if ((function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value)) > $limit) {
            throw new NjApiHttpException(422, 'media_' . strtolower($field) . '_too_large');
        }
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
        nj_admin_upsert_postmeta($pdo, $id, '_nj_media_credit', $credit);
        nj_admin_upsert_postmeta($pdo, $id, '_nj_media_license', $license);
        nj_admin_upsert_postmeta($pdo, $id, '_nj_media_seo_title', $seoTitle);
        nj_admin_upsert_postmeta($pdo, $id, '_nj_media_seo_description', $seoDescription);

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

        $extrasReadback = $pdo->prepare(
            "SELECT meta_key, meta_value FROM {$postmeta} WHERE post_id = :id
             AND meta_key IN ('_nj_media_credit', '_nj_media_license', '_nj_media_seo_title', '_nj_media_seo_description')
             ORDER BY meta_id DESC"
        );
        $extrasReadback->execute(['id' => $id]);
        $savedExtras = [];
        foreach ($extrasReadback->fetchAll() as $entry) {
            $key = (string) $entry['meta_key'];
            if (!isset($savedExtras[$key])) $savedExtras[$key] = (string) $entry['meta_value'];
        }
        foreach ([
            '_nj_media_credit' => $credit, '_nj_media_license' => $license,
            '_nj_media_seo_title' => $seoTitle, '_nj_media_seo_description' => $seoDescription,
        ] as $key => $expected) {
            if (($savedExtras[$key] ?? null) !== $expected) {
                throw new RuntimeException('media_metadata_readback_mismatch');
            }
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
            'credit' => $credit,
            'license' => $license,
            'seoTitle' => $seoTitle,
            'seoDescription' => $seoDescription,
            'modifiedAt' => nj_content_iso8601((string) $persisted['modified_at']),
        ],
    ];
});
