<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'moderate_comments');
    nj_admin_require_csrf();

    $body = nj_admin_request_body();
    $commentId = filter_var(
        $body['commentId'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $author = trim((string) ($body['author'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $authorUrl = trim((string) ($body['authorUrl'] ?? ''));
    $content = trim((string) ($body['content'] ?? ''));

    if (!is_int($commentId) || $commentId <= 0) {
        throw new NjApiHttpException(422, 'invalid_comment_id');
    }

    if ($author === '' || (function_exists('mb_strlen') ? mb_strlen($author, 'UTF-8') : strlen($author)) > 245) {
        throw new NjApiHttpException(422, 'invalid_comment_author');
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new NjApiHttpException(422, 'invalid_comment_email');
    }

    if ($authorUrl !== '' && filter_var($authorUrl, FILTER_VALIDATE_URL) === false) {
        throw new NjApiHttpException(422, 'invalid_comment_url');
    }

    $contentLength = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
    if ($content === '' || $contentLength > 200000) {
        throw new NjApiHttpException(422, 'invalid_comment_content');
    }

    $pdo = nj_db();
    $comments = nj_table('comments');

    $exists = $pdo->prepare("SELECT 1 FROM {$comments} WHERE comment_ID = :id LIMIT 1");
    $exists->execute(['id' => $commentId]);

    if (!$exists->fetchColumn()) {
        throw new NjApiHttpException(404, 'comment_not_found');
    }

    $update = $pdo->prepare(<<<SQL
UPDATE {$comments}
SET
    comment_author = :author,
    comment_author_email = :email,
    comment_author_url = :author_url,
    comment_content = :content
WHERE comment_ID = :id
LIMIT 1
SQL);
    $update->execute([
        'author' => $author,
        'email' => $email,
        'author_url' => $authorUrl,
        'content' => $content,
        'id' => $commentId,
    ]);

    return [
        'comment' => [
            'id' => $commentId,
            'author' => $author,
            'email' => $email,
            'authorUrl' => $authorUrl,
            'content' => $content,
        ],
    ];
});
