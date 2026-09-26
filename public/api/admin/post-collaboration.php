<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['GET', 'POST'], static function (string $method): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_posts');

    $pdo = nj_db();
    $posts = nj_table('posts');
    $users = nj_table('users');
    $postmeta = nj_table('postmeta');

    $readItems = static function (int $postId) use ($pdo, $posts, $users, $postmeta): array {
        $comments = [];
        $commentStatement = $pdo->prepare(<<<SQL
SELECT
    c.ID,
    c.post_author,
    c.post_content,
    c.post_date,
    c.post_modified,
    COALESCE(u.display_name, u.user_login, '') AS author_name,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = c.ID AND pm.meta_key = '_nj_editorial_comment_resolved'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '0') AS resolved
FROM {$posts} c
LEFT JOIN {$users} u ON u.ID = c.post_author
WHERE
    c.post_type = 'nj_editorial_comment'
    AND c.post_parent = :post_id
    AND c.post_status = 'private'
ORDER BY c.post_date DESC, c.ID DESC
LIMIT 100
SQL);
        $commentStatement->execute(['post_id' => $postId]);

        foreach ($commentStatement->fetchAll() as $row) {
            $comments[] = [
                'id' => (int) $row['ID'],
                'text' => (string) $row['post_content'],
                'resolved' => (string) $row['resolved'] === '1',
                'createdAt' => nj_content_iso8601((string) $row['post_date']),
                'modifiedAt' => nj_content_iso8601((string) $row['post_modified']),
                'author' => [
                    'id' => (int) $row['post_author'],
                    'name' => (string) $row['author_name'],
                ],
            ];
        }

        $corrections = [];
        $correctionStatement = $pdo->prepare(<<<SQL
SELECT
    c.ID,
    c.post_author,
    c.post_title,
    c.post_content,
    c.post_date,
    c.post_modified,
    COALESCE(u.display_name, u.user_login, '') AS author_name,
    COALESCE((
        SELECT pm.meta_value
        FROM {$postmeta} pm
        WHERE pm.post_id = c.ID AND pm.meta_key = '_nj_correction_public'
        ORDER BY pm.meta_id DESC
        LIMIT 1
    ), '0') AS is_public
FROM {$posts} c
LEFT JOIN {$users} u ON u.ID = c.post_author
WHERE
    c.post_type = 'nj_correction'
    AND c.post_parent = :post_id
    AND c.post_status = 'private'
ORDER BY c.post_date DESC, c.ID DESC
LIMIT 100
SQL);
        $correctionStatement->execute(['post_id' => $postId]);

        foreach ($correctionStatement->fetchAll() as $row) {
            $corrections[] = [
                'id' => (int) $row['ID'],
                'type' => (string) $row['post_title'],
                'text' => (string) $row['post_content'],
                'public' => (string) $row['is_public'] === '1',
                'createdAt' => nj_content_iso8601((string) $row['post_date']),
                'modifiedAt' => nj_content_iso8601((string) $row['post_modified']),
                'author' => [
                    'id' => (int) $row['post_author'],
                    'name' => (string) $row['author_name'],
                ],
            ];
        }

        return [
            'comments' => $comments,
            'corrections' => $corrections,
        ];
    };

    if ($method === 'GET') {
        $postId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (!is_int($postId) || $postId <= 0) {
            throw new NjApiHttpException(422, 'invalid_post_id');
        }

        nj_admin_require_post_editor($pdo, $user, $postId);

        return $readItems($postId);
    }

    nj_admin_require_csrf();

    $body = nj_admin_request_body();
    $postId = filter_var($body['postId'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $action = trim((string) ($body['action'] ?? ''));

    if (!is_int($postId) || $postId <= 0) {
        throw new NjApiHttpException(422, 'invalid_post_id');
    }

    nj_admin_require_post_editor($pdo, $user, $postId);

    $insertChild = static function (
        string $type,
        string $title,
        string $content
    ) use ($pdo, $posts, $user, $postId): int {
        $statement = $pdo->prepare(<<<SQL
INSERT INTO {$posts} (
    post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
    post_status, comment_status, ping_status, post_password, post_name, to_ping,
    pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent,
    guid, menu_order, post_type, post_mime_type, comment_count
) VALUES (
    :author_id, NOW(), UTC_TIMESTAMP(), :content, :title, '',
    'private', 'closed', 'closed', '', '', '', '',
    NOW(), UTC_TIMESTAMP(), '', :post_parent, '', 0, :post_type, '', 0
)
SQL);
        $statement->execute([
            'author_id' => $user['id'],
            'content' => $content,
            'title' => $title,
            'post_parent' => $postId,
            'post_type' => $type,
        ]);

        return (int) $pdo->lastInsertId();
    };

    if ($action === 'comment_add') {
        $text = trim((string) ($body['text'] ?? ''));
        if ($text === '' || (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) > 10000) {
            throw new NjApiHttpException(422, 'invalid_editorial_comment');
        }

        $pdo->beginTransaction();
        try {
            $commentId = $insertChild('nj_editorial_comment', 'Comentário interno', $text);
            nj_admin_upsert_postmeta($pdo, $commentId, '_nj_editorial_comment_resolved', '0');
            nj_admin_log_post_activity($pdo, $postId, (int) $user['id'], 'comment_added', ['commentId' => $commentId]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }

        return $readItems($postId);
    }

    if ($action === 'comment_toggle') {
        $commentId = (int) ($body['commentId'] ?? 0);
        $resolved = ($body['resolved'] ?? false) === true;

        $check = $pdo->prepare("SELECT ID FROM {$posts} WHERE ID=:id AND post_type='nj_editorial_comment' AND post_parent=:post_id LIMIT 1");
        $check->execute(['id' => $commentId, 'post_id' => $postId]);
        if (!$check->fetchColumn()) {
            throw new NjApiHttpException(404, 'editorial_comment_not_found');
        }

        nj_admin_upsert_postmeta($pdo, $commentId, '_nj_editorial_comment_resolved', $resolved ? '1' : '0');
        nj_admin_log_post_activity($pdo, $postId, (int) $user['id'], $resolved ? 'comment_resolved' : 'comment_reopened', ['commentId' => $commentId]);

        return $readItems($postId);
    }

    if ($action === 'correction_add') {
        $type = trim((string) ($body['type'] ?? 'update'));
        if (!in_array($type, ['update', 'correction'], true)) {
            throw new NjApiHttpException(422, 'invalid_correction_type');
        }

        $text = trim((string) ($body['text'] ?? ''));
        $isPublic = ($body['public'] ?? false) === true;

        if ($text === '' || (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) > 10000) {
            throw new NjApiHttpException(422, 'invalid_correction_text');
        }

        $pdo->beginTransaction();
        try {
            $correctionId = $insertChild('nj_correction', $type, $text);
            nj_admin_upsert_postmeta($pdo, $correctionId, '_nj_correction_public', $isPublic ? '1' : '0');
            nj_admin_log_post_activity($pdo, $postId, (int) $user['id'], 'correction_added', [
                'correctionId' => $correctionId,
                'type' => $type,
                'public' => $isPublic,
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }

        return $readItems($postId);
    }

    if ($action === 'correction_public') {
        $correctionId = (int) ($body['correctionId'] ?? 0);
        $isPublic = ($body['public'] ?? false) === true;

        $check = $pdo->prepare("SELECT ID FROM {$posts} WHERE ID=:id AND post_type='nj_correction' AND post_parent=:post_id LIMIT 1");
        $check->execute(['id' => $correctionId, 'post_id' => $postId]);
        if (!$check->fetchColumn()) {
            throw new NjApiHttpException(404, 'correction_not_found');
        }

        nj_admin_upsert_postmeta($pdo, $correctionId, '_nj_correction_public', $isPublic ? '1' : '0');
        nj_admin_log_post_activity($pdo, $postId, (int) $user['id'], 'correction_visibility_changed', [
            'correctionId' => $correctionId,
            'public' => $isPublic,
        ]);

        return $readItems($postId);
    }

    throw new NjApiHttpException(422, 'invalid_collaboration_action');
});
