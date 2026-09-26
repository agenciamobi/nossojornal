<?php
declare(strict_types=1);

require __DIR__ . '/_admin.php';
require_once __DIR__ . '/../v1/_category_theme.php';

nj_admin_run(['POST'], static function (): array {
    $user = nj_admin_current_user(true);
    nj_admin_require_capability($user, 'manage_categories');
    nj_admin_require_csrf();

    $body = nj_admin_request_body();

    $categoryId = filter_var(
        $body['categoryId'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $name = trim((string) ($body['name'] ?? ''));
    $slug = nj_admin_slugify((string) ($body['slug'] ?? ''));
    $description = trim((string) ($body['description'] ?? ''));
    $parentRaw = $body['parentId'] ?? null;
    $parentId = $parentRaw === null || $parentRaw === '' ? null : (int) $parentRaw;
    $color = nj_category_validate_color((string) ($body['color'] ?? ''));

    if (!is_int($categoryId) || $categoryId <= 0) {
        throw new NjApiHttpException(422, 'invalid_category_id');
    }

    if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 200) {
        throw new NjApiHttpException(422, 'invalid_category_name');
    }

    if ($slug === '') {
        $slug = nj_admin_slugify($name);
    }

    if ($slug === '') {
        throw new NjApiHttpException(422, 'invalid_category_slug');
    }

    if ((function_exists('mb_strlen') ? mb_strlen($description, 'UTF-8') : strlen($description)) > 20000) {
        throw new NjApiHttpException(422, 'category_description_too_large');
    }

    if ($color === null) {
        throw new NjApiHttpException(422, 'invalid_category_color');
    }

    $pdo = nj_db();
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $termmeta = nj_table('termmeta');

    $currentStatement = $pdo->prepare(<<<SQL
SELECT
    t.term_id AS id,
    tt.term_taxonomy_id AS taxonomy_id
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
    AND tt.taxonomy = 'category'
WHERE t.term_id = :id
LIMIT 1
SQL);
    $currentStatement->execute(['id' => $categoryId]);
    $current = $currentStatement->fetch();

    if (!$current) {
        throw new NjApiHttpException(404, 'category_not_found');
    }

    $duplicateStatement = $pdo->prepare(<<<SQL
SELECT term_id
FROM {$terms}
WHERE
    slug = :slug
    AND term_id <> :id
LIMIT 1
SQL);
    $duplicateStatement->execute([
        'slug' => $slug,
        'id' => $categoryId,
    ]);

    if ($duplicateStatement->fetchColumn()) {
        throw new NjApiHttpException(409, 'category_slug_exists');
    }

    nj_admin_validate_category_parent($pdo, $categoryId, $parentId);

    try {
        $pdo->beginTransaction();

        $updateTerm = $pdo->prepare(<<<SQL
UPDATE {$terms}
SET
    name = :name,
    slug = :slug
WHERE term_id = :id
LIMIT 1
SQL);
        $updateTerm->execute([
            'name' => $name,
            'slug' => $slug,
            'id' => $categoryId,
        ]);

        $updateTaxonomy = $pdo->prepare(<<<SQL
UPDATE {$taxonomy}
SET
    description = :description,
    parent = :parent_id
WHERE
    term_taxonomy_id = :taxonomy_id
    AND taxonomy = 'category'
LIMIT 1
SQL);
        $updateTaxonomy->execute([
            'description' => $description,
            'parent_id' => $parentId ?? 0,
            'taxonomy_id' => (int) $current['taxonomy_id'],
        ]);

        $findColor = $pdo->prepare(<<<SQL
SELECT meta_id
FROM {$termmeta}
WHERE
    term_id = :term_id
    AND meta_key = :meta_key
ORDER BY meta_id DESC
LIMIT 1
FOR UPDATE
SQL);
        $findColor->execute([
            'term_id' => $categoryId,
            'meta_key' => NJ_CATEGORY_COLOR_META_KEY,
        ]);
        $metaId = (int) ($findColor->fetchColumn() ?: 0);

        if ($metaId > 0) {
            $updateColor = $pdo->prepare(<<<SQL
UPDATE {$termmeta}
SET meta_value = :color
WHERE meta_id = :meta_id
LIMIT 1
SQL);
            $updateColor->execute([
                'color' => $color,
                'meta_id' => $metaId,
            ]);
        } else {
            $insertColor = $pdo->prepare(<<<SQL
INSERT INTO {$termmeta} (term_id, meta_key, meta_value)
VALUES (:term_id, :meta_key, :color)
SQL);
            $insertColor->execute([
                'term_id' => $categoryId,
                'meta_key' => NJ_CATEGORY_COLOR_META_KEY,
                'color' => $color,
            ]);
        }

        $readBack = $pdo->prepare(<<<SQL
SELECT
    t.term_id AS id,
    t.name,
    t.slug,
    tt.description,
    tt.parent AS parent_id,
    (
        SELECT tm.meta_value
        FROM {$termmeta} tm
        WHERE
            tm.term_id = t.term_id
            AND tm.meta_key = :meta_key
        ORDER BY tm.meta_id DESC
        LIMIT 1
    ) AS color
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
    AND tt.taxonomy = 'category'
WHERE t.term_id = :id
LIMIT 1
SQL);
        $readBack->execute([
            'meta_key' => NJ_CATEGORY_COLOR_META_KEY,
            'id' => $categoryId,
        ]);
        $persisted = $readBack->fetch();

        if (
            !$persisted
            || (string) $persisted['name'] !== $name
            || (string) $persisted['slug'] !== $slug
            || (string) $persisted['description'] !== $description
            || (int) $persisted['parent_id'] !== ($parentId ?? 0)
            || nj_category_validate_color((string) $persisted['color']) !== $color
        ) {
            throw new RuntimeException('category_readback_mismatch');
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
        'category' => [
            'id' => $categoryId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'parentId' => $parentId,
            'color' => $color,
            'colorSource' => 'termmeta',
            'publicUrl' => '/categoria/' . rawurlencode($slug),
        ],
    ];
});
