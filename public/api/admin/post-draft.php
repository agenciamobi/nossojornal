<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

function nj_admin_string_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

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

    $title = trim((string) ($body['title'] ?? ''));
    $excerpt = (string) ($body['excerpt'] ?? '');
    $content = (string) ($body['content'] ?? '');

    if (!is_int($postId) || $postId <= 0) {
        throw new NjApiHttpException(422, 'invalid_post_id');
    }

    if ($title === '' || nj_admin_string_length($title) > 500) {
        throw new NjApiHttpException(422, 'invalid_post_title');
    }

    if (nj_admin_string_length($excerpt) > 10000) {
        throw new NjApiHttpException(422, 'excerpt_too_large');
    }

    if (nj_admin_string_length($content) > 1000000) {
        throw new NjApiHttpException(422, 'content_too_large');
    }

    $pdo = nj_db();
    $posts = nj_table('posts');

    $currentStatement = $pdo->prepare(<<<SQL
SELECT
    ID,
    post_status,
    post_title,
    post_excerpt,
    post_content
FROM {$posts}
WHERE
    ID = :id
    AND post_type = 'post'
LIMIT 1
SQL);
    $currentStatement->execute(['id' => $postId]);
    $current = $currentStatement->fetch();

    if (!$current) {
        throw new NjApiHttpException(404, 'post_not_found');
    }

    if ((string) $current['post_status'] !== 'draft') {
        throw new NjApiHttpException(409, 'post_not_draft');
    }

    try {
        $pdo->beginTransaction();

        $update = $pdo->prepare(<<<SQL
UPDATE {$posts}
SET
    post_title = :title,
    post_excerpt = :excerpt,
    post_content = :content,
    post_modified = NOW(),
    post_modified_gmt = UTC_TIMESTAMP()
WHERE
    ID = :id
    AND post_type = 'post'
    AND post_status = 'draft'
LIMIT 1
SQL);
        $update->execute([
            'title' => $title,
            'excerpt' => $excerpt,
            'content' => $content,
            'id' => $postId,
        ]);

        $readBack = $pdo->prepare(<<<SQL
SELECT
    post_title AS title,
    post_excerpt AS excerpt,
    post_content AS content,
    post_status AS status,
    post_modified AS modified_at
FROM {$posts}
WHERE ID = :id
LIMIT 1
SQL);
        $readBack->execute(['id' => $postId]);
        $persisted = $readBack->fetch();

        if (
            !$persisted
            || (string) $persisted['status'] !== 'draft'
            || (string) $persisted['title'] !== $title
            || (string) $persisted['excerpt'] !== $excerpt
            || (string) $persisted['content'] !== $content
        ) {
            throw new RuntimeException('draft_readback_mismatch');
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
        'post' => [
            'id' => $postId,
            'title' => $title,
            'excerpt' => $excerpt,
            'content' => $content,
            'status' => 'draft',
            'modifiedAt' => nj_content_iso8601((string) $persisted['modified_at']),
        ],
        'mutation' => [
            'verifiedByReadBack' => true,
            'publishedContentTouched' => false,
        ],
    ];
});
