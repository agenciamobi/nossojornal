<?php
declare(strict_types=1);

/** Set only an existing image belonging to an existing draft. */
require __DIR__ . '/_draft.php';

nj_m2m_run('POST', 'editorial.post.featured.set', static function (array $context): array {
    $body = nj_m2m_body($context);
    if (array_diff(array_keys($body), ['post_id', 'attachment_id']) !== []) {
        throw new NjApiHttpException(422, 'body_field_not_allowed');
    }
    $postId = filter_var($body['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $attachmentId = filter_var($body['attachment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!is_int($postId) || !is_int($attachmentId)) {
        throw new NjApiHttpException(422, 'featured_payload_invalid');
    }

    $pdo = $context['pdo'];
    $posts = nj_table('posts');
    $meta = nj_table('postmeta');
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT ID, post_author FROM {$posts}
            WHERE ID = :id AND post_type = 'post' AND post_status = 'draft' LIMIT 1 FOR UPDATE");
        $lock->execute(['id' => $postId]);
        $draft = $lock->fetch();
        if (!is_array($draft)) throw new NjApiHttpException(409, 'featured_draft_required');

        // Do not accept a foreign organization's asset or an arbitrary historical attachment.
        $check = $pdo->prepare("SELECT ID, guid, post_excerpt FROM {$posts}
            WHERE ID = :id AND post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/webp')
              AND post_parent = :post LIMIT 1");
        $check->execute(['id' => $attachmentId, 'post' => $postId]);
        $image = $check->fetch();
        if (!is_array($image)) throw new NjApiHttpException(404, 'featured_attachment_not_owned_by_draft');

        $file = $pdo->prepare("SELECT meta_value FROM {$meta}
            WHERE post_id = :id AND meta_key = '_wp_attached_file' ORDER BY meta_id DESC LIMIT 1");
        $file->execute(['id' => $attachmentId]);
        $relative = (string) $file->fetchColumn();
        $root = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if (!preg_match('#^[0-9]{4}/[0-9]{2}/[a-z0-9._-]+$#i', $relative)
            || $root === '' || !is_file($root . '/wp-content/uploads/' . $relative)
            || is_link($root . '/wp-content/uploads/' . $relative)) {
            throw new NjApiHttpException(409, 'featured_attachment_file_missing');
        }
        // Switching featured media must also switch (or clear) the display
        // credit/caption. Never leave attribution from a different photograph.
        $creditQuery = $pdo->prepare("SELECT meta_value FROM {$meta}
            WHERE post_id = :id AND meta_key = '_nj_media_credit'
            ORDER BY meta_id DESC LIMIT 1");
        $creditQuery->execute(['id' => $attachmentId]);
        $credit = (string) ($creditQuery->fetchColumn() ?: '');
        nj_admin_upsert_postmeta($pdo, $postId, '_thumbnail_id', (string) $attachmentId);
        nj_admin_upsert_postmeta($pdo, $postId, '_nj_image_credit', $credit);
        nj_admin_upsert_postmeta($pdo, $postId, '_nj_image_caption', (string) $image['post_excerpt']);
        $pdo->prepare("UPDATE {$posts} SET post_modified = NOW(), post_modified_gmt = UTC_TIMESTAMP() WHERE ID = :id")
            ->execute(['id' => $postId]);
        nj_admin_log_post_activity($pdo, $postId, (int) $draft['post_author'], 'editorial_featured_set', [
            'attachmentId' => $attachmentId, 'requestId' => (string) $context['requestId'],
        ]);
        $verify = $pdo->prepare("SELECT meta_value FROM {$meta}
            WHERE post_id = :id AND meta_key = '_thumbnail_id' ORDER BY meta_id DESC LIMIT 1");
        $verify->execute(['id' => $postId]);
        if ((int) $verify->fetchColumn() !== $attachmentId) throw new RuntimeException('featured_readback_failed');
        $pdo->commit();
        return ['post_id' => (string) $postId, 'attachment_id' => (string) $attachmentId, 'featured' => true];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
});
