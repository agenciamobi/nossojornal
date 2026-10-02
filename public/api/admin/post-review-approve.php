<?php
declare(strict_types=1);

/** Human-only approval; the M2M service has no access to this CSRF-protected action. */
require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_posts');
    nj_admin_require_capability($user, 'publish_posts');
    nj_admin_require_csrf();
    $body = nj_admin_request_body();
    $postId = filter_var($body['postId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!is_int($postId) || ($body['confirmReviewed'] ?? null) !== true) {
        throw new NjApiHttpException(422, 'human_review_confirmation_required');
    }
    $pdo = nj_db();
    $posts = nj_table('posts');
    $meta = nj_table('postmeta');
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT ID, post_author, post_status, post_title, post_content
            FROM {$posts} WHERE ID = :id AND post_type = 'post' LIMIT 1 FOR UPDATE");
        $lock->execute(['id' => $postId]);
        $post = $lock->fetch();
        if (!is_array($post) || $post['post_status'] !== 'draft') {
            throw new NjApiHttpException(409, 'human_review_draft_required');
        }
        if ((int) $post['post_author'] !== (int) $user['id']
            && !in_array('edit_others_posts', $user['capabilities'], true)) {
            throw new NjApiHttpException(403, 'insufficient_permissions');
        }
        if (trim((string) $post['post_title']) === '' || trim((string) $post['post_content']) === '') {
            throw new NjApiHttpException(422, 'human_review_content_required');
        }
        $flag = $pdo->prepare("SELECT meta_value FROM {$meta}
            WHERE post_id = :id AND meta_key = '_nj_mobi_human_review_required'
            ORDER BY meta_id DESC LIMIT 1");
        $flag->execute(['id' => $postId]);
        if ((string) $flag->fetchColumn() !== '1') {
            throw new NjApiHttpException(409, 'human_review_not_pending');
        }
        nj_admin_upsert_postmeta($pdo, $postId, '_nj_mobi_human_review_required', '0');
        nj_admin_upsert_postmeta($pdo, $postId, '_nj_mobi_reviewed_by', (string) $user['id']);
        nj_admin_upsert_postmeta($pdo, $postId, '_nj_mobi_reviewed_at', gmdate('Y-m-d H:i:s'));
        nj_admin_log_post_activity($pdo, $postId, (int) $user['id'], 'editorial_human_review_approved', [
            'reviewedBy' => (int) $user['id'],
        ]);
        $verify = $pdo->prepare("SELECT meta_value FROM {$meta}
            WHERE post_id = :id AND meta_key = '_nj_mobi_human_review_required'
            ORDER BY meta_id DESC LIMIT 1");
        $verify->execute(['id' => $postId]);
        if ((string) $verify->fetchColumn() !== '0') throw new RuntimeException('review_readback_failed');
        $pdo->commit();
        return ['postId' => $postId, 'reviewRequired' => false, 'reviewed' => true];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
});
