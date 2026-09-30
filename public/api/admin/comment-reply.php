<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'moderate_comments');
    nj_admin_require_csrf();

    $body = nj_admin_request_body();
    $parentId = filter_var(
        $body['parentId'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $content = trim((string) ($body['content'] ?? ''));

    if (!is_int($parentId) || $parentId <= 0) {
        throw new NjApiHttpException(422, 'invalid_comment_parent');
    }

    $contentLength = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
    if ($content === '' || $contentLength > 200000) {
        throw new NjApiHttpException(422, 'invalid_comment_content');
    }

    $pdo = nj_db();
    $comments = nj_table('comments');

    $parentStatement = $pdo->prepare(<<<SQL
SELECT comment_ID, comment_post_ID
FROM {$comments}
WHERE comment_ID = :id
LIMIT 1
SQL);
    $parentStatement->execute(['id' => $parentId]);
    $parent = $parentStatement->fetch();

    if (!$parent) {
        throw new NjApiHttpException(404, 'comment_not_found');
    }

    $authorName = trim((string) ($user['displayName'] ?? $user['login'] ?? 'Redação'));
    $authorEmail = trim((string) ($user['email'] ?? ''));
    $userId = (int) ($user['id'] ?? 0);

    $insert = $pdo->prepare(<<<SQL
INSERT INTO {$comments} (
    comment_post_ID,
    comment_author,
    comment_author_email,
    comment_author_url,
    comment_author_IP,
    comment_date,
    comment_date_gmt,
    comment_content,
    comment_karma,
    comment_approved,
    comment_agent,
    comment_type,
    comment_parent,
    user_id
) VALUES (
    :post_id,
    :author,
    :email,
    '',
    '',
    NOW(),
    UTC_TIMESTAMP(),
    :content,
    0,
    '1',
    '',
    'comment',
    :parent_id,
    :user_id
)
SQL);
    $insert->execute([
        'post_id' => (int) $parent['comment_post_ID'],
        'author' => $authorName,
        'email' => $authorEmail,
        'content' => $content,
        'parent_id' => $parentId,
        'user_id' => $userId,
    ]);

    $commentId = (int) $pdo->lastInsertId();
    if ($commentId <= 0) {
        throw new RuntimeException('comment_reply_missing_id');
    }

    return [
        'comment' => [
            'id' => $commentId,
            'parentId' => $parentId,
            'postId' => (int) $parent['comment_post_ID'],
            'author' => $authorName,
            'email' => $authorEmail,
            'content' => $content,
            'status' => 'approved',
        ],
    ];
});
