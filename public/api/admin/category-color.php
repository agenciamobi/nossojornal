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
    $color = nj_category_validate_color((string) ($body['color'] ?? ''));

    if (!is_int($categoryId) || $categoryId <= 0) {
        throw new NjApiHttpException(422, 'invalid_category_id');
    }

    if ($color === null) {
        throw new NjApiHttpException(422, 'invalid_category_color');
    }

    $pdo = nj_db();
    $terms = nj_table('terms');
    $taxonomy = nj_table('term_taxonomy');
    $termmeta = nj_table('termmeta');

    $categoryStatement = $pdo->prepare(<<<SQL
SELECT t.term_id AS id, t.name, t.slug
FROM {$terms} t
INNER JOIN {$taxonomy} tt
    ON tt.term_id = t.term_id
    AND tt.taxonomy = 'category'
WHERE t.term_id = :id
LIMIT 1
SQL);
    $categoryStatement->execute(['id' => $categoryId]);
    $category = $categoryStatement->fetch();

    if (!$category) {
        throw new NjApiHttpException(404, 'category_not_found');
    }

    try {
        $pdo->beginTransaction();

        $existingStatement = $pdo->prepare(<<<SQL
SELECT meta_id
FROM {$termmeta}
WHERE
    term_id = :term_id
    AND meta_key = :meta_key
ORDER BY meta_id DESC
LIMIT 1
FOR UPDATE
SQL);
        $existingStatement->execute([
            'term_id' => $categoryId,
            'meta_key' => NJ_CATEGORY_COLOR_META_KEY,
        ]);

        $metaId = (int) ($existingStatement->fetchColumn() ?: 0);

        if ($metaId > 0) {
            $update = $pdo->prepare(<<<SQL
UPDATE {$termmeta}
SET meta_value = :meta_value
WHERE meta_id = :meta_id
LIMIT 1
SQL);
            $update->execute([
                'meta_value' => $color,
                'meta_id' => $metaId,
            ]);
        } else {
            $insert = $pdo->prepare(<<<SQL
INSERT INTO {$termmeta} (term_id, meta_key, meta_value)
VALUES (:term_id, :meta_key, :meta_value)
SQL);
            $insert->execute([
                'term_id' => $categoryId,
                'meta_key' => NJ_CATEGORY_COLOR_META_KEY,
                'meta_value' => $color,
            ]);
            $metaId = (int) $pdo->lastInsertId();
        }

        $readBack = $pdo->prepare(<<<SQL
SELECT meta_value
FROM {$termmeta}
WHERE meta_id = :meta_id
LIMIT 1
SQL);
        $readBack->execute(['meta_id' => $metaId]);
        $persisted = nj_category_validate_color((string) ($readBack->fetchColumn() ?: ''));

        if ($persisted !== $color) {
            throw new RuntimeException('category_color_readback_mismatch');
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
            'name' => (string) $category['name'],
            'slug' => (string) $category['slug'],
            'color' => $color,
            'colorSource' => 'termmeta',
        ],
        'mutation' => [
            'metaKey' => NJ_CATEGORY_COLOR_META_KEY,
            'verifiedByReadBack' => true,
        ],
    ];
});
