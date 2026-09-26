<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_posts');
    nj_admin_require_csrf();

    $body = nj_admin_request_body();

    $postId = filter_var(
        $body['postId'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $attachmentId = (int) ($body['attachmentId'] ?? 0);

    if (!is_int($postId) || $postId <= 0) {
        throw new NjApiHttpException(422, 'invalid_post_id');
    }

    if ($attachmentId < 0) {
        throw new NjApiHttpException(422, 'invalid_attachment_id');
    }

    $pdo = nj_db();
    $posts = nj_table('posts');

    $postStatement = $pdo->prepare(<<<SQL
SELECT
    ID,
    post_author,
    post_status
FROM {$posts}
WHERE
    ID = :id
    AND post_type = 'post'
LIMIT 1
SQL);
    $postStatement->execute(['id' => $postId]);
    $post = $postStatement->fetch();

    if (!$post) {
        throw new NjApiHttpException(404, 'post_not_found');
    }

    if (
        (int) $post['post_author'] !== (int) $user['id']
        && !in_array('edit_others_posts', $user['capabilities'], true)
    ) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }

    if (
        in_array((string) $post['post_status'], ['publish', 'private', 'future'], true)
        && !in_array('edit_published_posts', $user['capabilities'], true)
    ) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }

    $media = null;

    if ($attachmentId > 0) {
        $attachmentStatement = $pdo->prepare(<<<SQL
SELECT
    ID AS id,
    post_title AS title,
    post_mime_type AS mime_type,
    guid
FROM {$posts}
WHERE
    ID = :id
    AND post_type = 'attachment'
    AND post_mime_type LIKE 'image/%'
LIMIT 1
SQL);
        $attachmentStatement->execute(['id' => $attachmentId]);
        $attachment = $attachmentStatement->fetch();

        if (!$attachment) {
            throw new NjApiHttpException(404, 'attachment_not_found');
        }

        $postmeta = nj_table('postmeta');
        $altStatement = $pdo->prepare(<<<SQL
SELECT meta_value
FROM {$postmeta}
WHERE
    post_id = :post_id
    AND meta_key = '_wp_attachment_image_alt'
ORDER BY meta_id DESC
LIMIT 1
SQL);
        $altStatement->execute(['post_id' => $attachmentId]);
        $alt = (string) ($altStatement->fetchColumn() ?: '');

        $url = (string) $attachment['guid'];
        $path = parse_url($url, PHP_URL_PATH);

        $media = [
            'id' => (int) $attachment['id'],
            'title' => (string) $attachment['title'],
            'url' => is_string($path) && str_starts_with($path, '/wp-content/uploads/')
                ? $path
                : $url,
            'alt' => $alt,
        ];
    }

    try {
        $pdo->beginTransaction();

        nj_admin_upsert_postmeta(
            $pdo,
            $postId,
            '_thumbnail_id',
            $attachmentId > 0 ? (string) $attachmentId : ''
        );

        $postUpdate = $pdo->prepare(<<<SQL
UPDATE {$posts}
SET
    post_modified = NOW(),
    post_modified_gmt = UTC_TIMESTAMP()
WHERE ID = :id
LIMIT 1
SQL);
        $postUpdate->execute(['id' => $postId]);

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
        'featuredImage' => $media,
    ];
});
