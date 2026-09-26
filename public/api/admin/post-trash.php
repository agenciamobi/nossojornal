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
    $action = trim((string) ($body['action'] ?? ''));

    if (!is_int($postId) || $postId <= 0) {
        throw new NjApiHttpException(422, 'invalid_post_id');
    }

    if (!in_array($action, ['trash', 'restore'], true)) {
        throw new NjApiHttpException(422, 'invalid_trash_action');
    }

    $pdo = nj_db();
    $posts = nj_table('posts');
    $relationships = nj_table('term_relationships');
    $taxonomy = nj_table('term_taxonomy');
    $postmeta = nj_table('postmeta');

    $statement = $pdo->prepare(<<<SQL
SELECT
    ID,
    post_author,
    post_status,
    post_title,
    post_name,
    post_date,
    post_date_gmt
FROM {$posts}
WHERE
    ID = :id
    AND post_type = 'post'
LIMIT 1
SQL);
    $statement->execute(['id' => $postId]);
    $post = $statement->fetch();

    if (!$post) {
        throw new NjApiHttpException(404, 'post_not_found');
    }

    $authorId = (int) $post['post_author'];
    $currentStatus = (string) $post['post_status'];

    if ($authorId !== (int) $user['id']) {
        if (!in_array('delete_others_posts', $user['capabilities'], true)) {
            throw new NjApiHttpException(403, 'insufficient_permissions');
        }
    } elseif (!in_array('delete_posts', $user['capabilities'], true)) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }

    if (
        in_array($currentStatus, ['publish', 'future', 'private'], true)
        && !in_array('delete_published_posts', $user['capabilities'], true)
    ) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }

    if ($action === 'trash' && $currentStatus === 'trash') {
        throw new NjApiHttpException(409, 'post_already_trashed');
    }

    if ($action === 'restore' && $currentStatus !== 'trash') {
        throw new NjApiHttpException(409, 'post_not_trashed');
    }

    $targetStatus = 'trash';

    if ($action === 'restore') {
        $restoreStatus = 'draft';

        $metaStatement = $pdo->prepare(<<<SQL
SELECT meta_value
FROM {$postmeta}
WHERE
    post_id = :post_id
    AND meta_key = '_wp_trash_meta_status'
ORDER BY meta_id DESC
LIMIT 1
SQL);
        $metaStatement->execute(['post_id' => $postId]);
        $storedStatus = trim((string) ($metaStatement->fetchColumn() ?: ''));

        if (in_array($storedStatus, ['publish', 'draft', 'pending', 'future', 'private'], true)) {
            $restoreStatus = $storedStatus;
        }

        if (
            in_array($restoreStatus, ['publish', 'future', 'private'], true)
            && (
                !in_array('edit_published_posts', $user['capabilities'], true)
                || !in_array('publish_posts', $user['capabilities'], true)
            )
        ) {
            $restoreStatus = 'draft';
        }

        $targetStatus = $restoreStatus;
    }

    try {
        $pdo->beginTransaction();

        $taxonomyStatement = $pdo->prepare(<<<SQL
SELECT tr.term_taxonomy_id
FROM {$relationships} tr
INNER JOIN {$taxonomy} tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
    AND tt.taxonomy = 'category'
WHERE tr.object_id = :post_id
SQL);
        $taxonomyStatement->execute(['post_id' => $postId]);
        $taxonomyIds = array_map('intval', $taxonomyStatement->fetchAll(PDO::FETCH_COLUMN));

        if ($action === 'trash') {
            nj_admin_upsert_postmeta($pdo, $postId, '_wp_trash_meta_status', $currentStatus);
            nj_admin_upsert_postmeta($pdo, $postId, '_wp_trash_meta_time', (string) time());
        } else {
            nj_admin_upsert_postmeta($pdo, $postId, '_wp_trash_meta_status', '');
            nj_admin_upsert_postmeta($pdo, $postId, '_wp_trash_meta_time', '');
        }

        $update = $pdo->prepare(<<<SQL
UPDATE {$posts}
SET
    post_status = :status,
    post_modified = NOW(),
    post_modified_gmt = UTC_TIMESTAMP()
WHERE ID = :id
LIMIT 1
SQL);
        $update->execute([
            'status' => $targetStatus,
            'id' => $postId,
        ]);

        nj_admin_recount_categories($pdo, $taxonomyIds);

        $readBack = $pdo->prepare(<<<SQL
SELECT
    post_status AS status,
    post_name AS slug,
    post_modified AS modified_at
FROM {$posts}
WHERE ID = :id
LIMIT 1
SQL);
        $readBack->execute(['id' => $postId]);
        $persisted = $readBack->fetch();

        if (!$persisted || (string) $persisted['status'] !== $targetStatus) {
            throw new RuntimeException('post_trash_readback_mismatch');
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

    $slug = (string) $persisted['slug'];

    return [
        'post' => [
            'id' => $postId,
            'status' => $targetStatus,
            'modifiedAt' => nj_content_iso8601((string) $persisted['modified_at']),
            'publicUrl' => $targetStatus === 'publish' && $slug !== ''
                ? '/noticia/' . rawurlencode($slug)
                : null,
        ],
    ];
});
