<?php
declare(strict_types=1);

require __DIR__ . '/_draft.php';
require __DIR__ . '/_video.php';

nj_m2m_run('POST', 'editorial.video.attach', static function (array $context): array {
    $body = nj_m2m_body($context);
    $allowed = ['post_id', 'video_url', 'source_url', 'title', 'caption',
                'credit', 'license', 'seo_title', 'seo_description'];
    if (array_diff(array_keys($body), $allowed) !== []) {
        throw new NjApiHttpException(422, 'body_field_not_allowed');
    }

    $postId = filter_var($body['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $title = trim((string) ($body['title'] ?? ''));
    $caption = trim((string) ($body['caption'] ?? ''));
    $credit = trim((string) ($body['credit'] ?? ''));
    $license = trim((string) ($body['license'] ?? ''));
    $seoTitle = trim((string) ($body['seo_title'] ?? ''));
    $seoDescription = trim((string) ($body['seo_description'] ?? ''));
    $length = static fn (string $value): int => function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8') : strlen($value);
    if (!is_int($postId) || $postId <= 0 || $title === '' || $length($title) > 160
        || $credit === '' || $length($credit) > 1000
        || $length($caption) > 1000 || $length($license) > 200
        || $length($seoTitle) > 180 || $length($seoDescription) > 400
    ) throw new NjApiHttpException(422, 'video_payload_invalid');

    $videoId = nj_video_youtube_id(trim((string) ($body['video_url'] ?? '')));
    $sourcePage = nj_video_source_page(trim((string) ($body['source_url'] ?? '')), $videoId);
    $canonical = 'https://www.youtube.com/watch?v=' . $videoId;
    $pdo = $context['pdo'];
    $posts = nj_table('posts');
    $meta = nj_table('postmeta');
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare(
            "SELECT ID, post_author FROM {$posts} WHERE ID = :id AND post_type = 'post'
             AND post_status = 'draft' LIMIT 1 FOR UPDATE"
        );
        $lock->execute(['id' => $postId]);
        $post = $lock->fetch();
        if (!is_array($post)) throw new NjApiHttpException(409, 'video_draft_required');

        $existingQuery = $pdo->prepare(
            "SELECT p.ID FROM {$posts} p
             INNER JOIN {$meta} m ON m.post_id = p.ID AND m.meta_key = '_nj_embed_youtube_id'
             INNER JOIN {$meta} s ON s.post_id = p.ID AND s.meta_key = '_nj_remote_media_source_page'
             WHERE p.post_type = 'attachment' AND p.post_mime_type = 'video/x-embed'
               AND m.meta_value = :video_id AND s.meta_value = :source_page
             ORDER BY p.ID ASC LIMIT 1"
        );
        $existingQuery->execute(['video_id' => $videoId, 'source_page' => $sourcePage]);
        $attachmentId = (int) ($existingQuery->fetchColumn() ?: 0);
        $reused = $attachmentId > 0;

        if ($attachmentId === 0) {
            $insert = $pdo->prepare(
                "INSERT INTO {$posts} (
                  post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
                  post_status, comment_status, ping_status, post_password, post_name, to_ping,
                  pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent,
                  guid, menu_order, post_type, post_mime_type, comment_count
                ) VALUES (
                  :author, NOW(), UTC_TIMESTAMP(), '', :title, :caption,
                  'inherit','closed','closed','', :slug, '', '',
                  NOW(),UTC_TIMESTAMP(),'', :parent, :guid,0,'attachment','video/x-embed',0
                )"
            );
            $insert->execute([
                'author' => (int) $post['post_author'],
                'title' => $title,
                'caption' => $caption,
                'slug' => nj_admin_slugify('video-' . $videoId),
                'parent' => $postId,
                'guid' => $canonical,
            ]);
            $attachmentId = (int) $pdo->lastInsertId();
            if ($attachmentId <= 0) throw new RuntimeException('video_attachment_missing');
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_embed_youtube_id', $videoId);
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_remote_media_source_page', $sourcePage);
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_remote_media_source_url', $canonical);
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_wp_attachment_image_alt', '');
        }
        // Reusing a library record never overwrites metadata already curated
        // for another news item, especially a potentially published one.
        if (!$reused) {
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_media_credit', $credit);
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_media_license', $license);
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_media_seo_title', $seoTitle);
            nj_admin_upsert_postmeta($pdo, $attachmentId, '_nj_media_seo_description', $seoDescription);
        }

        $linked = $pdo->prepare(
            "SELECT meta_value FROM {$meta} WHERE post_id = :post AND meta_key = '_nj_editorial_video_attachment_ids'
             ORDER BY meta_id DESC LIMIT 1"
        );
        $linked->execute(['post' => $postId]);
        $ids = json_decode((string) $linked->fetchColumn(), true);
        $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)) : [];
        if (!in_array($attachmentId, $ids, true)) $ids[] = $attachmentId;
        if (count($ids) > 6) throw new NjApiHttpException(422, 'video_limit_reached');
        nj_admin_upsert_postmeta(
            $pdo, $postId, '_nj_editorial_video_attachment_ids',
            json_encode($ids, JSON_THROW_ON_ERROR)
        );
        nj_admin_log_post_activity($pdo, $postId, (int) $post['post_author'], 'editorial_video_attached', [
            'attachmentId' => $attachmentId, 'sourcePage' => $sourcePage,
            'reused' => $reused, 'requestId' => (string) $context['requestId'],
        ]);
        $verify = $pdo->prepare(
            "SELECT meta_value FROM {$meta} WHERE post_id = :post AND meta_key = '_nj_editorial_video_attachment_ids'
             ORDER BY meta_id DESC LIMIT 1"
        );
        $verify->execute(['post' => $postId]);
        $persisted = json_decode((string) $verify->fetchColumn(), true);
        if (!is_array($persisted) || !in_array($attachmentId, array_map('intval', $persisted), true)) {
            throw new RuntimeException('video_link_readback_failed');
        }
        $pdo->commit();
        return [
            'post_id' => (string) $postId,
            'attachment_id' => (string) $attachmentId,
            'video_url' => $canonical,
            'embed_url' => 'https://www.youtube-nocookie.com/embed/' . $videoId,
            'source_page' => $sourcePage,
            'reused' => $reused,
            'credit' => $credit,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
});
