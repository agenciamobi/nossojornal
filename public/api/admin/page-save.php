<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_pages');
    nj_admin_require_csrf();

    $body = nj_admin_request_body();

    $pageId = filter_var(
        $body['pageId'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $title = trim((string) ($body['title'] ?? ''));
    $requestedSlug = trim((string) ($body['slug'] ?? ''));
    $excerpt = (string) ($body['excerpt'] ?? '');
    $content = (string) ($body['content'] ?? '');
    $seoTitle = trim((string) ($body['seoTitle'] ?? ''));
    $seoDescription = trim((string) ($body['seoDescription'] ?? ''));

    if (!is_int($pageId) || $pageId <= 0) {
        throw new NjApiHttpException(422, 'invalid_page_id');
    }

    if ($title === '' || (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)) > 500) {
        throw new NjApiHttpException(422, 'invalid_page_title');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($excerpt, 'UTF-8') : strlen($excerpt)) > 10000) {
        throw new NjApiHttpException(422, 'page_excerpt_too_large');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content)) > 1500000) {
        throw new NjApiHttpException(422, 'page_content_too_large');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($seoTitle, 'UTF-8') : strlen($seoTitle)) > 500) {
        throw new NjApiHttpException(422, 'seo_title_too_large');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($seoDescription, 'UTF-8') : strlen($seoDescription)) > 1000) {
        throw new NjApiHttpException(422, 'seo_description_too_large');
    }

    $pdo = nj_db();
    $posts = nj_table('posts');

    $currentStatement = $pdo->prepare(<<<SQL
SELECT
    ID,
    post_author,
    post_status,
    post_name
FROM {$posts}
WHERE
    ID = :id
    AND post_type = 'page'
LIMIT 1
SQL);
    $currentStatement->execute(['id' => $pageId]);
    $current = $currentStatement->fetch();

    if (!$current) {
        throw new NjApiHttpException(404, 'page_not_found');
    }

    $authorId = (int) $current['post_author'];
    $status = (string) $current['post_status'];
    $currentSlug = (string) $current['post_name'];

    if ($authorId !== (int) $user['id'] && !in_array('edit_others_pages', $user['capabilities'], true)) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }

    if (
        in_array($status, ['publish', 'private', 'future'], true)
        && !in_array('edit_published_pages', $user['capabilities'], true)
    ) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }

    $slugLocked = in_array($currentSlug, ['quem-somos', 'contato'], true);
    $slug = $slugLocked
        ? $currentSlug
        : nj_admin_unique_page_slug($pdo, $pageId, $requestedSlug, $title);

    try {
        $pdo->beginTransaction();

        $update = $pdo->prepare(<<<SQL
UPDATE {$posts}
SET
    post_title = :title,
    post_name = :slug,
    post_excerpt = :excerpt,
    post_content = :content,
    post_modified = NOW(),
    post_modified_gmt = UTC_TIMESTAMP()
WHERE
    ID = :id
    AND post_type = 'page'
LIMIT 1
SQL);
        $update->execute([
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $excerpt,
            'content' => $content,
            'id' => $pageId,
        ]);

        nj_admin_upsert_postmeta($pdo, $pageId, '_yoast_wpseo_title', $seoTitle);
        nj_admin_upsert_postmeta($pdo, $pageId, '_yoast_wpseo_metadesc', $seoDescription);

        $readBack = $pdo->prepare(<<<SQL
SELECT
    post_title AS title,
    post_name AS slug,
    post_excerpt AS excerpt,
    post_content AS content,
    post_modified AS modified_at
FROM {$posts}
WHERE ID = :id
LIMIT 1
SQL);
        $readBack->execute(['id' => $pageId]);
        $persisted = $readBack->fetch();

        if (
            !$persisted
            || (string) $persisted['title'] !== $title
            || (string) $persisted['slug'] !== $slug
            || (string) $persisted['excerpt'] !== $excerpt
            || (string) $persisted['content'] !== $content
        ) {
            throw new RuntimeException('page_readback_mismatch');
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
        'page' => [
            'id' => $pageId,
            'title' => $title,
            'slug' => $slug,
            'slugLocked' => $slugLocked,
            'excerpt' => $excerpt,
            'content' => $content,
            'status' => $status,
            'seo' => [
                'title' => $seoTitle,
                'description' => $seoDescription,
            ],
            'modifiedAt' => nj_content_iso8601((string) $persisted['modified_at']),
            'publicUrl' => nj_admin_page_public_url($slug),
        ],
    ];
});
