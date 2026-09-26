<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

function nj_revision_decode(string $content): array
{
    $decoded = json_decode($content, true);

    return is_array($decoded) ? $decoded : [];
}

function nj_revision_payload(array $row): array
{
    $decoded = nj_revision_decode((string) $row['post_content']);
    $snapshot = is_array($decoded['snapshot'] ?? null) ? $decoded['snapshot'] : [];

    return [
        'id' => (int) $row['ID'],
        'kind' => (string) ($decoded['kind'] ?? 'save'),
        'createdAt' => nj_content_iso8601((string) $row['post_date']),
        'modifiedAt' => nj_content_iso8601((string) $row['post_modified']),
        'author' => [
            'id' => (int) $row['post_author'],
            'name' => (string) ($row['author_name'] ?? ''),
        ],
        'summary' => [
            'title' => (string) ($snapshot['title'] ?? ''),
            'status' => (string) ($snapshot['status'] ?? ''),
            'words' => str_word_count(strip_tags((string) ($snapshot['content'] ?? ''))),
        ],
        'snapshot' => $snapshot,
    ];
}

nj_admin_run(['GET', 'POST'], static function (string $method): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'edit_posts');

    $pdo = nj_db();
    $posts = nj_table('posts');
    $users = nj_table('users');

    if ($method === 'GET') {
        $postId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (!is_int($postId) || $postId <= 0) {
            throw new NjApiHttpException(422, 'invalid_post_id');
        }

        nj_admin_require_post_editor($pdo, $user, $postId);

        $statement = $pdo->prepare(<<<SQL
SELECT
    r.*,
    COALESCE(u.display_name, u.user_login, '') AS author_name
FROM {$posts} r
LEFT JOIN {$users} u ON u.ID = r.post_author
WHERE
    r.post_type = 'nj_revision'
    AND r.post_parent = :post_id
    AND r.post_status = 'private'
ORDER BY r.post_modified DESC, r.ID DESC
LIMIT 30
SQL);
        $statement->execute(['post_id' => $postId]);

        $items = array_map('nj_revision_payload', $statement->fetchAll());

        return [
            'items' => $items,
        ];
    }

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

    $post = nj_admin_require_post_editor($pdo, $user, $postId);

    if ($action === 'autosave') {
        $snapshot = [
            'title' => trim((string) ($body['title'] ?? '')),
            'slug' => trim((string) ($body['slug'] ?? '')),
            'excerpt' => (string) ($body['excerpt'] ?? ''),
            'content' => (string) ($body['content'] ?? ''),
            'status' => (string) $post['post_status'],
            'categoryIds' => array_values(array_unique(array_filter(array_map(
                static fn (mixed $value): int => (int) $value,
                is_array($body['categoryIds'] ?? null) ? $body['categoryIds'] : []
            )))),
            'seo' => [
                'title' => trim((string) ($body['seoTitle'] ?? '')),
                'description' => trim((string) ($body['seoDescription'] ?? '')),
                'primaryCategoryId' => max(0, (int) ($body['primaryCategoryId'] ?? 0)),
            ],
        ];

        if (
            (function_exists('mb_strlen') ? mb_strlen($snapshot['content'], 'UTF-8') : strlen($snapshot['content']))
            > 1500000
        ) {
            throw new NjApiHttpException(422, 'autosave_content_too_large');
        }

        $encoded = json_encode([
            'kind' => 'autosave',
            'snapshot' => $snapshot,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        try {
            $pdo->beginTransaction();

            $find = $pdo->prepare(<<<SQL
SELECT ID
FROM {$posts}
WHERE
    post_type = 'nj_revision'
    AND post_parent = :post_id
    AND post_author = :author_id
    AND post_status = 'private'
    AND post_title = 'Autosave'
ORDER BY ID DESC
LIMIT 1
FOR UPDATE
SQL);
            $find->execute([
                'post_id' => $postId,
                'author_id' => $user['id'],
            ]);
            $revisionId = (int) ($find->fetchColumn() ?: 0);

            if ($revisionId > 0) {
                $update = $pdo->prepare(<<<SQL
UPDATE {$posts}
SET
    post_content = :content,
    post_modified = NOW(),
    post_modified_gmt = UTC_TIMESTAMP()
WHERE ID = :id
LIMIT 1
SQL);
                $update->execute([
                    'content' => $encoded,
                    'id' => $revisionId,
                ]);
            } else {
                $insert = $pdo->prepare(<<<SQL
INSERT INTO {$posts} (
    post_author,
    post_date,
    post_date_gmt,
    post_content,
    post_title,
    post_excerpt,
    post_status,
    comment_status,
    ping_status,
    post_password,
    post_name,
    to_ping,
    pinged,
    post_modified,
    post_modified_gmt,
    post_content_filtered,
    post_parent,
    guid,
    menu_order,
    post_type,
    post_mime_type,
    comment_count
) VALUES (
    :author_id,
    NOW(),
    UTC_TIMESTAMP(),
    :content,
    'Autosave',
    '',
    'private',
    'closed',
    'closed',
    '',
    '',
    '',
    '',
    NOW(),
    UTC_TIMESTAMP(),
    '',
    :post_parent,
    '',
    0,
    'nj_revision',
    '',
    0
)
SQL);
                $insert->execute([
                    'author_id' => $user['id'],
                    'content' => $encoded,
                    'post_parent' => $postId,
                ]);
                $revisionId = (int) $pdo->lastInsertId();
            }

            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return [
            'autosave' => [
                'id' => $revisionId,
                'savedAt' => (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format(DATE_ATOM),
            ],
        ];
    }

    if ($action === 'restore') {
        $revisionId = filter_var(
            $body['revisionId'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!is_int($revisionId) || $revisionId <= 0) {
            throw new NjApiHttpException(422, 'invalid_revision_id');
        }

        $revisionStatement = $pdo->prepare(<<<SQL
SELECT post_content
FROM {$posts}
WHERE
    ID = :id
    AND post_type = 'nj_revision'
    AND post_parent = :post_id
    AND post_status = 'private'
LIMIT 1
SQL);
        $revisionStatement->execute([
            'id' => $revisionId,
            'post_id' => $postId,
        ]);
        $revisionContent = $revisionStatement->fetchColumn();

        if (!is_string($revisionContent) || $revisionContent === '') {
            throw new NjApiHttpException(404, 'revision_not_found');
        }

        $decoded = nj_revision_decode($revisionContent);
        $snapshot = is_array($decoded['snapshot'] ?? null) ? $decoded['snapshot'] : [];

        $title = trim((string) ($snapshot['title'] ?? ''));
        $excerpt = (string) ($snapshot['excerpt'] ?? '');
        $content = (string) ($snapshot['content'] ?? '');
        $requestedSlug = trim((string) ($snapshot['slug'] ?? ''));

        if ($title === '') {
            throw new NjApiHttpException(422, 'invalid_revision_snapshot');
        }

        $slug = nj_admin_unique_post_slug($pdo, $postId, $requestedSlug, $title);
        $categoryIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): int => (int) $value,
            is_array($snapshot['categoryIds'] ?? null) ? $snapshot['categoryIds'] : []
        ))));
        $seo = is_array($snapshot['seo'] ?? null) ? $snapshot['seo'] : [];

        try {
            $pdo->beginTransaction();

            nj_admin_create_revision($pdo, $postId, (int) $user['id'], 'before_restore');

            $update = $pdo->prepare(<<<SQL
UPDATE {$posts}
SET
    post_title = :title,
    post_name = :slug,
    post_excerpt = :excerpt,
    post_content = :content,
    post_modified = NOW(),
    post_modified_gmt = UTC_TIMESTAMP()
WHERE ID = :id
LIMIT 1
SQL);
            $update->execute([
                'title' => $title,
                'slug' => $slug,
                'excerpt' => $excerpt,
                'content' => $content,
                'id' => $postId,
            ]);

            $relationships = nj_table('term_relationships');
            $taxonomy = nj_table('term_taxonomy');

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

            $deleteRelations = $pdo->prepare(<<<SQL
DELETE tr
FROM {$relationships} tr
INNER JOIN {$taxonomy} tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
    AND tt.taxonomy = 'category'
WHERE tr.object_id = :post_id
SQL);
            $deleteRelations->execute(['post_id' => $postId]);

            $newTaxonomyIds = [];
            if ($categoryIds !== []) {
                $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
                $categoryStatement = $pdo->prepare(<<<SQL
SELECT term_id, term_taxonomy_id
FROM {$taxonomy}
WHERE taxonomy = 'category' AND term_id IN ({$placeholders})
SQL);
                $categoryStatement->execute($categoryIds);

                $insertRelation = $pdo->prepare(<<<SQL
INSERT INTO {$relationships} (object_id, term_taxonomy_id, term_order)
VALUES (:post_id, :taxonomy_id, 0)
SQL);

                foreach ($categoryStatement->fetchAll() as $category) {
                    $taxonomyId = (int) $category['term_taxonomy_id'];
                    $newTaxonomyIds[] = $taxonomyId;
                    $insertRelation->execute([
                        'post_id' => $postId,
                        'taxonomy_id' => $taxonomyId,
                    ]);
                }
            }

            nj_admin_recount_categories($pdo, array_merge($oldTaxonomyIds, $newTaxonomyIds));

            nj_admin_upsert_postmeta($pdo, $postId, '_yoast_wpseo_title', trim((string) ($seo['title'] ?? '')));
            nj_admin_upsert_postmeta($pdo, $postId, '_yoast_wpseo_metadesc', trim((string) ($seo['description'] ?? '')));
            nj_admin_upsert_postmeta(
                $pdo,
                $postId,
                '_yoast_wpseo_primary_category',
                max(0, (int) ($seo['primaryCategoryId'] ?? 0)) > 0
                    ? (string) max(0, (int) ($seo['primaryCategoryId'] ?? 0))
                    : ''
            );

            nj_admin_log_post_activity(
                $pdo,
                $postId,
                (int) $user['id'],
                'revision_restored',
                ['revisionId' => $revisionId]
            );

            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return [
            'snapshot' => nj_admin_post_snapshot($pdo, $postId),
        ];
    }

    throw new NjApiHttpException(422, 'invalid_revision_action');
});
