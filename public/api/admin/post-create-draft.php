<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_posts');
    nj_admin_require_csrf();

    $body = nj_admin_request_body();
    $title = trim((string) ($body['title'] ?? 'Nova notícia'));

    if ($title === '') {
        $title = 'Nova notícia';
    }

    if ((function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)) > 500) {
        throw new NjApiHttpException(422, 'invalid_post_title');
    }

    $pdo = nj_db();
    $posts = nj_table('posts');

    try {
        $pdo->beginTransaction();

        $insert = $pdo->prepare(<<<SQL
INSERT INTO {$posts} (
    post_author,
    post_date,
    post_date_gmt,
    post_content,
    post_title,
    post_excerpt,
    post_status,
    comment_status,
    ping_status,
    post_password,
    post_name,
    to_ping,
    pinged,
    post_modified,
    post_modified_gmt,
    post_content_filtered,
    post_parent,
    guid,
    menu_order,
    post_type,
    post_mime_type,
    comment_count
) VALUES (
    :author_id,
    NOW(),
    UTC_TIMESTAMP(),
    '',
    :title,
    '',
    'draft',
    'closed',
    'closed',
    '',
    '',
    '',
    '',
    NOW(),
    UTC_TIMESTAMP(),
    '',
    0,
    '',
    0,
    'post',
    '',
    0
)
SQL);
        $insert->execute([
            'author_id' => $user['id'],
            'title' => $title,
        ]);

        $postId = (int) $pdo->lastInsertId();

        if ($postId <= 0) {
            throw new RuntimeException('draft_insert_missing_id');
        }

        $readBack = $pdo->prepare(<<<SQL
SELECT
    ID AS id,
    post_author AS author_id,
    post_title AS title,
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
            || (int) $persisted['id'] !== $postId
            || (int) $persisted['author_id'] !== (int) $user['id']
            || (string) $persisted['title'] !== $title
            || (string) $persisted['status'] !== 'draft'
        ) {
            throw new RuntimeException('draft_insert_readback_mismatch');
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
            'status' => 'draft',
            'authorId' => (int) $user['id'],
            'modifiedAt' => nj_content_iso8601((string) $persisted['modified_at']),
            'adminUrl' => '/sistema/noticias/' . $postId,
        ],
        'mutation' => [
            'verifiedByReadBack' => true,
            'publishedContentTouched' => false,
        ],
    ];
});
