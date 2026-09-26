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
    $action = trim((string) ($body['action'] ?? ''));

    if (!is_int($commentId) || $commentId <= 0) {
        throw new NjApiHttpException(422, 'invalid_comment_id');
    }

    $targets = [
        'approve' => '1',
        'pending' => '0',
        'spam' => 'spam',
        'trash' => 'trash',
        'restore' => '0',
    ];

    if (!array_key_exists($action, $targets)) {
        throw new NjApiHttpException(422, 'invalid_comment_action');
    }

    $pdo = nj_db();
    $comments = nj_table('comments');

    $currentStatement = $pdo->prepare(<<<SQL
SELECT
    comment_ID AS id,
    comment_approved AS approved
FROM {$comments}
WHERE comment_ID = :id
LIMIT 1
SQL);
    $currentStatement->execute(['id' => $commentId]);
    $current = $currentStatement->fetch();

    if (!$current) {
        throw new NjApiHttpException(404, 'comment_not_found');
    }

    $target = $targets[$action];

    try {
        $pdo->beginTransaction();

        $update = $pdo->prepare(<<<SQL
UPDATE {$comments}
SET comment_approved = :approved
WHERE comment_ID = :id
LIMIT 1
SQL);
        $update->execute([
            'approved' => $target,
            'id' => $commentId,
        ]);

        $readBack = $pdo->prepare(
            "SELECT comment_approved FROM {$comments} WHERE comment_ID = :id LIMIT 1"
        );
        $readBack->execute(['id' => $commentId]);
        $persisted = (string) $readBack->fetchColumn();

        if ($persisted !== $target) {
            throw new RuntimeException('comment_status_readback_mismatch');
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
        'comment' => [
            'id' => $commentId,
            'status' => $target === '1'
                ? 'approved'
                : ($target === '0' ? 'pending' : $target),
        ],
    ];
});
