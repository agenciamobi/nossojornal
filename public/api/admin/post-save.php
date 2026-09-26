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
    $title = trim((string) ($body['title'] ?? ''));
    $requestedSlug = trim((string) ($body['slug'] ?? ''));
    $excerpt = (string) ($body['excerpt'] ?? '');
    $content = (string) ($body['content'] ?? '');
    $seoTitle = trim((string) ($body['seoTitle'] ?? ''));
    $seoDescription = trim((string) ($body['seoDescription'] ?? ''));
    $primaryCategoryId = (int) ($body['primaryCategoryId'] ?? 0);
    $categoryIds = array_values(array_unique(array_filter(array_map(
        static fn (mixed $value): int => (int) $value,
        is_array($body['categoryIds'] ?? null) ? $body['categoryIds'] : []
    ))));

    $tagNamesInput = is_array($body['tagNames'] ?? null) ? $body['tagNames'] : [];
    $tagNames = [];
    foreach ($tagNamesInput as $tagNameInput) {
        $tagName = trim((string) $tagNameInput);
        if ($tagName === '') {
            continue;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($tagName, 'UTF-8') : strlen($tagName);
        if ($length > 80) {
            throw new NjApiHttpException(422, 'tag_name_too_large');
        }

        $tagNames[function_exists('mb_strtolower') ? mb_strtolower($tagName, 'UTF-8') : strtolower($tagName)] = $tagName;
    }
    $tagNames = array_values($tagNames);

    if (count($tagNames) > 20) {
        throw new NjApiHttpException(422, 'too_many_tags');
    }

    if (!is_int($postId) || $postId <= 0) {
        throw new NjApiHttpException(422, 'invalid_post_id');
    }

    $titleLength = function_exists('mb_strlen')
        ? mb_strlen($title, 'UTF-8')
        : strlen($title);

    if ($title === '' || $titleLength > 500) {
        throw new NjApiHttpException(422, 'invalid_post_title');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($excerpt, 'UTF-8') : strlen($excerpt)) > 10000) {
        throw new NjApiHttpException(422, 'excerpt_too_large');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content)) > 1500000) {
        throw new NjApiHttpException(422, 'content_too_large');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($seoTitle, 'UTF-8') : strlen($seoTitle)) > 500) {
        throw new NjApiHttpException(422, 'seo_title_too_large');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($seoDescription, 'UTF-8') : strlen($seoDescription)) > 1000) {
        throw new NjApiHttpException(422, 'seo_description_too_large');
    }

    $pdo = nj_db();
    $posts = nj_table('posts');
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $relationships = nj_table('term_relationships');

    $currentStatement = $pdo->prepare(<<<SQL
SELECT
    ID,
    post_author,
    post_status,
    post_name,
    post_title
FROM {$posts}
WHERE
    ID = :id
    AND post_type = 'post'
LIMIT 1
SQL);
    $currentStatement->execute(['id' => $postId]);
    $current = $currentStatement->fetch();

    if (!$current) {
        throw new NjApiHttpException(404, 'post_not_found');
    }

    $status = (string) $current['post_status'];
    $authorId = (int) $current['post_author'];

    if ($authorId !== (int) $user['id'] && !in_array('edit_others_posts', $user['capabilities'], true)) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }

    if (
        in_array($status, ['publish', 'private', 'future'], true)
        && !in_array('edit_published_posts', $user['capabilities'], true)
    ) {
        throw new NjApiHttpException(403, 'insufficient_permissions');
    }

    $slug = nj_admin_slugify($requestedSlug);
    if ($slug === '' && in_array($status, ['publish', 'private', 'future'], true)) {
        $slug = nj_admin_unique_post_slug($pdo, $postId, '', $title);
    } elseif ($slug !== '') {
        $slug = nj_admin_unique_post_slug($pdo, $postId, $slug, $title);
    }

    $taxonomyIds = [];
    if ($categoryIds !== []) {
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $categoryStatement = $pdo->prepare(<<<SQL
SELECT term_id, term_taxonomy_id
FROM {$taxonomy}
WHERE
    taxonomy = 'category'
    AND term_id IN ({$placeholders})
SQL);
        $categoryStatement->execute($categoryIds);

        foreach ($categoryStatement->fetchAll() as $categoryRow) {
            $taxonomyIds[(int) $categoryRow['term_id']] = (int) $categoryRow['term_taxonomy_id'];
        }

        if (count($taxonomyIds) !== count($categoryIds)) {
            throw new NjApiHttpException(422, 'invalid_categories');
        }
    }

    if ($primaryCategoryId > 0 && !in_array($primaryCategoryId, $categoryIds, true)) {
        throw new NjApiHttpException(422, 'invalid_primary_category');
    }

    try {
        $pdo->beginTransaction();

        nj_admin_create_revision($pdo, $postId, (int) $user['id'], 'save');

        $oldTaxonomyStatement = $pdo->prepare(<<<SQL
SELECT tr.term_taxonomy_id
FROM {$relationships} tr
INNER JOIN {$taxonomy} tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
    AND tt.taxonomy = 'category'
WHERE tr.object_id = :post_id
SQL);
        $oldTaxonomyStatement->execute(['post_id' => $postId]);
        $oldTaxonomyIds = array_map('intval', $oldTaxonomyStatement->fetchAll(PDO::FETCH_COLUMN));

        $oldTagStatement = $pdo->prepare(<<<SQL
SELECT tr.term_taxonomy_id
FROM {$relationships} tr
INNER JOIN {$taxonomy} tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
    AND tt.taxonomy = 'post_tag'
WHERE tr.object_id = :post_id
SQL);
        $oldTagStatement->execute(['post_id' => $postId]);
        $oldTagTaxonomyIds = array_map('intval', $oldTagStatement->fetchAll(PDO::FETCH_COLUMN));

        $updatePost = $pdo->prepare(<<<SQL
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
    AND post_type = 'post'
LIMIT 1
SQL);
        $updatePost->execute([
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $excerpt,
            'content' => $content,
            'id' => $postId,
        ]);

        $deleteCategoryRelations = $pdo->prepare(<<<SQL
DELETE tr
FROM {$relationships} tr
INNER JOIN {$taxonomy} tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
    AND tt.taxonomy = 'category'
WHERE tr.object_id = :post_id
SQL);
        $deleteCategoryRelations->execute(['post_id' => $postId]);

        if ($taxonomyIds !== []) {
            $insertRelationship = $pdo->prepare(<<<SQL
INSERT INTO {$relationships} (object_id, term_taxonomy_id, term_order)
VALUES (:post_id, :taxonomy_id, 0)
SQL);

            foreach ($taxonomyIds as $taxonomyId) {
                $insertRelationship->execute([
                    'post_id' => $postId,
                    'taxonomy_id' => $taxonomyId,
                ]);
            }
        }

        $deleteTagRelations = $pdo->prepare(<<<SQL
DELETE tr
FROM {$relationships} tr
INNER JOIN {$taxonomy} tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
    AND tt.taxonomy = 'post_tag'
WHERE tr.object_id = :post_id
SQL);
        $deleteTagRelations->execute(['post_id' => $postId]);

        $newTagTaxonomyIds = [];
        $findTermBySlug = $pdo->prepare(
            "SELECT term_id FROM {$terms} WHERE slug = :slug LIMIT 1"
        );
        $findTagTaxonomy = $pdo->prepare(
            "SELECT term_taxonomy_id FROM {$taxonomy}
             WHERE term_id = :term_id AND taxonomy = 'post_tag'
             LIMIT 1"
        );
        $insertTerm = $pdo->prepare(
            "INSERT INTO {$terms} (name, slug, term_group)
             VALUES (:name, :slug, 0)"
        );
        $insertTagTaxonomy = $pdo->prepare(
            "INSERT INTO {$taxonomy} (term_id, taxonomy, description, parent, count)
             VALUES (:term_id, 'post_tag', '', 0, 0)"
        );
        $insertTagRelationship = $pdo->prepare(<<<SQL
INSERT INTO {$relationships} (object_id, term_taxonomy_id, term_order)
VALUES (:post_id, :taxonomy_id, 0)
SQL);

        foreach ($tagNames as $tagName) {
            $tagSlug = nj_admin_slugify($tagName);
            if ($tagSlug === '') {
                continue;
            }

            $findTermBySlug->execute(['slug' => $tagSlug]);
            $termId = (int) ($findTermBySlug->fetchColumn() ?: 0);

            if ($termId <= 0) {
                $insertTerm->execute([
                    'name' => $tagName,
                    'slug' => $tagSlug,
                ]);
                $termId = (int) $pdo->lastInsertId();
            }

            $findTagTaxonomy->execute(['term_id' => $termId]);
            $tagTaxonomyId = (int) ($findTagTaxonomy->fetchColumn() ?: 0);

            if ($tagTaxonomyId <= 0) {
                $insertTagTaxonomy->execute(['term_id' => $termId]);
                $tagTaxonomyId = (int) $pdo->lastInsertId();
            }

            if ($tagTaxonomyId <= 0 || in_array($tagTaxonomyId, $newTagTaxonomyIds, true)) {
                continue;
            }

            $newTagTaxonomyIds[] = $tagTaxonomyId;
            $insertTagRelationship->execute([
                'post_id' => $postId,
                'taxonomy_id' => $tagTaxonomyId,
            ]);
        }

        nj_admin_upsert_postmeta($pdo, $postId, '_yoast_wpseo_title', $seoTitle);
        nj_admin_upsert_postmeta($pdo, $postId, '_yoast_wpseo_metadesc', $seoDescription);
        nj_admin_upsert_postmeta(
            $pdo,
            $postId,
            '_yoast_wpseo_primary_category',
            $primaryCategoryId > 0 ? (string) $primaryCategoryId : ''
        );

        nj_admin_recount_categories(
            $pdo,
            array_merge($oldTaxonomyIds, array_values($taxonomyIds))
        );

        nj_admin_recount_categories(
            $pdo,
            array_merge($oldTagTaxonomyIds, $newTagTaxonomyIds)
        );

        nj_admin_log_post_activity(
            $pdo,
            $postId,
            (int) $user['id'],
            'post_saved',
            [
                'titleChanged' => (string) $current['post_title'] !== $title,
                'slugChanged' => (string) $current['post_name'] !== $slug,
                'categoryCount' => count($categoryIds),
                'tagCount' => count($newTagTaxonomyIds),
            ]
        );

        $readBack = $pdo->prepare(<<<SQL
SELECT
    post_title AS title,
    post_name AS slug,
    post_excerpt AS excerpt,
    post_content AS content,
    post_status AS status,
    post_modified AS modified_at
FROM {$posts}
WHERE ID = :id
LIMIT 1
SQL);
        $readBack->execute(['id' => $postId]);
        $persisted = $readBack->fetch();

        if (
            !$persisted
            || (string) $persisted['title'] !== $title
            || (string) $persisted['slug'] !== $slug
            || (string) $persisted['excerpt'] !== $excerpt
            || (string) $persisted['content'] !== $content
            || (string) $persisted['status'] !== $status
        ) {
            throw new RuntimeException('post_readback_mismatch');
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
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $excerpt,
            'content' => $content,
            'status' => $status,
            'categoryIds' => $categoryIds,
            'tagNames' => $tagNames,
            'seo' => [
                'title' => $seoTitle,
                'description' => $seoDescription,
                'primaryCategoryId' => $primaryCategoryId,
            ],
            'modifiedAt' => nj_content_iso8601((string) $persisted['modified_at']),
            'publicUrl' => $slug !== '' ? '/noticia/' . rawurlencode($slug) : null,
        ],
    ];
});
