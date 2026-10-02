<?php
declare(strict_types=1);

/**
 * Publishing is a separate granted capability. An AI draft must first be
 * explicitly approved using the authenticated human editor, never this bridge.
 */
require __DIR__ . '/_draft.php';

nj_m2m_run('POST', 'editorial.post.publish', static function (array $context): array {
    $body = nj_m2m_body($context);
    if (array_diff(array_keys($body), ['post_id', 'confirm_publish']) !== []
        || ($body['confirm_publish'] ?? null) !== true) {
        throw new NjApiHttpException(422, 'publish_confirmation_required');
    }
    $postId = filter_var($body['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!is_int($postId)) throw new NjApiHttpException(422, 'publish_post_id_invalid');

    $pdo = $context['pdo'];
    $posts = nj_table('posts');
    $meta = nj_table('postmeta');
    $relationships = nj_table('term_relationships');
    $taxonomy = nj_table('term_taxonomy');
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare("SELECT ID, post_author, post_status, post_title, post_excerpt, post_content, post_name
            FROM {$posts} WHERE ID = :id AND post_type = 'post' LIMIT 1 FOR UPDATE");
        $lock->execute(['id' => $postId]);
        $post = $lock->fetch();
        if (!is_array($post)) throw new NjApiHttpException(404, 'publish_post_not_found');
        if ((string) $post['post_status'] === 'publish') {
            $pdo->commit();
            return ['post_id' => (string) $postId, 'status' => 'publish', 'already_published' => true];
        }
        if ((string) $post['post_status'] !== 'draft') throw new NjApiHttpException(409, 'publish_draft_required');

        $review = $pdo->prepare("SELECT meta_value FROM {$meta}
            WHERE post_id = :id AND meta_key = '_nj_mobi_human_review_required'
            ORDER BY meta_id DESC LIMIT 1");
        $review->execute(['id' => $postId]);
        if ((string) $review->fetchColumn() === '1') throw new NjApiHttpException(409, 'publish_human_review_required');

        if (trim((string) $post['post_title']) === '' || trim((string) $post['post_content']) === ''
            || trim((string) $post['post_excerpt']) === '') {
            throw new NjApiHttpException(422, 'publish_required_editorial_content_missing');
        }
        $featured = $pdo->prepare("SELECT meta_value FROM {$meta}
            WHERE post_id = :id AND meta_key = '_thumbnail_id' ORDER BY meta_id DESC LIMIT 1");
        $featured->execute(['id' => $postId]);
        $featuredId = (int) $featured->fetchColumn();
        if ($featuredId <= 0) throw new NjApiHttpException(422, 'publish_featured_image_required');
        $image = $pdo->prepare("SELECT ID FROM {$posts} WHERE ID = :id AND post_type = 'attachment'
            AND post_mime_type LIKE 'image/%' LIMIT 1");
        $image->execute(['id' => $featuredId]);
        if (!$image->fetchColumn()) throw new NjApiHttpException(422, 'publish_featured_image_invalid');
        $categories = $pdo->prepare("SELECT tr.term_taxonomy_id FROM {$relationships} tr
            JOIN {$taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
            WHERE tr.object_id = :id");
        $categories->execute(['id' => $postId]);
        $categoryIds = array_map('intval', $categories->fetchAll(PDO::FETCH_COLUMN));
        if ($categoryIds === []) throw new NjApiHttpException(422, 'publish_category_required');

        $slug = nj_admin_unique_post_slug($pdo, $postId, (string) $post['post_name'], (string) $post['post_title']);
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $publish = $pdo->prepare("UPDATE {$posts} SET post_status = 'publish', post_name = :slug,
            post_date = :local, post_date_gmt = :gmt,
            post_modified = NOW(), post_modified_gmt = UTC_TIMESTAMP()
            WHERE ID = :id AND post_status = 'draft' LIMIT 1");
        $publish->execute([
            'slug' => $slug,
            'local' => $now->format('Y-m-d H:i:s'),
            'gmt' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'id' => $postId,
        ]);
        if ($publish->rowCount() !== 1) throw new RuntimeException('publish_transition_failed');
        nj_admin_upsert_postmeta($pdo, $postId, '_nj_editorial_stage', 'published');
        nj_admin_recount_categories($pdo, $categoryIds);
        nj_admin_log_post_activity($pdo, $postId, (int) $post['post_author'], 'editorial_mobi_published', [
            'reviewed' => true, 'requestId' => (string) $context['requestId'],
        ]);
        $read = $pdo->prepare("SELECT post_status, post_name FROM {$posts} WHERE ID = :id LIMIT 1");
        $read->execute(['id' => $postId]);
        $saved = $read->fetch();
        if (!is_array($saved) || $saved['post_status'] !== 'publish' || $saved['post_name'] !== $slug) {
            throw new RuntimeException('publish_readback_failed');
        }
        $pdo->commit();
        return ['post_id' => (string) $postId, 'status' => 'publish',
            'slug' => $slug, 'already_published' => false];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
});
