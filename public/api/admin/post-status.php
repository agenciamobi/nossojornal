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
    $scheduledAt = trim((string) ($body['scheduledAt'] ?? ''));

    if (!is_int($postId) || $postId <= 0) {
        throw new NjApiHttpException(422, 'invalid_post_id');
    }

    if (!in_array($action, ['publish', 'draft', 'schedule'], true)) {
        throw new NjApiHttpException(422, 'invalid_post_action');
    }

    $pdo = nj_db();
    $posts = nj_table('posts');
    $relationships = nj_table('term_relationships');
    $taxonomy = nj_table('term_taxonomy');

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
    $title = trim((string) $post['post_title']);

    if ($authorId !== (int) $user['id'] && !in_array('edit_others_posts', $user['capabilities'], true)) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }

    if (in_array($action, ['publish', 'schedule'], true)) {
        nj_admin_require_capability($user, 'publish_posts');
    }

    if (
        $action === 'draft'
        && in_array($currentStatus, ['publish', 'future', 'private'], true)
        && !in_array('edit_published_posts', $user['capabilities'], true)
    ) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }

    if (in_array($action, ['publish', 'schedule'], true) && $title === '') {
        throw new NjApiHttpException(422, 'post_title_required');
    }

    $slug = (string) $post['post_name'];
    if (in_array($action, ['publish', 'schedule'], true)) {
        $slug = nj_admin_unique_post_slug($pdo, $postId, $slug, $title);
    }

    $targetStatus = $action === 'publish'
        ? 'publish'
        : ($action === 'schedule' ? 'future' : 'draft');

    $localDate = null;
    $gmtDate = null;

    if ($action === 'publish') {
        $timezone = new DateTimeZone('America/Sao_Paulo');
        $now = new DateTimeImmutable('now', $timezone);
        $localDate = $now->format('Y-m-d H:i:s');
        $gmtDate = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    if ($action === 'schedule') {
        if ($scheduledAt === '') {
            throw new NjApiHttpException(422, 'scheduled_at_required');
        }

        try {
            $timezone = new DateTimeZone('America/Sao_Paulo');
            $date = new DateTimeImmutable($scheduledAt, $timezone);

            if ($date <= new DateTimeImmutable('now', $timezone)) {
                throw new NjApiHttpException(422, 'scheduled_at_must_be_future');
            }

            $localDate = $date->setTimezone($timezone)->format('Y-m-d H:i:s');
            $gmtDate = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (NjApiHttpException $error) {
            throw $error;
        } catch (Throwable) {
            throw new NjApiHttpException(422, 'invalid_scheduled_at');
        }
    }

    try {
        $pdo->beginTransaction();

        if ($localDate !== null && $gmtDate !== null) {
            $update = $pdo->prepare(<<<SQL
UPDATE {$posts}
SET
    post_status = :status,
    post_name = :slug,
    post_date = :post_date,
    post_date_gmt = :post_date_gmt,
    post_modified = NOW(),
    post_modified_gmt = UTC_TIMESTAMP()
WHERE ID = :id
LIMIT 1
SQL);
            $update->execute([
                'status' => $targetStatus,
                'slug' => $slug,
                'post_date' => $localDate,
                'post_date_gmt' => $gmtDate,
                'id' => $postId,
            ]);
        } else {
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
        }

        $taxonomyStatement = $pdo->prepare(<<<SQL
SELECT tr.term_taxonomy_id
FROM {$relationships} tr
INNER JOIN {$taxonomy} tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
    AND tt.taxonomy = 'category'
WHERE tr.object_id = :post_id
SQL);
        $taxonomyStatement->execute(['post_id' => $postId]);
        nj_admin_recount_categories(
            $pdo,
            array_map('intval', $taxonomyStatement->fetchAll(PDO::FETCH_COLUMN))
        );

        $readBack = $pdo->prepare(<<<SQL
SELECT
    post_status AS status,
    post_name AS slug,
    post_date AS published_at,
    post_modified AS modified_at
FROM {$posts}
WHERE ID = :id
LIMIT 1
SQL);
        $readBack->execute(['id' => $postId]);
        $persisted = $readBack->fetch();

        if (!$persisted || (string) $persisted['status'] !== $targetStatus) {
            throw new RuntimeException('post_status_readback_mismatch');
        }

        if (in_array($action, ['publish', 'schedule'], true) && (string) $persisted['slug'] !== $slug) {
            throw new RuntimeException('post_slug_readback_mismatch');
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
            'status' => $targetStatus,
            'slug' => $slug,
            'publishedAt' => nj_content_iso8601((string) $persisted['published_at']),
            'modifiedAt' => nj_content_iso8601((string) $persisted['modified_at']),
            'publicUrl' => $slug !== '' ? '/noticia/' . rawurlencode($slug) : null,
        ],
    ];
});
