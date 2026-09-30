<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';

nj_admin_run(['POST'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'manage_categories');
    nj_admin_require_csrf();

    $body = nj_admin_request_body();
    $tagId = filter_var(
        $body['tagId'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $name = trim((string) ($body['name'] ?? ''));
    $slug = nj_admin_slugify((string) ($body['slug'] ?? ''));
    $description = trim((string) ($body['description'] ?? ''));

    if (!is_int($tagId) || $tagId <= 0) {
        throw new NjApiHttpException(422, 'invalid_tag_id');
    }

    if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 200) {
        throw new NjApiHttpException(422, 'invalid_tag_name');
    }

    if ($slug === '') {
        $slug = nj_admin_slugify($name);
    }

    if ($slug === '') {
        throw new NjApiHttpException(422, 'invalid_tag_slug');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description)) > 20000) {
        throw new NjApiHttpException(422, 'tag_description_too_large');
    }

    $pdo = nj_db();
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');

    $current = $pdo->prepare(<<<SQL
SELECT tt.term_taxonomy_id
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
    AND tt.taxonomy = 'post_tag'
WHERE t.term_id = :id
LIMIT 1
SQL);
    $current->execute(['id' => $tagId]);
    $taxonomyId = (int) ($current->fetchColumn() ?: 0);

    if ($taxonomyId <= 0) {
        throw new NjApiHttpException(404, 'tag_not_found');
    }

    $duplicate = $pdo->prepare(
        "SELECT term_id FROM {$terms} WHERE slug = :slug AND term_id <> :id LIMIT 1"
    );
    $duplicate->execute([
        'slug' => $slug,
        'id' => $tagId,
    ]);

    if ($duplicate->fetchColumn()) {
        throw new NjApiHttpException(409, 'tag_slug_exists');
    }

    try {
        $pdo->beginTransaction();

        $updateTerm = $pdo->prepare(
            "UPDATE {$terms} SET name = :name, slug = :slug WHERE term_id = :id LIMIT 1"
        );
        $updateTerm->execute([
            'name' => $name,
            'slug' => $slug,
            'id' => $tagId,
        ]);

        $updateTaxonomy = $pdo->prepare(<<<SQL
UPDATE {$taxonomy}
SET description = :description
WHERE term_taxonomy_id = :taxonomy_id
  AND taxonomy = 'post_tag'
LIMIT 1
SQL);
        $updateTaxonomy->execute([
            'description' => $description,
            'taxonomy_id' => $taxonomyId,
        ]);

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
        'tag' => [
            'id' => $tagId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
        ],
    ];
});
